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

    /**
     * @throws \InvalidArgumentException When the group is the empty string
     */
    public function has(string $taskId, ?string $group = null): bool
    {
        $this->assertGroupIsNotEmptyString($group);

        return isset($this->executions[TaskExecution::slotKey($taskId, $group)]);
    }

    /**
     * @throws \InvalidArgumentException When the group is the empty string
     */
    public function get(string $taskId, ?string $group = null): ?TaskExecution
    {
        $this->assertGroupIsNotEmptyString($group);

        return $this->executions[TaskExecution::slotKey($taskId, $group)] ?? null;
    }

    /**
     * @throws \InvalidArgumentException When the execution's group is the empty string
     */
    public function save(TaskExecution $execution): void
    {
        $this->assertGroupIsNotEmptyString($execution->group);

        $this->executions[TaskExecution::slotKey($execution->id, $execution->group)] = $execution;
    }

    /**
     * @throws \InvalidArgumentException When the group is the empty string
     */
    public function remove(string $taskId, ?string $group = null): void
    {
        $this->assertGroupIsNotEmptyString($group);

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
     * Records come back in insertion order (oldest save first) — a property of this
     * backend, not of the interface, which guarantees no ordering.
     *
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

    /**
     * slotKey() maps a null group to '' internally, so an empty-string group would
     * silently alias the default slot. Rejected as an input-contract violation,
     * matching the other backends (see TaskStorageInterface).
     *
     * @throws \InvalidArgumentException
     */
    private function assertGroupIsNotEmptyString(?string $group): void
    {
        if ('' === $group) {
            throw new \InvalidArgumentException('Group name must not be the empty string; use null to target the default group slot.');
        }
    }
}
