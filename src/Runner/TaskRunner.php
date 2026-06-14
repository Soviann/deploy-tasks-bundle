<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Runner;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Soviann\DeployTasksBundle\Attribute\AsDeployTask;
use Soviann\DeployTasksBundle\DeployTaskInterface;
use Soviann\DeployTasksBundle\Event\AfterTaskEvent;
use Soviann\DeployTasksBundle\Event\BeforeTaskEvent;
use Soviann\DeployTasksBundle\Event\TaskFailedEvent;
use Soviann\DeployTasksBundle\Exception\AllOrNothingFailureException;
use Soviann\DeployTasksBundle\Exception\EventListenerException;
use Soviann\DeployTasksBundle\Exception\TaskEnvironmentMismatchException;
use Soviann\DeployTasksBundle\Exception\TaskGroupMismatchException;
use Soviann\DeployTasksBundle\Exception\TaskGroupRequiredException;
use Soviann\DeployTasksBundle\Exception\TaskNotFoundException;
use Soviann\DeployTasksBundle\Exception\TaskReturnedFailureException;
use Soviann\DeployTasksBundle\Helper\ConsoleSanitizer;
use Soviann\DeployTasksBundle\Identifier\TaskDescriptionResolver;
use Soviann\DeployTasksBundle\Identifier\TaskIdResolver;
use Soviann\DeployTasksBundle\Sorting\TaskSorterInterface;
use Soviann\DeployTasksBundle\Storage\TaskExecution;
use Soviann\DeployTasksBundle\Storage\TaskStatus;
use Soviann\DeployTasksBundle\Storage\TaskStorageInterface;
use Soviann\DeployTasksBundle\Storage\TransactionalStorageInterface;
use Soviann\DeployTasksBundle\TaskResult;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\LockInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Executes pending deploy tasks in order, tracking results in storage.
 */
final class TaskRunner
{
    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly TaskRegistry $registry,
        private readonly TaskStorageInterface $storage,
        private readonly TaskSorterInterface $sorter,
        private readonly TaskIdResolver $idResolver,
        private readonly TaskDescriptionResolver $descriptionResolver,
        private readonly int $defaultTimeout,
        private readonly bool $transactional,
        private readonly bool $allOrNothing,
        private readonly int $lockTtl,
        private readonly ?EventDispatcherInterface $dispatcher = null,
        private readonly ?LockFactory $lockFactory = null,
        private readonly ?string $environment = null,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Runs all pending tasks in resolved order.
     *
     * When `$groups` is empty only default-slot tasks run; when it lists one or more
     * group names, only tasks declaring any of those groups run, and a multi-group
     * task executes once per invocation writing one storage row per matching slot.
     * When `$force` is true, all matching slots are re-executed regardless of state.
     *
     * @param list<string> $groups
     *
     * @throws \ReflectionException When the #[AsDeployTask] attribute lookup fails for a registered task
     * @throws \Throwable           When `all_or_nothing` is enabled and a task throws — the exception escapes after
     *                              the transaction is rolled back
     */
    public function runAll(
        OutputInterface $output,
        bool $dryRun = false,
        bool $force = false,
        array $groups = [],
    ): RunResult {
        $result = $this->withLock(
            $output,
            function (?LockInterface $lock) use ($output, $dryRun, $force, $groups): RunResult {
                $this->logger->info('Deploy tasks run starting', [
                    'environment' => $this->environment,
                    'dry_run' => $dryRun,
                    'force' => $force,
                    'groups' => $groups,
                ]);

                $tasks = \array_values($this->registry->all($this->environment, $groups));
                $sorted = $this->sorter->sort($tasks);
                /** @var list<?string> $effectiveGroups */
                $effectiveGroups = [] === $groups ? [null] : $groups;

                if ($dryRun) {
                    return $this->dryRun($sorted, $output, $effectiveGroups, $force);
                }

                if ($this->allOrNothing && $this->storage instanceof TransactionalStorageInterface) {
                    try {
                        return $this->storage->transactional(
                            fn (): RunResult => $this->executeAll($sorted, $output, $force, $effectiveGroups, $lock),
                        );
                    } catch (\Throwable $e) {
                        $this->logger->error(
                            'Deploy tasks run failed — transaction rolled back.',
                            $this->buildExceptionLogContext($e),
                        );

                        throw $e;
                    }
                }

                return $this->executeAll($sorted, $output, $force, $effectiveGroups, $lock);
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
     * When `$dryRun` is true, pending slots are listed without executing the task
     * and nothing is written to storage.
     *
     * When `all_or_nothing` is enabled and the storage backend is transactional,
     * execution and persistence run inside a single transaction, mirroring runAll():
     * a failure rolls back every side-effect before the exception escapes.
     *
     * Slot resolution:
     * - `$groups === []` and task has no declared groups → single default slot.
     * - `$groups === []` and task declares groups → throws {@see TaskGroupRequiredException}.
     * - `$groups !== []` and task has no declared groups → throws {@see TaskGroupMismatchException}.
     * - `$groups !== []` → slots are the requested groups; any undeclared group throws
     *   {@see TaskGroupMismatchException}.
     *
     * @param list<string> $groups
     *
     * @throws TaskEnvironmentMismatchException When the task declares an env constraint that does not match the
     *                                          runner's environment
     * @throws TaskGroupRequiredException
     * @throws TaskGroupMismatchException
     * @throws TaskNotFoundException            When no task is registered with the given id
     * @throws \ReflectionException             When the #[AsDeployTask] attribute lookup fails
     * @throws \Throwable                       When `all_or_nothing` is enabled and the task throws — the exception
     *                                          escapes after the transaction is rolled back
     */
    public function runOne(
        string $taskId,
        OutputInterface $output,
        bool $dryRun = false,
        bool $force = false,
        array $groups = [],
    ): TaskResult {
        $result = $this->withLock(
            $output,
            function (?LockInterface $lock) use ($taskId, $output, $force, $groups, $dryRun): TaskResult {
                $task = $this->registry->get($taskId);

                $attribute = AsDeployTask::of($task);
                $taskEnv = $attribute?->env;

                if (null !== $taskEnv && null !== $this->environment) {
                    $envs = \is_array($taskEnv) ? $taskEnv : [$taskEnv];

                    if (!\in_array($this->environment, $envs, true)) {
                        throw new TaskEnvironmentMismatchException($taskId, \is_array($taskEnv) ? \implode('|', $taskEnv) : $taskEnv, $this->environment);
                    }
                }

                $slots = $this->resolveSlotsForRunOne($taskId, $task, $groups);
                $pendingSlots = $this->filterPendingSlots($taskId, $slots, $force);

                if ([] === $pendingSlots) {
                    $output->writeln(\sprintf('<comment>Task "%s" has already been executed.</comment>', $taskId));
                    $this->logger->info('Deploy task skipped (already executed)', ['task_id' => $taskId]);

                    return TaskResult::SKIPPED;
                }

                if ($dryRun) {
                    foreach ($pendingSlots as $slot) {
                        $label = null === $slot ? $taskId : $taskId.'@'.$slot;
                        $output->writeln(\sprintf(
                            '  [would run] %s - %s',
                            $label,
                            $this->descriptionResolver->resolve($task),
                        ));
                    }

                    return TaskResult::SUCCESS;
                }

                if ($this->allOrNothing && $this->storage instanceof TransactionalStorageInterface) {
                    try {
                        return $this->storage->transactional(
                            fn (): TaskResult => $this->executeTask($task, $output, 1, 1, $taskId, $pendingSlots)->result,
                        );
                    } catch (\Throwable $e) {
                        $this->logger->error(
                            'Deploy tasks run failed — transaction rolled back.',
                            $this->buildExceptionLogContext($e),
                        );

                        throw $e;
                    }
                }

                $outcome = $this->executeTask($task, $output, 1, 1, $taskId, $pendingSlots);

                return $outcome->result;
            });

        return $result ?? TaskResult::LOCKED;
    }

    /**
     * Acquires the shared run lock, executes the operation, and releases on the way out.
     *
     * Returns null when the lock is already held by another process — caller must map that
     * to its own sentinel (RunResult::$locked or TaskResult::LOCKED).
     *
     * @template T
     *
     * @param \Closure(?LockInterface): T $operation
     *
     * @return T|null
     */
    private function withLock(OutputInterface $output, \Closure $operation): mixed
    {
        if (null === $this->lockFactory) {
            if ($output->isVerbose()) {
                $output->writeln('<comment>No lock factory configured — concurrent execution is not protected.</comment>');
            }
            $this->logger->warning('Deploy tasks runner has no lock factory — concurrent execution is not protected');
        }

        $lock = $this->lockFactory?->createLock('soviann_deploy_tasks_run', $this->lockTtl);

        if (null !== $lock && !$lock->acquire()) {
            $output->writeln('<error>Another deploytasks:run process is already running.</error>');
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
     * When `$force` is true, every slot is treated as pending, mirroring what a
     * forced run would re-execute.
     *
     * @param list<DeployTaskInterface> $tasks
     * @param list<?string>             $effectiveGroups
     *
     * @throws \ReflectionException
     */
    private function dryRun(array $tasks, OutputInterface $output, array $effectiveGroups, bool $force): RunResult
    {
        $pending = 0;
        $skipped = 0;

        foreach ($tasks as $task) {
            $taskId = $this->idResolver->resolve($task);
            $slots = self::computeSlots($task, $effectiveGroups);

            foreach ($slots as $slot) {
                $execution = $force ? null : $this->storage->get($taskId, $slot);

                if (!$this->isPendingSlot($execution)) {
                    ++$skipped;

                    continue;
                }

                ++$pending;
                $label = null === $slot ? $taskId : $taskId.'@'.$slot;
                $output->writeln(\sprintf('  [would run] %s - %s', $label, $this->descriptionResolver->resolve($task)));
            }
        }

        return new RunResult(ran: $pending, skipped: $skipped, failed: 0);
    }

    /**
     * Executes all ordered tasks, recording one storage row per matching slot.
     *
     * Refreshes the lock after each task (including the last — harmless but avoids a
     * special-case branch) so a deploy longer than the configured TTL does not let the
     * lease expire and allow a second runner to acquire it mid-deploy.
     *
     * @param list<DeployTaskInterface> $tasks
     * @param list<?string>             $effectiveGroups
     *
     * @throws \ReflectionException
     * @throws \Throwable           When `all_or_nothing` is enabled and a task throws
     */
    private function executeAll(
        array $tasks,
        OutputInterface $output,
        bool $force,
        array $effectiveGroups,
        ?LockInterface $lock = null,
    ): RunResult {
        $ran = 0;
        $skipped = 0;
        $failed = 0;

        // Pre-compute which tasks will actually be executed, so progress counters are accurate.
        /** @var list<array{task: DeployTaskInterface, taskId: string, pendingSlots: list<?string>}> $executable */
        $executable = [];

        foreach ($tasks as $task) {
            $taskId = $this->idResolver->resolve($task);
            $slots = self::computeSlots($task, $effectiveGroups);

            if ([] === $slots) {
                continue;
            }

            $pendingSlots = $this->filterPendingSlots($taskId, $slots, $force);
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
                if ($this->allOrNothing) {
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

            $lock?->refresh();

            $count = \count($item['pendingSlots']);

            if (TaskResult::FAILURE === $outcome->result) {
                $failed += $count;
            } elseif (TaskResult::SKIPPED === $outcome->result) {
                $skipped += $count;
            } else {
                $ran += $count;
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
     * Persistence happens after run() completes: the success path writes through
     * persistOutcomeTransactional() (a dedicated per-task transaction on transactional
     * backends), the failure path through plain persistOutcome(); under all_or_nothing
     * both nest inside the run-wide transaction. The execution record is always written
     * before After/Failed events are dispatched.
     *
     * @param list<?string> $pendingSlots Slots to persist the execution outcome into
     *
     * @throws \ReflectionException
     * @throws \Throwable           When `all_or_nothing` is enabled and a task throws
     * @throws \Throwable           When storage throws inside the transactional closure
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

        try {
            $result = $this->wrapInTransaction($task, $attribute, $output, $taskId);
            $taskRanSuccessfully = true;
            $duration = \microtime(true) - $start;

            $outcome = $this->buildSuccessOutcome($taskId, $result, $duration, $timeout, $output);

            if (!$output->isQuiet()) {
                $output->writeln(\sprintf(
                    '   → %s (%dms)',
                    $outcome->status->value,
                    (int) \round($outcome->durationSeconds * 1000),
                ));
            }

            $this->persistOutcomeTransactional($taskId, $outcome, $pendingSlots);

            // Persist must precede dispatch so a throwing listener cannot lose the record.
            $this->dispatchGuarded(new AfterTaskEvent($taskId, $task, $result, $duration), $taskId);

            return $outcome;
        } catch (\Throwable $e) {
            $duration = \microtime(true) - $start;

            // Re-raise when the task itself succeeded: the record is already persisted, and a
            // failure record would be wrong — this covers both a storage save() failure and a
            // throwing AfterTaskEvent listener.
            if ($taskRanSuccessfully) {
                throw $e;
            }

            $outcome = $this->buildFailureOutcome($taskId, $e, $duration, $output);

            if (!$output->isQuiet()) {
                $output->writeln(\sprintf(
                    '   → %s (%dms)',
                    $outcome->status->value,
                    (int) \round($outcome->durationSeconds * 1000),
                ));
            }

            $this->persistOutcome($taskId, $outcome, $pendingSlots);

            $this->dispatchGuarded(new TaskFailedEvent($taskId, $task, $e, $duration), $taskId);

            if ($this->allOrNothing) {
                // Propagate so the outer transaction rolls everything back
                throw $e;
            }

            return $outcome;
        }
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

        $status = TaskResult::SKIPPED === $result ? TaskStatus::Skipped : TaskStatus::Ran;

        $this->logger->info('Deploy task executed', [
            'task_id' => $taskId,
            'result' => $result->value,
            'duration_ms' => (int) \round($duration * 1000),
        ]);

        return new TaskOutcome(
            result: $result,
            status: $status,
            executedAt: new \DateTimeImmutable(),
            durationSeconds: $duration,
        );
    }

    private function buildFailureOutcome(
        string $taskId,
        \Throwable $e,
        float $duration,
        OutputInterface $output,
    ): TaskOutcome {
        $output->writeln(\sprintf(
            '<error>Task "%s" failed: %s</error>',
            $taskId,
            ConsoleSanitizer::sanitize($e->getMessage()),
        ));
        $this->logger->error('Deploy task failed', [
            'task_id' => $taskId,
            'duration_ms' => (int) \round($duration * 1000),
            ...$this->buildExceptionLogContext($e),
        ]);

        return new TaskOutcome(
            result: TaskResult::FAILURE,
            status: TaskStatus::Failed,
            executedAt: new \DateTimeImmutable(),
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

            throw new EventListenerException(\sprintf('Listener for %s failed.', $event::class), 0, $listenerError);
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
     * Wraps task execution in a database transaction if the task is marked as transactional
     * and the storage backend supports it.
     *
     * A returned TaskResult::FAILURE (or the runner-reserved TaskResult::LOCKED) is converted
     * into a TaskReturnedFailureException inside the wrapper, so it follows the same path as
     * a thrown failure: rollback, TaskFailedEvent, Failed record, all_or_nothing abort.
     */
    private function wrapInTransaction(
        DeployTaskInterface $task,
        ?AsDeployTask $attribute,
        OutputInterface $output,
        string $taskId,
    ): TaskResult {
        $run = static function () use ($task, $output, $taskId): TaskResult {
            $result = $task->run($output);

            if (TaskResult::FAILURE === $result || TaskResult::LOCKED === $result) {
                // Thrown inside any wrapping transaction so the task's side-effects
                // roll back together with the failure.
                throw TaskReturnedFailureException::create($taskId, $result);
            }

            return $result;
        };

        // Skip per-task wrapping when allOrNothing already wraps the entire run
        if ($this->allOrNothing) {
            return $run();
        }

        $shouldWrap = null !== $attribute ? ($attribute->transactional ?? $this->transactional) : $this->transactional;

        if ($shouldWrap && $this->storage instanceof TransactionalStorageInterface) {
            return $this->storage->transactional($run);
        }

        return $run();
    }

    /**
     * Persists the task outcome for each pending slot, wrapping the saves in a single
     * per-task transaction when the storage backend supports it.
     *
     * Placing `save()` inside the same transaction as `task->run()` would require
     * restructuring the call stack.  Instead, a dedicated per-save transaction is used:
     * if storage throws during any save the exception propagates to the caller
     * (which re-raises it), keeping the runner's failure semantics intact while ensuring
     * that a partial-write within a multi-slot task is rolled back on transactional backends.
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
     * @param list<string> $groups
     *
     * @return list<?string>
     *
     * @throws TaskGroupRequiredException
     * @throws TaskGroupMismatchException
     * @throws \ReflectionException
     */
    private function resolveSlotsForRunOne(string $taskId, DeployTaskInterface $task, array $groups): array
    {
        $declared = AsDeployTask::groupsOf($task);

        if ([] === $groups) {
            if (null !== $declared) {
                throw TaskGroupRequiredException::create($taskId, $declared);
            }

            return [null];
        }

        if (null === $declared) {
            throw TaskGroupMismatchException::create($taskId, $groups, []);
        }

        $undeclared = \array_values(\array_diff($groups, $declared));

        if ([] !== $undeclared) {
            throw TaskGroupMismatchException::create($taskId, $undeclared, $declared);
        }

        return $groups;
    }

    /**
     * @param list<?string> $slots
     *
     * @return list<?string>
     */
    private function filterPendingSlots(string $taskId, array $slots, bool $force): array
    {
        if ($force) {
            return $slots;
        }

        $pending = [];

        foreach ($slots as $slot) {
            if ($this->isPendingSlot($this->storage->get($taskId, $slot))) {
                $pending[] = $slot;
            }
        }

        return $pending;
    }

    /**
     * A slot is pending when it has no stored execution yet, or its last execution
     * failed — failed slots are retried on the next run.
     */
    private function isPendingSlot(?TaskExecution $execution): bool
    {
        return null === $execution || TaskStatus::Failed === $execution->status;
    }

    /**
     * @param list<?string> $pendingSlots
     */
    private function persistOutcome(string $taskId, TaskOutcome $outcome, array $pendingSlots): void
    {
        foreach ($pendingSlots as $slot) {
            $this->storage->save(new TaskExecution(
                id: $taskId,
                status: $outcome->status,
                executedAt: $outcome->executedAt,
                error: $outcome->error,
                group: $slot,
            ));
        }
    }

    /**
     * Computes the slots a task participates in for the current invocation.
     *
     * @param list<?string> $effectiveGroups null means the default slot is requested
     *
     * @return list<?string>
     */
    private static function computeSlots(DeployTaskInterface $task, array $effectiveGroups): array
    {
        $declared = AsDeployTask::groupsOf($task);

        if (null === $declared) {
            return \in_array(null, $effectiveGroups, true) ? [null] : [];
        }

        $slots = [];

        foreach ($effectiveGroups as $group) {
            if (null !== $group && \in_array($group, $declared, true)) {
                $slots[] = $group;
            }
        }

        return $slots;
    }
}
