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
 *
 * @internal
 */
final class DbalStorage implements TransactionalStorageInterface
{
    private bool $initialized = false;

    public function __construct(
        private readonly Connection $connection,
        private readonly DbalStorageConfiguration $configuration = new DbalStorageConfiguration(),
    ) {
    }

    /**
     * Returns a CREATE TABLE SQL statement compatible with SQLite, MySQL, and PostgreSQL.
     */
    public static function getCreateTableSql(?DbalStorageConfiguration $configuration = null): string
    {
        $configuration ??= new DbalStorageConfiguration();

        return \sprintf(
            'CREATE TABLE IF NOT EXISTS %s (%s VARCHAR(%d) NOT NULL, %s VARCHAR(16) NOT NULL, %s VARCHAR(32) NOT NULL, %s TEXT DEFAULT NULL, PRIMARY KEY (%s))',
            $configuration->tableName,
            $configuration->idColumn,
            $configuration->idColumnLength,
            $configuration->statusColumn,
            $configuration->executedAtColumn,
            $configuration->errorColumn,
            $configuration->idColumn,
        );
    }

    /**
     * Whether an execution record exists for the given task ID.
     */
    public function has(string $taskId): bool
    {
        $this->ensureInitialized();

        try {
            /** @var int|string|false $count */
            $count = $this->connection->fetchOne(
                \sprintf('SELECT COUNT(*) FROM %s WHERE %s = ?', $this->configuration->tableName, $this->configuration->idColumn),
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
        $this->ensureInitialized();

        try {
            $row = $this->connection->fetchAssociative(
                \sprintf('SELECT * FROM %s WHERE %s = ?', $this->configuration->tableName, $this->configuration->idColumn),
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
        $this->ensureInitialized();

        try {
            $this->connection->transactional(function (Connection $connection) use ($execution): void {
                $this->connection->executeStatement(
                    \sprintf('DELETE FROM %s WHERE %s = ?', $this->configuration->tableName, $this->configuration->idColumn),
                    [$execution->id],
                );

                $this->connection->executeStatement(
                    \sprintf(
                        'INSERT INTO %s (%s, %s, %s, %s) VALUES (?, ?, ?, ?)',
                        $this->configuration->tableName,
                        $this->configuration->idColumn,
                        $this->configuration->statusColumn,
                        $this->configuration->executedAtColumn,
                        $this->configuration->errorColumn,
                    ),
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
        $this->ensureInitialized();

        try {
            $this->connection->executeStatement(
                \sprintf('DELETE FROM %s WHERE %s = ?', $this->configuration->tableName, $this->configuration->idColumn),
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
        $this->ensureInitialized();

        try {
            $rows = $this->connection->fetchAllAssociative(
                \sprintf('SELECT * FROM %s ORDER BY %s', $this->configuration->tableName, $this->configuration->executedAtColumn),
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

    /**
     * Removes all execution records from storage.
     */
    public function reset(): void
    {
        $this->ensureInitialized();

        try {
            $this->connection->executeStatement(
                \sprintf('DELETE FROM %s', $this->configuration->tableName),
            );
        } catch (DbalException $e) {
            throw new StorageException(\sprintf('Failed to reset all tasks: %s', $e->getMessage()), 0, $e);
        }
    }

    public function transactional(\Closure $callback): mixed
    {
        try {
            return $this->connection->transactional(static fn (Connection $connection): mixed => $callback());
        } catch (DbalException $e) {
            throw new StorageException(\sprintf('Transaction failed: %s', $e->getMessage()), 0, $e);
        }
    }

    private function ensureInitialized(): void
    {
        if ($this->initialized) {
            return;
        }

        if ($this->configuration->autoCreateTable) {
            $schemaManager = $this->connection->createSchemaManager();

            if (!$schemaManager->tablesExist([$this->configuration->tableName])) {
                $this->connection->executeStatement(self::getCreateTableSql($this->configuration));
            }
        }

        $this->initialized = true;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): TaskExecution
    {
        /** @var string $id */
        $id = $row[$this->configuration->idColumn] ?? '';
        /** @var string $statusRaw */
        $statusRaw = $row[$this->configuration->statusColumn] ?? '';
        /** @var string $executedAtRaw */
        $executedAtRaw = $row[$this->configuration->executedAtColumn] ?? '';
        /** @var string|null $error */
        $error = $row[$this->configuration->errorColumn] ?? null;

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
