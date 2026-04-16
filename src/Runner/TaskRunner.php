<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Runner;

use Soviann\DeployTasksBundle\Attribute\AsDeployTask;
use Soviann\DeployTasksBundle\DeployTaskInterface;
use Soviann\DeployTasksBundle\Event\AfterTaskEvent;
use Soviann\DeployTasksBundle\Event\BeforeTaskEvent;
use Soviann\DeployTasksBundle\Event\TaskFailedEvent;
use Soviann\DeployTasksBundle\Exception\TaskGroupMismatchException;
use Soviann\DeployTasksBundle\Exception\TaskGroupRequiredException;
use Soviann\DeployTasksBundle\Identifier\TaskIdResolver;
use Soviann\DeployTasksBundle\Ordering\OrderedTaskCollection;
use Soviann\DeployTasksBundle\Ordering\TaskOrderResolverInterface;
use Soviann\DeployTasksBundle\Storage\TaskExecution;
use Soviann\DeployTasksBundle\Storage\TaskStatus;
use Soviann\DeployTasksBundle\Storage\TaskStorageInterface;
use Soviann\DeployTasksBundle\Storage\TransactionalStorageInterface;
use Soviann\DeployTasksBundle\TaskResult;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Executes pending deploy tasks in order, tracking results in storage.
 *
 * @internal
 */
final class TaskRunner
{
    public function __construct(
        private readonly TaskRegistry $registry,
        private readonly TaskStorageInterface $storage,
        private readonly TaskOrderResolverInterface $resolver,
        private readonly TaskIdResolver $idResolver,
        private readonly ?EventDispatcherInterface $dispatcher = null,
        private readonly ?LockFactory $lockFactory = null,
        private readonly int $defaultTimeout = 300,
        private readonly ?string $environment = null,
        private readonly bool $transactional = true,
        private readonly bool $allOrNothing = false,
    ) {
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
     */
    public function runAll(OutputInterface $output, bool $dryRun = false, bool $force = false, array $groups = []): RunResult
    {
        $result = $this->withLock($output, function () use ($output, $dryRun, $force, $groups): RunResult {
            $tasks = \array_values($this->registry->all($this->environment, $groups));
            $ordered = $this->resolver->resolve($tasks);
            /** @var list<?string> $effectiveGroups */
            $effectiveGroups = [] === $groups ? [null] : $groups;

            if ($dryRun) {
                return $this->dryRun($ordered, $output, $effectiveGroups);
            }

            if ($this->allOrNothing && $this->storage instanceof TransactionalStorageInterface) {
                try {
                    return $this->storage->transactional(fn (): RunResult => $this->executeAll($ordered, $output, $force, $effectiveGroups));
                } catch (\Throwable) {
                    return new RunResult(ran: 0, skipped: 0, failed: 1);
                }
            }

            return $this->executeAll($ordered, $output, $force, $effectiveGroups);
        });

        return $result ?? new RunResult(ran: 0, skipped: 0, failed: 0, locked: true);
    }

    /**
     * Runs a single task by ID, recording one storage row per target slot.
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
     * @throws TaskGroupRequiredException
     * @throws TaskGroupMismatchException
     */
    public function runOne(string $taskId, OutputInterface $output, bool $force = false, array $groups = []): TaskResult
    {
        $result = $this->withLock($output, function () use ($taskId, $output, $force, $groups): TaskResult {
            $task = $this->registry->get($taskId);
            $slots = $this->resolveSlotsForRunOne($taskId, $task, $groups);
            $pendingSlots = $this->filterPendingSlots($taskId, $slots, $force);

            if ([] === $pendingSlots) {
                $output->writeln(\sprintf('<comment>Task "%s" has already been executed.</comment>', $taskId));

                return TaskResult::SKIPPED;
            }

            $outcome = $this->executeTask($task, $output);

            $this->persistOutcome($taskId, $outcome, $pendingSlots);

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
     * @param \Closure(): T $operation
     *
     * @return T|null
     */
    private function withLock(OutputInterface $output, \Closure $operation): mixed
    {
        if (null === $this->lockFactory) {
            $output->writeln('<comment>No lock factory configured — concurrent execution is not protected.</comment>');
        }

        $lock = $this->lockFactory?->createLock('deploy_tasks_run', 3600);

        if (null !== $lock && !$lock->acquire()) {
            $output->writeln('<error>Another deploytasks:run process is already running.</error>');

            return null;
        }

        try {
            return $operation();
        } finally {
            $lock?->release();
        }
    }

    /**
     * Lists pending (task, slot) pairs without executing them.
     *
     * @param list<?string> $effectiveGroups
     */
    private function dryRun(OrderedTaskCollection $tasks, OutputInterface $output, array $effectiveGroups): RunResult
    {
        $pending = 0;
        $skipped = 0;

        foreach ($tasks as $task) {
            $taskId = $this->idResolver->resolve($task);
            $slots = self::computeSlots($task, $effectiveGroups);

            foreach ($slots as $slot) {
                $execution = $this->storage->get($taskId, $slot);

                if (null !== $execution && TaskStatus::Failed !== $execution->status) {
                    ++$skipped;

                    continue;
                }

                ++$pending;
                $label = null === $slot ? $taskId : $taskId.'@'.$slot;
                $output->writeln(\sprintf('  [pending] %s - %s', $label, $task->getDescription()));
            }
        }

        return new RunResult(ran: $pending, skipped: $skipped, failed: 0);
    }

    /**
     * Executes all ordered tasks, recording one storage row per matching slot.
     *
     * @param list<?string> $effectiveGroups
     */
    private function executeAll(OrderedTaskCollection $tasks, OutputInterface $output, bool $force, array $effectiveGroups): RunResult
    {
        $ran = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($tasks as $task) {
            $taskId = $this->idResolver->resolve($task);
            $slots = self::computeSlots($task, $effectiveGroups);

            if ([] === $slots) {
                continue;
            }

            $pendingSlots = $this->filterPendingSlots($taskId, $slots, $force);

            if ([] === $pendingSlots) {
                $skipped += \count($slots);

                continue;
            }

            $outcome = $this->executeTask($task, $output);
            $this->persistOutcome($taskId, $outcome, $pendingSlots);

            $count = \count($pendingSlots);

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
     * Storage writes are performed by the caller (one row per matching slot).
     */
    private function executeTask(DeployTaskInterface $task, OutputInterface $output): TaskOutcome
    {
        $taskId = $this->idResolver->resolve($task);
        $attribute = AsDeployTask::of($task);
        $timeout = null !== $attribute && null !== $attribute->timeout ? $attribute->timeout : $this->defaultTimeout;

        $this->dispatcher?->dispatch(new BeforeTaskEvent($taskId, $task));

        $start = \microtime(true);

        try {
            $result = $this->wrapInTransaction($task, $attribute, $output);
            $duration = \microtime(true) - $start;

            if ($duration > $timeout) {
                $output->writeln(\sprintf(
                    '<comment>Task "%s" exceeded timeout (%ds elapsed, %ds limit).</comment>',
                    $taskId,
                    (int) $duration,
                    $timeout,
                ));
            }

            $status = TaskResult::SKIPPED === $result ? TaskStatus::Skipped : TaskStatus::Ran;

            $this->dispatcher?->dispatch(new AfterTaskEvent($taskId, $task, $result, $duration));

            return new TaskOutcome(
                result: $result,
                status: $status,
                executedAt: new \DateTimeImmutable(),
            );
        } catch (\Throwable $e) {
            $duration = \microtime(true) - $start;

            $this->dispatcher?->dispatch(new TaskFailedEvent($taskId, $task, $e, $duration));

            $output->writeln(\sprintf('<error>Task "%s" failed: %s</error>', $taskId, $e->getMessage()));

            if ($this->allOrNothing) {
                // Propagate so the outer transaction rolls everything back
                throw $e;
            }

            return new TaskOutcome(
                result: TaskResult::FAILURE,
                status: TaskStatus::Failed,
                executedAt: new \DateTimeImmutable(),
                error: $e->getMessage(),
            );
        }
    }

    /**
     * Wraps task execution in a database transaction if the task is marked as transactional
     * and the storage backend supports it.
     */
    private function wrapInTransaction(DeployTaskInterface $task, ?AsDeployTask $attribute, OutputInterface $output): TaskResult
    {
        // Skip per-task wrapping when allOrNothing already wraps the entire run
        if ($this->allOrNothing) {
            return $task->run($output);
        }

        $shouldWrap = null !== $attribute ? ($attribute->transactional ?? $this->transactional) : $this->transactional;

        if ($shouldWrap && $this->storage instanceof TransactionalStorageInterface) {
            return $this->storage->transactional(static fn (): TaskResult => $task->run($output));
        }

        return $task->run($output);
    }

    /**
     * @param list<string> $groups
     *
     * @return list<?string>
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

        /** @var list<?string> $slots */
        $slots = $groups;

        return $slots;
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
            $existing = $this->storage->get($taskId, $slot);

            if (null === $existing || TaskStatus::Failed === $existing->status) {
                $pending[] = $slot;
            }
        }

        return $pending;
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
