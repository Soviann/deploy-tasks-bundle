<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Tests\Fixtures;

use Soviann\DeployTasksBundle\Exception\StorageException;
use Soviann\DeployTasksBundle\Storage\TaskExecution;
use Soviann\DeployTasksBundle\Storage\TransactionalStorageInterface;

/**
 * Transactional in-memory storage with database-like rollback semantics.
 *
 * transactional() snapshots the execution records and the $sideEffects register,
 * restoring both when the callback throws — $sideEffects stands in for the domain
 * tables a real task mutates in the same database that holds the records.
 *
 * Probes for runner tests: save() can be armed to fail once ($failNextSave) to
 * simulate a record-persist failure after the task's own work succeeded, and
 * $transactionDepth / $lastSaveInsideTransaction expose where calls happen
 * relative to open transactions.
 */
final class RollbackTransactionalStorageFixture implements TransactionalStorageInterface
{
    /**
     * Task side effects covered by the same transaction as the execution records.
     *
     * @var list<string>
     */
    public array $sideEffects = [];

    /** When true, the next save() call throws a StorageException (one-shot). */
    public bool $failNextSave = false;

    /** Current transactional() nesting depth — probe it during run() or save(). */
    public int $transactionDepth = 0;

    /** Whether the most recent save() happened inside a transactional() closure. */
    public bool $lastSaveInsideTransaction = false;

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
        $this->lastSaveInsideTransaction = $this->transactionDepth > 0;

        if ($this->failNextSave) {
            $this->failNextSave = false;

            throw new StorageException(\sprintf('Simulated failure saving task "%s".', $execution->id));
        }

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

    public function transactional(\Closure $callback): mixed
    {
        $executions = $this->executions;
        $sideEffects = $this->sideEffects;
        ++$this->transactionDepth;

        try {
            return $callback();
        } catch (\Throwable $e) {
            $this->executions = $executions;
            $this->sideEffects = $sideEffects;

            throw $e;
        } finally {
            --$this->transactionDepth;
        }
    }

    private static function key(string $taskId, ?string $group): string
    {
        return $taskId."\0".($group ?? '');
    }
}
