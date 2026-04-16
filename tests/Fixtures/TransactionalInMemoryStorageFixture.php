<?php

declare(strict_types=1);

namespace Soviann\DeployTasks\Tests\Fixtures;

use Soviann\DeployTasks\Contract\TaskExecution;
use Soviann\DeployTasks\Contract\TransactionalStorageInterface;

final class TransactionalInMemoryStorageFixture implements TransactionalStorageInterface
{
    /** @var array<string, TaskExecution> */
    private array $executions = [];

    public function has(string $taskId): bool
    {
        return isset($this->executions[$taskId]);
    }

    public function get(string $taskId): ?TaskExecution
    {
        return $this->executions[$taskId] ?? null;
    }

    public function save(TaskExecution $execution): void
    {
        $this->executions[$execution->id] = $execution;
    }

    public function remove(string $taskId): void
    {
        unset($this->executions[$taskId]);
    }

    public function all(): array
    {
        return $this->executions;
    }

    public function reset(): void
    {
        $this->executions = [];
    }

    public function transactional(\Closure $callback): mixed
    {
        return $callback();
    }
}
