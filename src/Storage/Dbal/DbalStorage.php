<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Storage\Dbal;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Soviann\DeployTasksBundle\Exception\StorageException;
use Soviann\DeployTasksBundle\Storage\SchemaManageable;
use Soviann\DeployTasksBundle\Storage\TaskExecution;
use Soviann\DeployTasksBundle\Storage\TaskStatus;
use Soviann\DeployTasksBundle\Storage\TransactionalStorageInterface;

/**
 * DBAL-backed task storage — persists execution records in a database table.
 *
 * Records are keyed by a composite primary key (id, task_group) so a task that
 * belongs to multiple groups can have one row per slot. The group column stores
 * the empty string for the default slot because SQL forbids NULL in a primary key.
 *
 * @internal
 */
final class DbalStorage implements SchemaManageable, TransactionalStorageInterface
{
    private readonly string $quotedTable;
    private readonly string $quotedIdColumn;
    private readonly string $quotedGroupColumn;
    private readonly string $quotedStatusColumn;
    private readonly string $quotedExecutedAtColumn;
    private readonly string $quotedErrorColumn;

    private bool $initialized = false;

    /**
     * @throws DbalException When the platform cannot be determined from the connection
     */
    public function __construct(
        private readonly Connection $connection,
        private readonly DbalStorageConfiguration $configuration = new DbalStorageConfiguration(),
    ) {
        $platform = $this->connection->getDatabasePlatform();

        $this->quotedTable = $platform->quoteSingleIdentifier($configuration->tableName);
        $this->quotedIdColumn = $platform->quoteSingleIdentifier($configuration->idColumn);
        $this->quotedGroupColumn = $platform->quoteSingleIdentifier($configuration->groupColumn);
        $this->quotedStatusColumn = $platform->quoteSingleIdentifier($configuration->statusColumn);
        $this->quotedExecutedAtColumn = $platform->quoteSingleIdentifier($configuration->executedAtColumn);
        $this->quotedErrorColumn = $platform->quoteSingleIdentifier($configuration->errorColumn);
    }

    /**
     * Returns a CREATE TABLE SQL statement compatible with SQLite, MySQL, and PostgreSQL.
     */
    public function getCreateTableSql(): string
    {
        return \sprintf(
            'CREATE TABLE IF NOT EXISTS %s (%s VARCHAR(%d) NOT NULL, %s VARCHAR(%d) NOT NULL DEFAULT \'\', %s VARCHAR(16) NOT NULL, %s VARCHAR(32) NOT NULL, %s TEXT DEFAULT NULL, PRIMARY KEY (%s, %s))',
            $this->quotedTable,
            $this->quotedIdColumn,
            $this->configuration->idColumnLength,
            $this->quotedGroupColumn,
            $this->configuration->groupColumnLength,
            $this->quotedStatusColumn,
            $this->quotedExecutedAtColumn,
            $this->quotedErrorColumn,
            $this->quotedIdColumn,
            $this->quotedGroupColumn,
        );
    }

    /**
     * @throws StorageException
     */
    public function has(string $taskId, ?string $group = null): bool
    {
        $this->ensureInitialized();

        try {
            /** @var int|string|false $count */
            $count = $this->connection->fetchOne(
                \sprintf(
                    'SELECT COUNT(*) FROM %s WHERE %s = ? AND %s = ?',
                    $this->quotedTable,
                    $this->quotedIdColumn,
                    $this->quotedGroupColumn,
                ),
                [$taskId, $group ?? ''],
            );

            return false !== $count && (int) $count > 0;
        } catch (DbalException $e) {
            throw new StorageException(\sprintf('Failed to check existence of task "%s": %s', $taskId, $e->getMessage()), 0, $e);
        }
    }

    /**
     * @throws StorageException
     */
    public function get(string $taskId, ?string $group = null): ?TaskExecution
    {
        $this->ensureInitialized();

        try {
            $row = $this->connection->fetchAssociative(
                \sprintf(
                    'SELECT * FROM %s WHERE %s = ? AND %s = ?',
                    $this->quotedTable,
                    $this->quotedIdColumn,
                    $this->quotedGroupColumn,
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

    /**
     * @throws StorageException
     */
    public function save(TaskExecution $execution): void
    {
        $this->ensureInitialized();

        $t = $this->quotedTable;
        $id = $this->quotedIdColumn;
        $group = $this->quotedGroupColumn;
        $status = $this->quotedStatusColumn;
        $executedAt = $this->quotedExecutedAtColumn;
        $error = $this->quotedErrorColumn;

        $columnList = \implode(', ', [$id, $group, $status, $executedAt, $error]);
        $placeholderList = '?, ?, ?, ?, ?';
        $updateAssignments = \implode(', ', [
            \sprintf('%s = excluded.%s', $status, $status),
            \sprintf('%s = excluded.%s', $executedAt, $executedAt),
            \sprintf('%s = excluded.%s', $error, $error),
        ]);
        $updateAssignmentsMysql = \implode(', ', [
            \sprintf('%s = VALUES(%s)', $status, $status),
            \sprintf('%s = VALUES(%s)', $executedAt, $executedAt),
            \sprintf('%s = VALUES(%s)', $error, $error),
        ]);

        $parameters = [
            $execution->id,
            $execution->group ?? '',
            $execution->status->value,
            $execution->executedAt->format(\DateTimeInterface::ATOM),
            $execution->error,
        ];

        try {
            $platform = $this->connection->getDatabasePlatform();

            if ($platform instanceof SQLitePlatform || $platform instanceof PostgreSQLPlatform) {
                $sql = \sprintf(
                    'INSERT INTO %s (%s) VALUES (%s) ON CONFLICT (%s, %s) DO UPDATE SET %s',
                    $t,
                    $columnList,
                    $placeholderList,
                    $id,
                    $group,
                    $updateAssignments,
                );
            } elseif ($platform instanceof MySQLPlatform || $platform instanceof MariaDBPlatform) {
                $sql = \sprintf(
                    'INSERT INTO %s (%s) VALUES (%s) ON DUPLICATE KEY UPDATE %s',
                    $t,
                    $columnList,
                    $placeholderList,
                    $updateAssignmentsMysql,
                );
            } else {
                throw new StorageException(\sprintf('Unsupported database platform "%s". Supported: SQLite, PostgreSQL, MySQL, MariaDB.', $platform::class));
            }

            $this->connection->executeStatement($sql, $parameters);
        } catch (StorageException $e) {
            throw $e;
        } catch (DbalException $e) {
            throw new StorageException(\sprintf('Failed to save task "%s": %s', $execution->id, $e->getMessage()), 0, $e);
        }
    }

    /**
     * @throws StorageException
     */
    public function remove(string $taskId, ?string $group = null): void
    {
        $this->ensureInitialized();

        try {
            $this->connection->executeStatement(
                \sprintf(
                    'DELETE FROM %s WHERE %s = ? AND %s = ?',
                    $this->quotedTable,
                    $this->quotedIdColumn,
                    $this->quotedGroupColumn,
                ),
                [$taskId, $group ?? ''],
            );
        } catch (DbalException $e) {
            throw new StorageException(\sprintf('Failed to remove task "%s": %s', $taskId, $e->getMessage()), 0, $e);
        }
    }

    /**
     * @throws StorageException
     */
    public function removeAll(string $taskId): void
    {
        $this->ensureInitialized();

        try {
            $this->connection->executeStatement(
                \sprintf(
                    'DELETE FROM %s WHERE %s = ?',
                    $this->quotedTable,
                    $this->quotedIdColumn,
                ),
                [$taskId],
            );
        } catch (DbalException $e) {
            throw new StorageException(\sprintf('Failed to remove task "%s": %s', $taskId, $e->getMessage()), 0, $e);
        }
    }

    /**
     * @return list<TaskExecution>
     *
     * @throws StorageException
     */
    public function findByTaskId(string $taskId): iterable
    {
        $this->ensureInitialized();

        try {
            $rows = $this->connection->fetchAllAssociative(
                \sprintf(
                    'SELECT * FROM %s WHERE %s = ? ORDER BY %s',
                    $this->quotedTable,
                    $this->quotedIdColumn,
                    $this->quotedExecutedAtColumn,
                ),
                [$taskId],
            );
        } catch (DbalException $e) {
            throw new StorageException(\sprintf('Failed to fetch task "%s": %s', $taskId, $e->getMessage()), 0, $e);
        }

        $executions = [];

        foreach ($rows as $row) {
            $executions[] = $this->hydrate($row);
        }

        return $executions;
    }

    /**
     * @return list<TaskExecution>
     *
     * @throws StorageException
     */
    public function all(): array
    {
        $this->ensureInitialized();

        try {
            $rows = $this->connection->fetchAllAssociative(
                \sprintf(
                    'SELECT * FROM %s ORDER BY %s',
                    $this->quotedTable,
                    $this->quotedExecutedAtColumn,
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

    /**
     * @throws StorageException
     */
    public function reset(): void
    {
        $this->ensureInitialized();

        try {
            $this->connection->executeStatement(
                \sprintf('DELETE FROM %s', $this->quotedTable),
            );
        } catch (DbalException $e) {
            throw new StorageException(\sprintf('Failed to reset all tasks: %s', $e->getMessage()), 0, $e);
        }
    }

    /**
     * @throws StorageException
     */
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
     *
     * @throws DbalException
     */
    public function createSchema(): void
    {
        $this->connection->executeStatement($this->getCreateTableSql());
    }

    /**
     * @throws DbalException
     */
    private function ensureInitialized(): void
    {
        if ($this->initialized) {
            return;
        }

        if ($this->configuration->autoCreateTable) {
            $this->connection->executeStatement($this->getCreateTableSql());
        }

        $this->initialized = true;
    }

    /**
     * @param array<string, mixed> $row
     *
     * @throws StorageException
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
