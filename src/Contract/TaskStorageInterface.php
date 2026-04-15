<?php

declare(strict_types=1);

namespace Soviann\DeployTasks\Contract;

/**
 * Persists task execution records to track which tasks have already run.
 */
interface TaskStorageInterface
{
    /**
     * Whether an execution record exists for the given task ID.
     */
    public function has(string $taskId): bool;

    /**
     * Returns the execution record for the given task ID, or null if not found.
     */
    public function get(string $taskId): ?TaskExecution;

    /**
     * Saves or updates an execution record.
     */
    public function save(TaskExecution $execution): void;

    /**
     * Removes the execution record for the given task ID.
     */
    public function remove(string $taskId): void;

    /**
     * Returns all stored execution records, keyed by task ID.
     *
     * No particular order is guaranteed; task ordering is handled by TaskOrderResolverInterface.
     *
     * @return array<string, TaskExecution>
     */
    public function all(): array;

    /**
     * Removes all execution records from storage.
     */
    public function reset(): void;
}
