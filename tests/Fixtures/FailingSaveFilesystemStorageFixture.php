<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Tests\Fixtures;

use Soviann\DeployTasksBundle\Exception\StorageException;
use Soviann\DeployTasksBundle\Storage\Filesystem\FilesystemStorage;
use Soviann\DeployTasksBundle\Storage\TaskExecution;
use Soviann\DeployTasksBundle\Storage\TaskStorageInterface;

/**
 * Filesystem-backed storage whose save() can be armed to fail after a number of
 * successful writes — simulates a mid-loop I/O failure (disk full, permission
 * change) on a backend with no transaction support.
 *
 * Deliberately implements only TaskStorageInterface, never
 * TransactionalStorageInterface: commands must take their non-transactional
 * code path with this storage.
 */
final class FailingSaveFilesystemStorageFixture implements TaskStorageInterface
{
    /**
     * Countdown of save() calls that still succeed once armed; the call that
     * finds it at zero throws a StorageException (one-shot). Negative = disarmed
     * (default), every save() succeeds.
     */
    public int $savesUntilFailure = -1;

    private readonly FilesystemStorage $inner;

    public function __construct(string $storagePath)
    {
        $this->inner = new FilesystemStorage($storagePath);
    }

    public function has(string $taskId, ?string $group = null): bool
    {
        return $this->inner->has($taskId, $group);
    }

    public function get(string $taskId, ?string $group = null): ?TaskExecution
    {
        return $this->inner->get($taskId, $group);
    }

    public function save(TaskExecution $execution): void
    {
        if (0 === $this->savesUntilFailure) {
            $this->savesUntilFailure = -1;

            throw new StorageException(\sprintf('Simulated I/O failure saving task "%s".', $execution->id));
        }

        if ($this->savesUntilFailure > 0) {
            --$this->savesUntilFailure;
        }

        $this->inner->save($execution);
    }

    public function remove(string $taskId, ?string $group = null): void
    {
        $this->inner->remove($taskId, $group);
    }

    public function removeAll(string $taskId): void
    {
        $this->inner->removeAll($taskId);
    }

    /**
     * @return list<TaskExecution>
     */
    public function findByTaskId(string $taskId): array
    {
        return $this->inner->findByTaskId($taskId);
    }

    /**
     * @return list<TaskExecution>
     */
    public function all(): array
    {
        return $this->inner->all();
    }

    public function reset(): void
    {
        $this->inner->reset();
    }
}
