<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Storage\InMemory;

use Soviann\DeployTasksBundle\Storage\TaskExecution;
use Soviann\DeployTasksBundle\Storage\TaskStorageInterface;

/**
 * In-memory task storage — intended for testing only.
 *
 * Records are keyed by a composite (task id, group) pair using the NUL byte as a
 * separator. IDs and group names are user-facing identifiers that never contain NUL.
 *
 * @internal
 */
final class InMemoryStorage implements TaskStorageInterface
{
    /** @var array<string, TaskExecution> */
    private array $executions = [];

    public function has(string $taskId, ?string $group = null): bool
    {
        return isset($this->executions[TaskExecution::slotKey($taskId, $group)]);
    }

    public function get(string $taskId, ?string $group = null): ?TaskExecution
    {
        return $this->executions[TaskExecution::slotKey($taskId, $group)] ?? null;
    }

    public function save(TaskExecution $execution): void
    {
        $this->executions[TaskExecution::slotKey($execution->id, $execution->group)] = $execution;
    }

    public function remove(string $taskId, ?string $group = null): void
    {
        unset($this->executions[TaskExecution::slotKey($taskId, $group)]);
    }

    public function removeAll(string $taskId): void
    {
        // slotKey(id, null) is exactly the shared "<id>\0" prefix of every slot of this task.
        $prefix = TaskExecution::slotKey($taskId, null);

        foreach (\array_keys($this->executions) as $key) {
            if (\str_starts_with($key, $prefix)) {
                unset($this->executions[$key]);
            }
        }
    }

    /**
     * @return list<TaskExecution>
     */
    public function findByTaskId(string $taskId): array
    {
        return \array_values(\array_filter(
            $this->executions,
            static fn (TaskExecution $execution): bool => $execution->id === $taskId,
        ));
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
}
