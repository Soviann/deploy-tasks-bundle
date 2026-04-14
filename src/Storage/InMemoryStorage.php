<?php

declare(strict_types=1);

namespace Soviann\DeployTasks\Storage;

use Soviann\DeployTasks\Contract\TaskExecution;
use Soviann\DeployTasks\Contract\TaskStorageInterface;

/**
 * In-memory task storage — intended for testing only.
 *
 * @internal
 */
final class InMemoryStorage implements TaskStorageInterface
{
    /** @var array<string, TaskExecution> */
    private array $executions = [];

    /**
     * Whether an execution record exists for the given task ID.
     */
    public function has(string $taskId): bool
    {
        return isset($this->executions[$taskId]);
    }

    /**
     * Returns the execution record for the given task ID, or null if not found.
     */
    public function get(string $taskId): ?TaskExecution
    {
        return $this->executions[$taskId] ?? null;
    }

    /**
     * Saves or updates an execution record.
     */
    public function save(TaskExecution $execution): void
    {
        $this->executions[$execution->id] = $execution;
    }

    /**
     * Removes the execution record for the given task ID.
     */
    public function remove(string $taskId): void
    {
        unset($this->executions[$taskId]);
    }

    /**
     * Returns all stored execution records, keyed by task ID.
     *
     * @return array<string, TaskExecution>
     */
    public function all(): array
    {
        return $this->executions;
    }
}
