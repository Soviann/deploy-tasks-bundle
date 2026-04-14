<?php

declare(strict_types=1);

namespace Soviann\DeployTasks;

use Soviann\DeployTasks\Contract\Attribute\AsDeployTask;
use Soviann\DeployTasks\Contract\DeployTaskInterface;
use Soviann\DeployTasks\Contract\OrderedTaskCollection;
use Soviann\DeployTasks\Contract\TaskExecution;
use Soviann\DeployTasks\Contract\TaskIdResolverInterface;
use Soviann\DeployTasks\Contract\TaskOrderResolverInterface;
use Soviann\DeployTasks\Contract\TaskResult;
use Soviann\DeployTasks\Contract\TaskStatus;
use Soviann\DeployTasks\Contract\TaskStorageInterface;
use Soviann\DeployTasks\Contract\TransactionalStorageInterface;
use Soviann\DeployTasks\Event\AfterTaskEvent;
use Soviann\DeployTasks\Event\BeforeTaskEvent;
use Soviann\DeployTasks\Event\TaskFailedEvent;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Executes pending deploy tasks in order, tracking results in storage.
 */
final class TaskRunner
{
    public function __construct(
        private readonly TaskRegistry $registry,
        private readonly TaskStorageInterface $storage,
        private readonly TaskOrderResolverInterface $resolver,
        private readonly TaskIdResolverInterface $idResolver,
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
     * When $force is true, all tasks are executed regardless of their state.
     */
    public function runAll(OutputInterface $output, bool $dryRun = false, bool $force = false): RunResult
    {
        if (null === $this->lockFactory) {
            $output->writeln('<comment>No lock factory configured — concurrent execution is not protected.</comment>');
        }

        $lock = $this->lockFactory?->createLock('deploy_tasks_run', 3600);

        if (null !== $lock && !$lock->acquire()) {
            $output->writeln('<error>Another deploytasks:run process is already running.</error>');

            return new RunResult(ran: 0, skipped: 0, failed: 0, locked: true);
        }

        try {
            $tasks = \array_values($this->registry->all($this->environment));
            $ordered = $this->resolver->resolve($tasks);

            if ($dryRun) {
                return $this->dryRun($ordered, $output);
            }

            if ($this->allOrNothing && $this->storage instanceof TransactionalStorageInterface) {
                try {
                    return $this->storage->transactional(fn (): RunResult => $this->executeAll($ordered, $output, $force));
                } catch (\Throwable) {
                    return new RunResult(ran: 0, skipped: 0, failed: 1);
                }
            }

            return $this->executeAll($ordered, $output, $force);
        } finally {
            $lock?->release();
        }
    }

    /**
     * Runs a single task by ID, optionally forcing re-execution.
     *
     * @return TaskResult::*
     */
    public function runOne(string $taskId, OutputInterface $output, bool $force = false): int
    {
        $task = $this->registry->get($taskId);

        if (!$force && $this->storage->has($taskId)) {
            $execution = $this->storage->get($taskId);

            if (null !== $execution && TaskStatus::Failed !== $execution->status) {
                $output->writeln(\sprintf('<comment>Task "%s" has already been executed.</comment>', $taskId));

                return TaskResult::SKIPPED;
            }
        }

        return $this->executeTask($task, $output);
    }

    /**
     * Lists pending tasks without executing them.
     */
    private function dryRun(OrderedTaskCollection $tasks, OutputInterface $output): RunResult
    {
        $pending = 0;
        $skipped = 0;

        foreach ($tasks as $task) {
            $taskId = $this->idResolver->resolve($task);
            $execution = $this->storage->get($taskId);

            if (null !== $execution && TaskStatus::Failed !== $execution->status) {
                ++$skipped;

                continue;
            }

            ++$pending;
            $output->writeln(\sprintf('  [pending] %s — %s', $taskId, $task->getDescription()));
        }

        return new RunResult(ran: $pending, skipped: $skipped, failed: 0);
    }

    /**
     * Executes all ordered tasks, recording results.
     */
    private function executeAll(OrderedTaskCollection $tasks, OutputInterface $output, bool $force = false): RunResult
    {
        $ran = 0;
        $skipped = 0;
        $failed = 0;
        /** @var array<string, \Throwable> $errors */
        $errors = [];

        foreach ($tasks as $task) {
            if (!$force) {
                $taskId = $this->idResolver->resolve($task);
                $execution = $this->storage->get($taskId);

                if (null !== $execution && TaskStatus::Failed !== $execution->status) {
                    ++$skipped;

                    continue;
                }
            }

            $result = $this->executeTask($task, $output);

            if (TaskResult::FAILURE === $result) {
                ++$failed;
            } elseif (TaskResult::SKIPPED === $result) {
                ++$skipped;
            } else {
                ++$ran;
            }
        }

        return new RunResult(ran: $ran, skipped: $skipped, failed: $failed, errors: $errors);
    }

    /**
     * Executes a single task with event dispatching, timeout check, and storage recording.
     *
     * @return TaskResult::*
     */
    private function executeTask(DeployTaskInterface $task, OutputInterface $output): int
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

            $this->storage->save(new TaskExecution(
                id: $taskId,
                status: $status,
                executedAt: new \DateTimeImmutable(),
            ));

            $this->dispatcher?->dispatch(new AfterTaskEvent($taskId, $task, $result, $duration));

            return $result;
        } catch (\Throwable $e) {
            $duration = \microtime(true) - $start;

            $this->dispatcher?->dispatch(new TaskFailedEvent($taskId, $task, $e, $duration));

            $output->writeln(\sprintf('<error>Task "%s" failed: %s</error>', $taskId, $e->getMessage()));

            if ($this->allOrNothing) {
                // Propagate so the outer transaction rolls everything back
                throw $e;
            }

            $this->storage->save(new TaskExecution(
                id: $taskId,
                status: TaskStatus::Failed,
                executedAt: new \DateTimeImmutable(),
                error: $e->getMessage(),
            ));

            return TaskResult::FAILURE;
        }
    }

    /**
     * Wraps task execution in a database transaction if the task is marked as transactional
     * and the storage backend supports it.
     *
     * @return TaskResult::*
     */
    private function wrapInTransaction(DeployTaskInterface $task, ?AsDeployTask $attribute, OutputInterface $output): int
    {
        // Skip per-task wrapping when allOrNothing already wraps the entire run
        if ($this->allOrNothing) {
            return $task->run($output);
        }

        $shouldWrap = null !== $attribute ? ($attribute->transactional ?? $this->transactional) : $this->transactional;

        if ($shouldWrap && $this->storage instanceof TransactionalStorageInterface) {
            /** @var TaskResult::* $result */
            $result = $this->storage->transactional(static fn (): int => $task->run($output));

            return $result;
        }

        return $task->run($output);
    }
}
