<?php

declare(strict_types=1);

namespace Soviann\DeployTasks;

use Soviann\DeployTasks\Contract\Attribute\AsDeployTask;
use Soviann\DeployTasks\Contract\DeployTaskInterface;
use Soviann\DeployTasks\Contract\OrderedTaskCollection;
use Soviann\DeployTasks\Contract\TaskExecution;
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
        private readonly ?EventDispatcherInterface $dispatcher = null,
        private readonly ?LockFactory $lockFactory = null,
        private readonly int $defaultTimeout = 300,
        private readonly ?string $environment = null,
    ) {
    }

    /**
     * Runs all pending tasks in resolved order.
     */
    public function runAll(OutputInterface $output, bool $dryRun = false): RunResult
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

            return $this->executeAll($ordered, $output);
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
            $execution = $this->storage->get($task->getId());

            if (null !== $execution && TaskStatus::Failed !== $execution->status) {
                ++$skipped;

                continue;
            }

            ++$pending;
            $output->writeln(\sprintf('  [pending] %s — %s', $task->getId(), $task->getDescription()));
        }

        return new RunResult(ran: $pending, skipped: $skipped, failed: 0);
    }

    /**
     * Executes all ordered tasks, recording results.
     */
    private function executeAll(OrderedTaskCollection $tasks, OutputInterface $output): RunResult
    {
        $ran = 0;
        $skipped = 0;
        $failed = 0;
        /** @var array<string, \Throwable> $errors */
        $errors = [];

        foreach ($tasks as $task) {
            $execution = $this->storage->get($task->getId());

            if (null !== $execution && TaskStatus::Failed !== $execution->status) {
                ++$skipped;

                continue;
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
        $attribute = AsDeployTask::of($task);
        $timeout = null !== $attribute && null !== $attribute->timeout ? $attribute->timeout : $this->defaultTimeout;

        $this->dispatcher?->dispatch(new BeforeTaskEvent($task));

        $start = \microtime(true);

        try {
            $result = $this->wrapInTransaction($task, $attribute, $output);
            $duration = \microtime(true) - $start;

            if ($duration > $timeout) {
                $output->writeln(\sprintf(
                    '<comment>Task "%s" exceeded timeout (%ds elapsed, %ds limit).</comment>',
                    $task->getId(),
                    (int) $duration,
                    $timeout,
                ));
            }

            $status = TaskResult::SKIPPED === $result ? TaskStatus::Skipped : TaskStatus::Ran;

            $this->storage->save(new TaskExecution(
                id: $task->getId(),
                status: $status,
                executedAt: new \DateTimeImmutable(),
            ));

            $this->dispatcher?->dispatch(new AfterTaskEvent($task, $result, $duration));

            return $result;
        } catch (\Throwable $e) {
            $duration = \microtime(true) - $start;

            $this->storage->save(new TaskExecution(
                id: $task->getId(),
                status: TaskStatus::Failed,
                executedAt: new \DateTimeImmutable(),
                error: $e->getMessage(),
            ));

            $this->dispatcher?->dispatch(new TaskFailedEvent($task, $e, $duration));

            $output->writeln(\sprintf('<error>Task "%s" failed: %s</error>', $task->getId(), $e->getMessage()));

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
        if (null !== $attribute && $attribute->transactional && $this->storage instanceof TransactionalStorageInterface) {
            /** @var TaskResult::* $result */
            $result = $this->storage->transactional(static fn (): int => $task->run($output));

            return $result;
        }

        return $task->run($output);
    }
}
