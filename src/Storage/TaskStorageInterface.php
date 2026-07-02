<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Storage;

use Soviann\DeployTasksBundle\Exception\StorageException;

/**
 * Persists task execution records to track which tasks have already run.
 *
 * Records are scoped by a (task id, group) pair. A null group maps to the default slot
 * used when deploytasks:run is called without --group. Passing a non-null group targets
 * a named group slot; a task that belongs to multiple groups has one record per slot.
 *
 * Exception contract: implementations MUST wrap backend failures (I/O, database,
 * serialization, corrupted records) in StorageException — the single failure channel
 * callers may rely on. \InvalidArgumentException is reserved for input-contract
 * violations (e.g. a malformed task id or group name rejected before the backend is
 * touched); it is not a backend failure and MUST NOT be wrapped.
 */
interface TaskStorageInterface
{
    /**
     * Whether an execution record exists for the given task ID and group slot.
     *
     * @throws StorageException When the backend operation fails
     */
    public function has(string $taskId, ?string $group = null): bool;

    /**
     * Returns the execution record for the given task ID and group slot, or null if not found.
     *
     * @throws StorageException When the backend operation fails
     */
    public function get(string $taskId, ?string $group = null): ?TaskExecution;

    /**
     * Saves or updates an execution record. The group slot is read from the execution entity.
     *
     * @throws StorageException When the backend operation fails
     */
    public function save(TaskExecution $execution): void;

    /**
     * Removes the execution record for the given task ID and group slot.
     *
     * Only the matching (id, group) row is deleted; other group slots for the same id are preserved.
     * Use removeAll() to delete every slot for an id.
     *
     * @throws StorageException When the backend operation fails
     */
    public function remove(string $taskId, ?string $group = null): void;

    /**
     * Removes every execution record for the given task ID across all group slots.
     *
     * @throws StorageException When the backend operation fails
     */
    public function removeAll(string $taskId): void;

    /**
     * Returns every execution record for the given task ID across all group slots.
     *
     * Backends with an indexed ID column (DBAL) filter at the storage layer so
     * the runner never has to page through the full execution set just to inspect
     * one task.
     *
     * @return list<TaskExecution>
     *
     * @throws StorageException When the backend operation fails
     */
    public function findByTaskId(string $taskId): array;

    /**
     * Returns all stored execution records as a flat list.
     *
     * Multi-group tasks yield multiple entries (one per group slot). No particular
     * order is guaranteed; task ordering is handled by TaskSorterInterface.
     *
     * @return list<TaskExecution>
     *
     * @throws StorageException When the backend operation fails
     */
    public function all(): array;

    /**
     * Removes all execution records from storage.
     *
     * @throws StorageException When the backend operation fails
     */
    public function reset(): void;
}
