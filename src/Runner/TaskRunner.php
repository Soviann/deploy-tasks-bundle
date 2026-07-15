<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Runner;

use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Soviann\DeployTasksBundle\Attribute\AsDeployTask;
use Soviann\DeployTasksBundle\DeployTaskInterface;
use Soviann\DeployTasksBundle\Event\AfterTaskEvent;
use Soviann\DeployTasksBundle\Event\BeforeTaskEvent;
use Soviann\DeployTasksBundle\Event\TaskFailedEvent;
use Soviann\DeployTasksBundle\Exception\AllOrNothingFailureException;
use Soviann\DeployTasksBundle\Exception\EventListenerException;
use Soviann\DeployTasksBundle\Exception\IncompatibleStorageException;
use Soviann\DeployTasksBundle\Exception\StorageException;
use Soviann\DeployTasksBundle\Exception\TaskEnvironmentMismatchException;
use Soviann\DeployTasksBundle\Exception\TaskGroupMismatchException;
use Soviann\DeployTasksBundle\Exception\TaskNotFoundException;
use Soviann\DeployTasksBundle\Exception\TaskReturnedFailureException;
use Soviann\DeployTasksBundle\Helper\ConsoleSanitizer;
use Soviann\DeployTasksBundle\Helper\SystemClock;
use Soviann\DeployTasksBundle\Identifier\TaskDescriptionResolver;
use Soviann\DeployTasksBundle\Identifier\TaskIdResolver;
use Soviann\DeployTasksBundle\Sorting\TaskSorterInterface;
use Soviann\DeployTasksBundle\Storage\TaskExecution;
use Soviann\DeployTasksBundle\Storage\TaskStatus;
use Soviann\DeployTasksBundle\Storage\TaskStorageInterface;
use Soviann\DeployTasksBundle\Storage\TransactionalStorageInterface;
use Soviann\DeployTasksBundle\TaskResult;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Lock\Exception\LockConflictedException;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\LockInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Executes pending deploy tasks in order, tracking results in storage.
 *
 * @internal the console commands are the bundle's public surface; this class
 *           stays autowirable for programmatic runs, but its contract (method
 *           signatures, thrown exception types) may change in any release
 */
final readonly class TaskRunner
{
    private LoggerInterface $logger;

    /**
     * @throws IncompatibleStorageException When $transactionMode requires transactions the storage cannot provide
     */
    public function __construct(
        private TaskRegistry $registry,
        private TaskStorageInterface $storage,
        private TaskSorterInterface $sorter,
        private TaskIdResolver $idResolver,
        private TaskDescriptionResolver $descriptionResolver,
        private int $defaultTimeout,
        private TransactionMode $transactionMode,
        private int $lockTtl,
        /** True when lock.enabled is false in config — a deliberate opt-out, not a missing symfony/lock. */
        private bool $lockDisabledByConfig = false,
        private ?EventDispatcherInterface $dispatcher = null,
        private ?LockFactory $lockFactory = null,
        private ?string $environment = null,
        /** Override for deterministic time in tests. */
        private ClockInterface $clock = new SystemClock(),
        ?LoggerInterface $logger = null,
    ) {
        // Runtime twin of the DI layer's compile-time check: a mode other than "none"
        // promises rollback, and a backend without transactions cannot keep it. The
        // compiler pass covers every storage whose class it can resolve; it reads null
        // for the rest (synthetic services, child definitions) and skips rather than
        // guess. Refusing here — on the real instance, before any task runs — closes
        // that gap without the false positives a class-name guess would produce.
        if (TransactionMode::None !== $transactionMode && !$storage instanceof TransactionalStorageInterface) {
            throw IncompatibleStorageException::modeRequiresTransactional($transactionMode, $storage::class);
        }

        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Runs all pending tasks in resolved order.
     *
     * Dry-run, rerun-all, and group targeting semantics are documented on the
     * {@see RunOptions} properties.
     *
     * @throws \ReflectionException   When the #[AsDeployTask] attribute lookup fails for a registered task
     * @throws StorageException       When the storage backend fails while reading pending slots or persisting
     *                                outcomes
     * @throws EventListenerException When a Before/After listener throws — propagates even without
     *                                `all_or_nothing`
     * @throws \Throwable             When the mode is `all_or_nothing` and a task throws — the exception escapes
     *                                after the transaction is rolled back
     */
    public function runAll(OutputInterface $output, RunOptions $options = new RunOptions()): RunResult
    {
        $result = $this->withLock(
            $output,
            function (?LockInterface $lock) use ($output, $options): RunResult {
                $this->logger->info('Deploy tasks run starting', [
                    'environment' => $this->environment,
                    'dry_run' => $options->dryRun,
                    'rerun_all' => $options->rerunAll,
                    'groups' => $options->groups,
                ]);

                $tasks = \array_values($this->registry->all($this->environment, $options->groups));
                $sorted = $this->sorter->sort($tasks);

                if ($options->dryRun) {
                    return $this->dryRun($sorted, $output, $options->groups, $options->rerunAll);
                }

                return $this->withAllOrNothingTransaction(
                    fn (): RunResult => $this->executeAll($sorted, $output, $options->rerunAll, $options->groups, $lock),
                );
            });

        $final = $result ?? new RunResult(ran: 0, skipped: 0, failed: 0, locked: true);

        $this->logger->info('Deploy tasks run finished', [
            'ran' => $final->ran,
            'skipped' => $final->skipped,
            'failed' => $final->failed,
            'locked' => $final->locked,
        ]);

        return $final;
    }

    /**
     * Runs a single task by ID, recording one storage row per target slot.
     *
     * Dry-run, rerun-all, and group targeting semantics are documented on the
     * {@see RunOptions} properties.
     *
     * When the mode is `all_or_nothing`, execution and persistence run inside a
     * single transaction, mirroring runAll(): a failure rolls back every side-effect
     * before the exception escapes.
     *
     * Slot resolution (`$groups` being `$options->groups`) is owned by {@see SlotResolver}.
     *
     * Returns null when the run lock is already held by another process,
     * matching {@see withLock()}'s own convention — callers map it to their
     * locked exit path.
     *
     * @throws TaskEnvironmentMismatchException When the task declares an env constraint that does not match the
     *                                          runner's environment
     * @throws TaskGroupMismatchException
     * @throws TaskNotFoundException            When no task is registered with the given id
     * @throws \ReflectionException             When the #[AsDeployTask] attribute lookup fails
     * @throws StorageException                 When the storage backend fails while reading pending slots or
     *                                          persisting outcomes
     * @throws EventListenerException           When a Before/After listener throws — propagates even without
     *                                          `all_or_nothing`
     * @throws AllOrNothingFailureException     When the mode is `all_or_nothing` and the task throws — wraps the
     *                                          cause after the transaction is rolled back
     * @throws \Throwable                       When the mode is not `all_or_nothing` and the task throws — the raw
     *                                          exception escapes
     */
    public function runOne(string $taskId, OutputInterface $output, RunOptions $options = new RunOptions()): ?TaskResult
    {
        return $this->withLock(
            $output,
            function (?LockInterface $lock) use ($taskId, $output, $options): TaskResult {
                $task = $this->registry->get($taskId);

                $envs = AsDeployTask::envsOf($task);

                if (null !== $envs && null !== $this->environment && !\in_array($this->environment, $envs, true)) {
                    throw new TaskEnvironmentMismatchException($taskId, \implode('|', $envs), $this->environment);
                }

                $slots = SlotResolver::resolve($taskId, $task, $options->groups);
                $pendingSlots = $this->filterPendingSlots($taskId, $slots, $options->rerunAll);

                if ([] === $pendingSlots) {
                    $output->writeln(\sprintf('<comment>Task "%s" has already been executed.</comment>', $taskId));
                    $this->logger->info('Deploy task skipped (already executed)', ['task_id' => $taskId]);

                    return TaskResult::SKIPPED;
                }

                if ($options->dryRun) {
                    foreach ($pendingSlots as $slot) {
                        $this->writeWouldRunLine($output, $taskId, $slot, $task);
                    }

                    return TaskResult::SUCCESS;
                }

                return $this->withAllOrNothingTransaction(function () use ($task, $output, $taskId, $pendingSlots): TaskResult {
                    try {
                        return $this->executeTask($task, $output, 1, 1, $taskId, $pendingSlots)->result;
                    } catch (\Throwable $taskError) {
                        if (TransactionMode::AllOrNothing !== $this->transactionMode) {
                            throw $taskError;
                        }

                        // Mirror executeAll(): surface the abort as AllOrNothingFailureException
                        // so the run command renders the rolled-back summary instead of letting
                        // the raw task exception escape to the console.
                        throw new AllOrNothingFailureException(new RunResult(ran: 0, skipped: 0, failed: 1), $taskId, $taskError);
                    }
                });
            });
    }

    /**
     * Acquires the shared run lock, executes the operation, and releases on the way out.
     *
     * Returns null when the lock is already held by another process — runAll() maps that
     * to RunResult::$locked, runOne() forwards the null as its own locked sentinel.
     *
     * @template T
     *
     * @param \Closure(?LockInterface): T $operation
     *
     * @return T|null
     */
    private function withLock(OutputInterface $output, \Closure $operation): mixed
    {
        if (null === $this->lockFactory && !$this->lockDisabledByConfig) {
            if ($output->isVerbose()) {
                $output->writeln('<comment>No lock factory configured — concurrent execution is not protected.</comment>');
            }
            $this->logger->warning('Deploy tasks runner has no lock factory — concurrent execution is not protected');
        }

        $lock = $this->lockFactory?->createLock('soviann_deploy_tasks_run', $this->lockTtl);

        if (null !== $lock && !$lock->acquire()) {
            $this->logger->warning('Deploy tasks run skipped: another process is already running');

            return null;
        }

        try {
            return $operation($lock);
        } finally {
            $lock?->release();
        }
    }

    /**
     * Lists pending (task, slot) pairs without executing them.
     *
     * When `$rerunAll` is true, every slot is treated as pending, mirroring what a
     * rerun-all run would re-execute.
     *
     * @param list<DeployTaskInterface> $tasks
     * @param list<string>              $requestedGroups
     *
     * @throws \ReflectionException
     */
    private function dryRun(array $tasks, OutputInterface $output, array $requestedGroups, bool $rerunAll): RunResult
    {
        $pending = 0;
        $skipped = 0;
        $executionIndex = $rerunAll ? [] : $this->indexExecutions();

        foreach ($tasks as $task) {
            $taskId = $this->idResolver->resolve($task);
            $slots = self::computeSlots($task, $requestedGroups);
            $pendingSlots = $this->filterPendingSlots($taskId, $slots, $rerunAll, $executionIndex);

            $skipped += \count($slots) - \count($pendingSlots);
            $pending += \count($pendingSlots);

            foreach ($pendingSlots as $slot) {
                $this->writeWouldRunLine($output, $taskId, $slot, $task);
            }
        }

        return new RunResult(ran: $pending, skipped: $skipped, failed: 0, dryRun: true);
    }

    /**
     * Runs the operation inside a single run-wide transaction when the mode is
     * `all_or_nothing`; runs it directly otherwise. The storage is known to support
     * transactions in that mode — the constructor rejects any other pairing.
     *
     * @template T
     *
     * @param \Closure(): T $operation
     *
     * @return T
     *
     * @throws \Throwable Rethrown after logging when the transaction rolled back
     */
    private function withAllOrNothingTransaction(\Closure $operation): mixed
    {
        if (TransactionMode::AllOrNothing !== $this->transactionMode) {
            return $operation();
        }

        // Guaranteed by the constructor guard, as in wrapInTransaction().
        \assert($this->storage instanceof TransactionalStorageInterface);

        try {
            return $this->storage->transactional($operation);
        } catch (\Throwable $e) {
            $this->logger->error(
                'Deploy tasks run failed — transaction rolled back.',
                $this->buildExceptionLogContext($e),
            );

            throw $e;
        }
    }

    private function writeWouldRunLine(
        OutputInterface $output,
        string $taskId,
        ?string $slot,
        DeployTaskInterface $task,
    ): void {
        $label = null === $slot ? $taskId : $taskId.'@'.$slot;
        $output->writeln(\sprintf('  [would run] %s - %s', $label, $this->descriptionResolver->resolve($task)));
    }

    /**
     * Executes all ordered tasks, recording one storage row per matching slot.
     *
     * Refreshes the lock after each task (including the last — harmless but avoids a
     * special-case branch) so a deploy longer than the configured TTL does not let the
     * lease expire and allow a second runner to acquire it mid-deploy.
     *
     * When the refresh itself fails because the lease already expired (a task outran
     * the TTL), the run stops and returns the locked sentinel: a second runner may
     * hold the lock by now, so continuing would defeat the concurrency protection.
     *
     * @param list<DeployTaskInterface> $tasks
     * @param list<string>              $requestedGroups
     *
     * @throws \ReflectionException
     * @throws \Throwable           When the mode is `all_or_nothing` and a task throws
     */
    private function executeAll(
        array $tasks,
        OutputInterface $output,
        bool $rerunAll,
        array $requestedGroups,
        ?LockInterface $lock = null,
    ): RunResult {
        $ran = 0;
        $skipped = 0;
        $failed = 0;

        // Pre-compute which tasks will actually be executed, so progress counters are accurate.
        /** @var list<array{task: DeployTaskInterface, taskId: string, pendingSlots: list<?string>}> $executable */
        $executable = [];
        $executionIndex = $rerunAll ? [] : $this->indexExecutions();

        foreach ($tasks as $task) {
            $taskId = $this->idResolver->resolve($task);
            $slots = self::computeSlots($task, $requestedGroups);

            if ([] === $slots) {
                continue;
            }

            $pendingSlots = $this->filterPendingSlots($taskId, $slots, $rerunAll, $executionIndex);
            $skipped += \count($slots) - \count($pendingSlots);

            if ([] === $pendingSlots) {
                continue;
            }

            $executable[] = ['task' => $task, 'taskId' => $taskId, 'pendingSlots' => $pendingSlots];
        }

        $total = \count($executable);
        $current = 0;

        foreach ($executable as $item) {
            ++$current;

            try {
                $outcome = $this->executeTask(
                    $item['task'],
                    $output,
                    $current,
                    $total,
                    $item['taskId'],
                    $item['pendingSlots'],
                );
            } catch (\Throwable $taskError) {
                if (TransactionMode::AllOrNothing === $this->transactionMode) {
                    $partial = new RunResult(
                        ran: $ran,
                        skipped: $skipped,
                        failed: $failed + 1,
                        locked: false,
                    );

                    throw new AllOrNothingFailureException($partial, $item['taskId'], $taskError);
                }

                throw $taskError;
            }

            $count = \count($item['pendingSlots']);

            if (TaskResult::FAILURE === $outcome->result) {
                $failed += $count;
            } elseif (TaskResult::SKIPPED === $outcome->result) {
                $skipped += $count;
            } else {
                $ran += $count;
            }

            try {
                $lock?->refresh();
            } catch (LockConflictedException $e) {
                $this->logger->warning(
                    'Deploy tasks run stopped: the run lock could not be refreshed — its lease expired and another process may hold it now',
                    ['task_id' => $item['taskId'], 'exception' => $e],
                );

                return new RunResult(ran: $ran, skipped: $skipped, failed: $failed, locked: true);
            }
        }

        return new RunResult(ran: $ran, skipped: $skipped, failed: $failed);
    }

    /**
     * Executes a single task with event dispatching, timeout check, and transactional wrapping.
     *
     * Emits a `[current/total] FQCN` progress line before execution and a
     * `→ status (ms)` completion line after.
     *
     * Success-path persistence is owned by wrapInTransaction(): on a transactional
     * backend the execution record commits inside the same per-task transaction as
     * run(), so a failure between them rolls the task's work back instead of leaving
     * it applied but unrecorded; non-transactional backends and all_or_nothing runs
     * keep the split (run, then persist). The failure path writes through plain
     * persistOutcome(). The execution record is always written before After/Failed
     * events are dispatched.
     *
     * @param list<?string> $pendingSlots Slots to persist the execution outcome into
     *
     * @throws \ReflectionException
     * @throws \Throwable           When the mode is `all_or_nothing` and a task throws
     * @throws \Throwable           When storage throws while persisting the outcome
     */
    private function executeTask(
        DeployTaskInterface $task,
        OutputInterface $output,
        int $current,
        int $total,
        string $taskId,
        array $pendingSlots,
    ): TaskOutcome {
        $attribute = AsDeployTask::of($task);
        $timeout = null !== $attribute && null !== $attribute->timeout ? $attribute->timeout : $this->defaultTimeout;

        if (!$output->isQuiet()) {
            $output->writeln(\sprintf(' [%d/%d] %s', $current, $total, $task::class));
        }

        $this->logger->info('Deploy task starting', ['task_id' => $taskId]);

        // Dispatched before the main try block: a Before-listener failure must propagate
        // directly without entering the task-failure handling below.
        $this->dispatchGuarded(new BeforeTaskEvent($taskId, $task), $taskId);

        $start = \microtime(true);
        $taskRanSuccessfully = false;

        // Runs the task and builds its success outcome. A returned TaskResult::FAILURE
        // is converted into a TaskReturnedFailureException, so it follows the same path
        // as a thrown failure inside any wrapping transaction: rollback, Failed record,
        // TaskFailedEvent, all_or_nothing abort.
        $run = function () use ($task, $output, $taskId, $timeout, $start, &$taskRanSuccessfully): TaskOutcome {
            $result = $task->run($output);

            if (TaskResult::FAILURE === $result) {
                throw TaskReturnedFailureException::create($taskId);
            }

            $taskRanSuccessfully = true;

            return $this->buildSuccessOutcome($taskId, $result, \microtime(true) - $start, $timeout, $output);
        };

        try {
            $outcome = $this->wrapInTransaction($run, $attribute, $taskId, $pendingSlots);

            $this->writeCompletionLine($output, $outcome);

            // Persisted (inside wrapInTransaction) before this dispatch so a throwing
            // listener cannot lose the record.
            $this->dispatchGuarded(new AfterTaskEvent($taskId, $task, $outcome->result, $outcome->durationSeconds), $taskId);

            return $outcome;
        } catch (\Throwable $e) {
            $duration = \microtime(true) - $start;

            // Re-raise when the task itself ran to completion — a failure record would be
            // wrong. This covers a throwing AfterTaskEvent listener (record already
            // persisted) and a storage failure while persisting the success outcome
            // (nothing recorded; on a transactional backend the task's side effects
            // rolled back with it, so the task is safe to re-run).
            if ($taskRanSuccessfully) {
                throw $e;
            }

            $outcome = $this->buildFailureOutcome($taskId, $e, $duration, $output);
            $this->writeCompletionLine($output, $outcome);

            $this->persistOutcome($taskId, $outcome, $pendingSlots);

            $this->dispatchGuarded(new TaskFailedEvent($taskId, $task, $e, $duration), $taskId);

            if (TransactionMode::AllOrNothing === $this->transactionMode) {
                // Propagate so the outer transaction rolls everything back
                throw $e;
            }

            return $outcome;
        }
    }

    /**
     * Emits the `→ status (ms)` completion line shared by the success and failure paths.
     */
    private function writeCompletionLine(OutputInterface $output, TaskOutcome $outcome): void
    {
        if ($output->isQuiet()) {
            return;
        }

        $output->writeln(\sprintf(
            '   → %s (%dms)',
            $outcome->status()->value,
            (int) \round($outcome->durationSeconds * 1000),
        ));
    }

    private function buildSuccessOutcome(
        string $taskId,
        TaskResult $result,
        float $duration,
        int $timeout,
        OutputInterface $output,
    ): TaskOutcome {
        if ($timeout > 0 && $duration > $timeout) {
            $output->writeln(\sprintf(
                '<comment>Task "%s" exceeded timeout (%ds elapsed, %ds limit).</comment>',
                $taskId,
                (int) $duration,
                $timeout,
            ));
            $this->logger->warning('Deploy task exceeded timeout', [
                'task_id' => $taskId,
                'duration_s' => $duration,
                'timeout_s' => $timeout,
            ]);
        }

        $this->logger->info('Deploy task executed', [
            'task_id' => $taskId,
            'result' => $result->name,
            'duration_ms' => (int) \round($duration * 1000),
        ]);

        return new TaskOutcome(
            result: $result,
            executedAt: $this->clock->now(),
            durationSeconds: $duration,
        );
    }

    private function buildFailureOutcome(
        string $taskId,
        \Throwable $e,
        float $duration,
        OutputInterface $output,
    ): TaskOutcome {
        // The message is untrusted (task code / bubbled-up runtime data) and this
        // writeln() interprets formatter tags: escape as well as control-strip.
        // $taskId is safe raw — registered ids match AsDeployTask::TASK_ID_PATTERN.
        $output->writeln(\sprintf(
            '<error>Task "%s" failed: %s</error>',
            $taskId,
            ConsoleSanitizer::sanitizeForFormatter($e->getMessage()),
        ));
        $this->logger->error('Deploy task failed', [
            'task_id' => $taskId,
            'duration_ms' => (int) \round($duration * 1000),
            ...$this->buildExceptionLogContext($e),
        ]);

        return new TaskOutcome(
            result: TaskResult::FAILURE,
            executedAt: $this->clock->now(),
            durationSeconds: $duration,
            error: $e->getMessage(),
        );
    }

    /**
     * Dispatches a lifecycle event, wrapping any listener failure so the caller
     * can distinguish listener bugs from task failures.
     *
     * @throws EventListenerException When a listener throws
     */
    private function dispatchGuarded(object $event, string $taskId): void
    {
        try {
            $this->dispatcher?->dispatch($event);
        } catch (\Throwable $listenerError) {
            $this->logger->error('Deploy task listener failed', [
                'event' => $event::class,
                'task' => $taskId,
                'exception' => $listenerError,
            ]);

            throw new EventListenerException(\sprintf('Listener for %s failed (task "%s").', $event::class, $taskId), 0, $listenerError);
        }
    }

    /**
     * Builds the `exception`-bearing part of a failure log context, scrubbing the
     * full throwable when a Doctrine DBAL exception sits anywhere in the chain.
     *
     * Monolog's default normaliser serialises `$e->getPrevious()->getTrace()`
     * alongside the outer throwable, and DBAL wraps the raw DSN into its driver-
     * exception traces on connection errors (`Connection to postgres://user:pass@host
     * failed: …`). Forwarding that object would export credentials into every log
     * handler. When a DBAL exception is detected, we drop the object entirely and
     * substitute a string digest: class, message, and the previous message only.
     *
     * @return array<string, scalar|\Throwable|null>
     */
    private function buildExceptionLogContext(\Throwable $e): array
    {
        if ($this->hasDbalExceptionInChain($e)) {
            return [
                'exception_class' => $e::class,
                'exception_message' => $e->getMessage(),
                'previous_message' => $e->getPrevious()?->getMessage(),
            ];
        }

        return ['exception' => $e];
    }

    private function hasDbalExceptionInChain(\Throwable $e): bool
    {
        for ($current = $e; null !== $current; $current = $current->getPrevious()) {
            if ($current instanceof \Doctrine\DBAL\Exception) {
                return true;
            }
        }

        return false;
    }

    /**
     * Runs the task and persists its success outcome, folding both into a single
     * per-task transaction when the mode is `per_task`.
     *
     * The fold closes the split-transaction window: with run() side effects and the
     * execution record committing together, a crash or storage failure between them
     * can never leave applied side effects without a record (which would re-run the
     * task on the next deploy) — the failure rolls the task's work back instead.
     *
     * `per_task` is the only mode that consults the `#[AsDeployTask(transactional:)]`
     * override: `false` opts the task out of the wrap. Conflicting declarations
     * (`false` under `all_or_nothing`, `true` under `none`) are rejected at compile
     * time by the DI layer.
     *
     * The split (run bare, then persist) remains for tasks that opted out of
     * wrapping, mode `none` (whose backend may have no transactions at all), and
     * all_or_nothing runs, where the run-wide transaction already provides the same
     * guarantee.
     *
     * @param \Closure(): TaskOutcome $run          Runs the task and builds its success outcome
     * @param list<?string>           $pendingSlots
     *
     * @throws \Throwable When the task, a storage save, or the transaction fails
     */
    private function wrapInTransaction(
        \Closure $run,
        ?AsDeployTask $attribute,
        string $taskId,
        array $pendingSlots,
    ): TaskOutcome {
        if (TransactionMode::PerTask === $this->transactionMode) {
            $shouldWrap = $attribute->transactional ?? true;

            if ($shouldWrap) {
                // The mode alone proves the capability: the constructor refuses per_task
                // on a storage that cannot roll back.
                \assert($this->storage instanceof TransactionalStorageInterface);

                return $this->storage->transactional(function () use ($run, $taskId, $pendingSlots): TaskOutcome {
                    $outcome = $run();
                    $this->persistOutcome($taskId, $outcome, $pendingSlots);

                    return $outcome;
                });
            }
        }

        $outcome = $run();
        $this->persistOutcomeTransactional($taskId, $outcome, $pendingSlots);

        return $outcome;
    }

    /**
     * Persists the task outcome for each pending slot, wrapping the saves in a single
     * per-save transaction when the storage backend supports it.
     *
     * Used by the split success path only — mode `none` (whose backend may have no
     * transactions at all), tasks that opted out of wrapping, and all_or_nothing runs
     * (where the record commits or rolls back with the run-wide transaction anyway).
     * The instanceof check therefore stays load-bearing here. Transactionally-wrapped tasks
     * persist inside their own run() transaction instead ({@see wrapInTransaction}).
     * If storage throws during any save the exception propagates to the caller (which
     * re-raises it), and the per-save transaction keeps a partial-write within a
     * multi-slot task from surviving on transactional backends.
     *
     * @param list<?string> $pendingSlots
     */
    private function persistOutcomeTransactional(string $taskId, TaskOutcome $outcome, array $pendingSlots): void
    {
        if ($this->storage instanceof TransactionalStorageInterface) {
            $this->storage->transactional(function () use ($taskId, $outcome, $pendingSlots): void {
                $this->persistOutcome($taskId, $outcome, $pendingSlots);
            });

            return;
        }

        $this->persistOutcome($taskId, $outcome, $pendingSlots);
    }

    /**
     * @param list<?string>                     $slots
     * @param array<string, TaskExecution>|null $executionIndex one-shot all() index; null = per-slot get() (single-task paths)
     *
     * @return list<?string>
     */
    private function filterPendingSlots(string $taskId, array $slots, bool $rerunAll, ?array $executionIndex = null): array
    {
        if ($rerunAll) {
            return $slots;
        }

        $pending = [];

        foreach ($slots as $slot) {
            $execution = null !== $executionIndex
                ? ($executionIndex[TaskExecution::slotKey($taskId, $slot)] ?? null)
                : $this->storage->get($taskId, $slot);

            if ($this->isPendingSlot($execution)) {
                $pending[] = $slot;
            }
        }

        return $pending;
    }

    /**
     * One-shot index of every stored execution, keyed by slot — turns the
     * per-(task, slot) storage->get() of a full run into a single all() read
     * (one SELECT on DBAL instead of one round-trip per slot).
     *
     * @return array<string, TaskExecution>
     */
    private function indexExecutions(): array
    {
        $index = [];

        foreach ($this->storage->all() as $execution) {
            $index[TaskExecution::slotKey($execution->id, $execution->group)] = $execution;
        }

        return $index;
    }

    /**
     * A slot is pending when it has no stored execution yet, or its stored status
     * is retried on the next run — {@see TaskStatus::willRerun()} owns that rule.
     */
    private function isPendingSlot(?TaskExecution $execution): bool
    {
        return null === $execution || $execution->status->willRerun();
    }

    /**
     * @param list<?string> $pendingSlots
     */
    private function persistOutcome(string $taskId, TaskOutcome $outcome, array $pendingSlots): void
    {
        foreach ($pendingSlots as $slot) {
            $this->storage->save(new TaskExecution(
                id: $taskId,
                status: $outcome->status(),
                executedAt: $outcome->executedAt,
                error: $outcome->error,
                group: $slot,
            ));
        }
    }

    /**
     * Computes the slots a task participates in for the current invocation.
     *
     * Delegates to {@see SlotResolver::expand()} — the single owner of the
     * group→slot expansion — so bulk runs and single-task targeting cannot
     * drift apart.
     *
     * @param list<string> $requestedGroups [] targets every slot
     *
     * @return list<?string>
     *
     * @throws \ReflectionException When the #[AsDeployTask] attribute lookup fails
     */
    private static function computeSlots(DeployTaskInterface $task, array $requestedGroups): array
    {
        return SlotResolver::expand(AsDeployTask::groupsOf($task), $requestedGroups);
    }
}
