<?php

declare(strict_types=1);

namespace Soviann\DeployTasks\Storage;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Soviann\DeployTasks\Contract\TaskExecution;
use Soviann\DeployTasks\Contract\TaskStatus;
use Soviann\DeployTasks\Contract\TransactionalStorageInterface;
use Soviann\DeployTasks\Exception\StorageException;

/**
 * DBAL-backed task storage — persists execution records in a database table.
 */
final class DbalStorage implements TransactionalStorageInterface
{
    /**
     * @param Connection $connection DBAL connection
     * @param string     $tableName  Target table name
     */
    public function __construct(
        private readonly Connection $connection,
        private readonly string $tableName = 'deploy_task_executions',
    ) {
    }

    /**
     * Returns a CREATE TABLE SQL statement compatible with SQLite, MySQL, and PostgreSQL.
     */
    public static function getCreateTableSql(string $tableName = 'deploy_task_executions'): string
    {
        return \sprintf(
            'CREATE TABLE IF NOT EXISTS %s (id VARCHAR(255) NOT NULL, status VARCHAR(16) NOT NULL, executed_at VARCHAR(32) NOT NULL, error TEXT DEFAULT NULL, PRIMARY KEY (id))',
            $tableName,
        );
    }

    /**
     * Whether an execution record exists for the given task ID.
     */
    public function has(string $taskId): bool
    {
        try {
            /** @var int|string|false $count */
            $count = $this->connection->fetchOne(
                \sprintf('SELECT COUNT(*) FROM %s WHERE id = ?', $this->tableName),
                [$taskId],
            );

            return false !== $count && (int) $count > 0;
        } catch (DbalException $e) {
            throw new StorageException(\sprintf('Failed to check existence of task "%s": %s', $taskId, $e->getMessage()), 0, $e);
        }
    }

    /**
     * Returns the execution record for the given task ID, or null if not found.
     */
    public function get(string $taskId): ?TaskExecution
    {
        try {
            $row = $this->connection->fetchAssociative(
                \sprintf('SELECT * FROM %s WHERE id = ?', $this->tableName),
                [$taskId],
            );
        } catch (DbalException $e) {
            throw new StorageException(\sprintf('Failed to fetch task "%s": %s', $taskId, $e->getMessage()), 0, $e);
        }

        if (false === $row) {
            return null;
        }

        return $this->hydrate($row);
    }

    /**
     * Saves or updates an execution record.
     */
    public function save(TaskExecution $execution): void
    {
        try {
            $this->connection->transactional(function (Connection $connection) use ($execution): void {
                $this->connection->executeStatement(
                    \sprintf('DELETE FROM %s WHERE id = ?', $this->tableName),
                    [$execution->id],
                );

                $this->connection->executeStatement(
                    \sprintf('INSERT INTO %s (id, status, executed_at, error) VALUES (?, ?, ?, ?)', $this->tableName),
                    [
                        $execution->id,
                        $execution->status->value,
                        $execution->executedAt->format(\DateTimeInterface::ATOM),
                        $execution->error,
                    ],
                );
            });
        } catch (DbalException $e) {
            throw new StorageException(\sprintf('Failed to save task "%s": %s', $execution->id, $e->getMessage()), 0, $e);
        }
    }

    /**
     * Removes the execution record for the given task ID.
     */
    public function remove(string $taskId): void
    {
        try {
            $this->connection->executeStatement(
                \sprintf('DELETE FROM %s WHERE id = ?', $this->tableName),
                [$taskId],
            );
        } catch (DbalException $e) {
            throw new StorageException(\sprintf('Failed to remove task "%s": %s', $taskId, $e->getMessage()), 0, $e);
        }
    }

    /**
     * Returns all stored execution records, keyed by task ID.
     *
     * @return array<string, TaskExecution>
     */
    public function all(): array
    {
        try {
            $rows = $this->connection->fetchAllAssociative(
                \sprintf('SELECT * FROM %s ORDER BY executed_at', $this->tableName),
            );
        } catch (DbalException $e) {
            throw new StorageException(\sprintf('Failed to fetch all tasks: %s', $e->getMessage()), 0, $e);
        }

        $executions = [];

        foreach ($rows as $row) {
            $execution = $this->hydrate($row);
            $executions[$execution->id] = $execution;
        }

        return $executions;
    }

    public function transactional(\Closure $callback): mixed
    {
        return $this->connection->transactional(static fn (Connection $connection): mixed => $callback());
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): TaskExecution
    {
        /** @var string $id */
        $id = $row['id'] ?? '';
        /** @var string $statusRaw */
        $statusRaw = $row['status'] ?? '';
        /** @var string $executedAtRaw */
        $executedAtRaw = $row['executed_at'] ?? '';
        /** @var string|null $error */
        $error = $row['error'] ?? null;

        $executedAt = \DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $executedAtRaw);

        if (false === $executedAt) {
            throw new StorageException(\sprintf('Invalid executed_at value "%s" in storage row.', $executedAtRaw));
        }

        return new TaskExecution(
            id: $id,
            status: TaskStatus::from($statusRaw),
            executedAt: $executedAt,
            error: $error,
        );
    }
}
