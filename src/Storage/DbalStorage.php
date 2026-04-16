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
 * Records are keyed by a composite primary key (id, task_group) so a task that
 * belongs to multiple groups can have one row per slot. The group column stores
 * the empty string for the default slot because SQL forbids NULL in a primary key.
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
    public function getCreateTableSql(): string
    {
        $t = $this->quoteIdentifier($this->configuration->tableName);
        $id = $this->quoteIdentifier($this->configuration->idColumn);
        $group = $this->quoteIdentifier($this->configuration->groupColumn);
        $status = $this->quoteIdentifier($this->configuration->statusColumn);
        $executedAt = $this->quoteIdentifier($this->configuration->executedAtColumn);
        $error = $this->quoteIdentifier($this->configuration->errorColumn);

        return \sprintf(
            'CREATE TABLE IF NOT EXISTS %s (%s VARCHAR(%d) NOT NULL, %s VARCHAR(%d) NOT NULL DEFAULT \'\', %s VARCHAR(16) NOT NULL, %s VARCHAR(32) NOT NULL, %s TEXT DEFAULT NULL, PRIMARY KEY (%s, %s))',
            $t,
            $id,
            $this->configuration->idColumnLength,
            $group,
            $this->configuration->groupColumnLength,
            $status,
            $executedAt,
            $error,
            $id,
            $group,
        );
    }

    public function has(string $taskId, ?string $group = null): bool
    {
        $this->ensureInitialized();

        try {
            /** @var int|string|false $count */
            $count = $this->connection->fetchOne(
                \sprintf(
                    'SELECT COUNT(*) FROM %s WHERE %s = ? AND %s = ?',
                    $this->quoteIdentifier($this->configuration->tableName),
                    $this->quoteIdentifier($this->configuration->idColumn),
                    $this->quoteIdentifier($this->configuration->groupColumn),
                ),
                [$taskId, $group ?? ''],
            );

            return false !== $count && (int) $count > 0;
        } catch (DbalException $e) {
            throw new StorageException(\sprintf('Failed to check existence of task "%s": %s', $taskId, $e->getMessage()), 0, $e);
        }
    }

    public function get(string $taskId, ?string $group = null): ?TaskExecution
    {
        $this->ensureInitialized();

        try {
            $row = $this->connection->fetchAssociative(
                \sprintf(
                    'SELECT * FROM %s WHERE %s = ? AND %s = ?',
                    $this->quoteIdentifier($this->configuration->tableName),
                    $this->quoteIdentifier($this->configuration->idColumn),
                    $this->quoteIdentifier($this->configuration->groupColumn),
                ),
                [$taskId, $group ?? ''],
            );
        } catch (DbalException $e) {
            throw new StorageException(\sprintf('Failed to fetch task "%s": %s', $taskId, $e->getMessage()), 0, $e);
        }

        if (false === $row) {
            return null;
        }

        return $this->hydrate($row);
    }

    public function save(TaskExecution $execution): void
    {
        $this->ensureInitialized();

        $t = $this->quoteIdentifier($this->configuration->tableName);
        $id = $this->quoteIdentifier($this->configuration->idColumn);
        $group = $this->quoteIdentifier($this->configuration->groupColumn);
        $status = $this->quoteIdentifier($this->configuration->statusColumn);
        $executedAt = $this->quoteIdentifier($this->configuration->executedAtColumn);
        $error = $this->quoteIdentifier($this->configuration->errorColumn);

        try {
            $this->connection->transactional(static function (Connection $connection) use ($execution, $t, $id, $group, $status, $executedAt, $error): void {
                $connection->executeStatement(
                    \sprintf('DELETE FROM %s WHERE %s = ? AND %s = ?', $t, $id, $group),
                    [$execution->id, $execution->group ?? ''],
                );

                $connection->executeStatement(
                    \sprintf(
                        'INSERT INTO %s (%s, %s, %s, %s, %s) VALUES (?, ?, ?, ?, ?)',
                        $t,
                        $id,
                        $group,
                        $status,
                        $executedAt,
                        $error,
                    ),
                    [
                        $execution->id,
                        $execution->group ?? '',
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

    public function remove(string $taskId, ?string $group = null): void
    {
        $this->ensureInitialized();

        try {
            $this->connection->executeStatement(
                \sprintf(
                    'DELETE FROM %s WHERE %s = ? AND %s = ?',
                    $this->quoteIdentifier($this->configuration->tableName),
                    $this->quoteIdentifier($this->configuration->idColumn),
                    $this->quoteIdentifier($this->configuration->groupColumn),
                ),
                [$taskId, $group ?? ''],
            );
        } catch (DbalException $e) {
            throw new StorageException(\sprintf('Failed to remove task "%s": %s', $taskId, $e->getMessage()), 0, $e);
        }
    }

    public function removeAll(string $taskId): void
    {
        $this->ensureInitialized();

        try {
            $this->connection->executeStatement(
                \sprintf(
                    'DELETE FROM %s WHERE %s = ?',
                    $this->quoteIdentifier($this->configuration->tableName),
                    $this->quoteIdentifier($this->configuration->idColumn),
                ),
                [$taskId],
            );
        } catch (DbalException $e) {
            throw new StorageException(\sprintf('Failed to remove task "%s": %s', $taskId, $e->getMessage()), 0, $e);
        }
    }

    /**
     * @return list<TaskExecution>
     */
    public function all(): array
    {
        $this->ensureInitialized();

        try {
            $rows = $this->connection->fetchAllAssociative(
                \sprintf(
                    'SELECT * FROM %s ORDER BY %s',
                    $this->quoteIdentifier($this->configuration->tableName),
                    $this->quoteIdentifier($this->configuration->executedAtColumn),
                ),
            );
        } catch (DbalException $e) {
            throw new StorageException(\sprintf('Failed to fetch all tasks: %s', $e->getMessage()), 0, $e);
        }

        $executions = [];

        foreach ($rows as $row) {
            $executions[] = $this->hydrate($row);
        }

        return $executions;
    }

    public function reset(): void
    {
        $this->ensureInitialized();

        try {
            $this->connection->executeStatement(
                \sprintf('DELETE FROM %s', $this->quoteIdentifier($this->configuration->tableName)),
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

    /**
     * Creates the storage table if it does not exist.
     */
    public function createSchema(): void
    {
        $this->connection->executeStatement($this->getCreateTableSql());
    }

    private function quoteIdentifier(string $identifier): string
    {
        return $this->connection->quoteSingleIdentifier($identifier);
    }

    private function ensureInitialized(): void
    {
        if ($this->initialized) {
            return;
        }

        if ($this->configuration->autoCreateTable) {
            $schemaManager = $this->connection->createSchemaManager();

            if (!$schemaManager->tablesExist([$this->configuration->tableName])) {
                $this->connection->executeStatement($this->getCreateTableSql());
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
        /** @var string $groupRaw */
        $groupRaw = $row[$this->configuration->groupColumn] ?? '';

        $executedAt = \DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $executedAtRaw);

        if (false === $executedAt) {
            throw new StorageException(\sprintf('Invalid executed_at value "%s" in storage row.', $executedAtRaw));
        }

        $group = '' === $groupRaw ? null : $groupRaw;

        try {
            $status = TaskStatus::from($statusRaw);
        } catch (\ValueError $e) {
            throw StorageException::corruptedRow($id, $group, $statusRaw, $e);
        }

        return new TaskExecution(
            id: $id,
            status: $status,
            executedAt: $executedAt,
            error: $error,
            group: $group,
        );
    }
}
