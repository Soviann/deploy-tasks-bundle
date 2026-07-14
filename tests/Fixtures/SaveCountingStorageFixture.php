<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Tests\Fixtures;

use Soviann\DeployTasksBundle\Storage\InMemory\InMemoryStorage;
use Soviann\DeployTasksBundle\Storage\TaskExecution;
use Soviann\DeployTasksBundle\Storage\TaskStorageInterface;

/**
 * Decorates a {@see TaskStorageInterface} to count save() invocations —
 * used to prove a slot is persisted exactly once, e.g. when a group is
 * requested more than once in the same run.
 */
final class SaveCountingStorageFixture implements TaskStorageInterface
{
    public int $saveCalls = 0;

    public function __construct(private readonly TaskStorageInterface $inner = new InMemoryStorage())
    {
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
        ++$this->saveCalls;
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
