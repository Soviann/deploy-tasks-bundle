<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Storage;

/**
 * Persists task execution records to track which tasks have already run.
 *
 * Records are scoped by a (task id, group) pair. A null group maps to the default slot
 * used when deploytasks:run is called without --group. Passing a non-null group targets
 * a named group slot; a task that belongs to multiple groups has one record per slot.
 */
interface TaskStorageInterface
{
    /**
     * Whether an execution record exists for the given task ID and group slot.
     */
    public function has(string $taskId, ?string $group = null): bool;

    /**
     * Returns the execution record for the given task ID and group slot, or null if not found.
     */
    public function get(string $taskId, ?string $group = null): ?TaskExecution;

    /**
     * Saves or updates an execution record. The group slot is read from the execution entity.
     */
    public function save(TaskExecution $execution): void;

    /**
     * Removes the execution record for the given task ID and group slot.
     *
     * Only the matching (id, group) row is deleted; other group slots for the same id are preserved.
     * Use removeAll() to delete every slot for an id.
     */
    public function remove(string $taskId, ?string $group = null): void;

    /**
     * Removes every execution record for the given task ID across all group slots.
     */
    public function removeAll(string $taskId): void;

    /**
     * Returns all stored execution records as a flat list.
     *
     * Multi-group tasks yield multiple entries (one per group slot). No particular
     * order is guaranteed; task ordering is handled by TaskSorterInterface.
     *
     * @return list<TaskExecution>
     */
    public function all(): array;

    /**
     * Removes all execution records from storage.
     */
    public function reset(): void;
}
