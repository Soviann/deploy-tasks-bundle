<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Tests\Fixtures;

use Soviann\DeployTasksBundle\Storage\TaskExecution;
use Soviann\DeployTasksBundle\Storage\TransactionalStorageInterface;

final class TransactionalInMemoryStorageFixture implements TransactionalStorageInterface
{
    /** @var array<string, TaskExecution> */
    private array $executions = [];

    public function has(string $taskId, ?string $group = null): bool
    {
        return isset($this->executions[self::key($taskId, $group)]);
    }

    public function get(string $taskId, ?string $group = null): ?TaskExecution
    {
        return $this->executions[self::key($taskId, $group)] ?? null;
    }

    public function save(TaskExecution $execution): void
    {
        $this->executions[self::key($execution->id, $execution->group)] = $execution;
    }

    public function remove(string $taskId, ?string $group = null): void
    {
        unset($this->executions[self::key($taskId, $group)]);
    }

    public function removeAll(string $taskId): void
    {
        $prefix = $taskId."\0";

        foreach (\array_keys($this->executions) as $key) {
            if (\str_starts_with($key, $prefix)) {
                unset($this->executions[$key]);
            }
        }
    }

    /**
     * @return list<TaskExecution>
     */
    public function all(): array
    {
        return \array_values($this->executions);
    }

    public function reset(): void
    {
        $this->executions = [];
    }

    public function transactional(\Closure $callback): mixed
    {
        return $callback();
    }

    private static function key(string $taskId, ?string $group): string
    {
        return $taskId."\0".($group ?? '');
    }
}
