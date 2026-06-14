<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Storage\Dbal;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\DBAL\Exception\TableExistsException;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
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

    /** Comma-separated, platform-quoted column list for SELECT projections. */
    private readonly string $selectColumns;

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

        $this->selectColumns = \implode(', ', [
            $this->quotedIdColumn,
            $this->quotedGroupColumn,
            $this->quotedStatusColumn,
            $this->quotedExecutedAtColumn,
            $this->quotedErrorColumn,
        ]);
    }

    /**
     * Returns a CREATE TABLE SQL string for the current platform, using quoted identifiers.
     *
     * The statement uses the DBAL Schema builder internally for platform-native column
     * types (e.g. DATETIME on SQLite/MySQL, TIMESTAMP on PostgreSQL), then rewrites the
     * unquoted identifiers with their properly-quoted equivalents so the output is safe to
     * fold into a Doctrine migration. Table and column names are quoted via the platform's
     * own quoting rules.
     */
    public function getCreateTableSql(): string
    {
        $sqls = $this->buildSchemaSql();
        $platform = $this->connection->getDatabasePlatform();

        // The Schema builder omits quotes for safe identifiers on some platforms (e.g. SQLite).
        // Replace the unquoted names with their platform-quoted equivalents so the output of
        // --dump-sql is migration-safe and matches the DDL actually executed by createSchema().
        $unquotedToQuoted = [
            $this->configuration->tableName => $this->quotedTable,
            $this->configuration->idColumn => $this->quotedIdColumn,
            $this->configuration->groupColumn => $this->quotedGroupColumn,
            $this->configuration->statusColumn => $this->quotedStatusColumn,
            $this->configuration->executedAtColumn => $this->quotedExecutedAtColumn,
            $this->configuration->errorColumn => $this->quotedErrorColumn,
        ];

        // Only replace whole-word occurrences (word boundary on both sides) to avoid
        // corrupting partial matches (e.g. "id" inside "task_id").
        $result = [];

        foreach ($sqls as $sql) {
            foreach ($unquotedToQuoted as $unquoted => $quoted) {
                if ($unquoted !== $quoted) {
                    $sql = \preg_replace('/\b'.\preg_quote($unquoted, '/').'\b/', $quoted, $sql) ?? $sql;
                }
            }
            $result[] = $sql;
        }

        return \implode(";\n", $result);
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
                    'SELECT %s FROM %s WHERE %s = ? AND %s = ?',
                    $this->selectColumns,
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

        // Normalize to UTC so cross-timezone ORDER BY works on platforms that store
        // wall-clock time without a TZ offset (SQLite, MySQL DATETIME).
        $executedAtUtc = $execution->executedAt->setTimezone(new \DateTimeZone('UTC'));

        $parameters = [
            $execution->id,
            $execution->group ?? '',
            $execution->status->value,
            $executedAtUtc,
            $execution->error,
        ];
        $types = [
            Types::STRING,
            Types::STRING,
            Types::STRING,
            Types::DATETIME_IMMUTABLE,
            Types::TEXT,
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

            $this->connection->executeStatement($sql, $parameters, $types);
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
    public function findByTaskId(string $taskId): array
    {
        $this->ensureInitialized();

        try {
            $rows = $this->connection->fetchAllAssociative(
                \sprintf(
                    'SELECT %s FROM %s WHERE %s = ? ORDER BY %s',
                    $this->selectColumns,
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
                    'SELECT %s FROM %s ORDER BY %s',
                    $this->selectColumns,
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
            // Run any pending auto-create DDL *before* opening the transaction:
            // MySQL/MariaDB DDL implicitly commits, which would silently void an
            // all_or_nothing run's rollback guarantee on first use.
            $this->ensureInitialized();

            return $this->connection->transactional(static fn (Connection $connection): mixed => $callback());
        } catch (DbalException $e) {
            throw new StorageException(\sprintf('Transaction failed: %s', $e->getMessage()), 0, $e);
        }
    }

    /**
     * Creates the storage table if it does not exist (idempotent).
     *
     * @throws DbalException
     */
    public function createSchema(): void
    {
        $schemaManager = $this->connection->createSchemaManager();

        if ($schemaManager->tablesExist([$this->configuration->tableName])) {
            return;
        }

        foreach ($this->buildSchemaSql() as $sql) {
            try {
                $this->connection->executeStatement($sql);
            } catch (TableExistsException) {
                // Lost a create race against a concurrent command — table exists, done.
                return;
            }
        }
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
            $this->createSchema();
        }

        $this->initialized = true;
    }

    /**
     * Builds the CREATE TABLE SQL statements for the current platform using the Schema builder.
     *
     * @return list<string>
     *
     * @throws DbalException
     */
    private function buildSchemaSql(): array
    {
        $schema = new Schema();
        $table = $schema->createTable($this->configuration->tableName);

        $table->addColumn($this->configuration->idColumn, Types::STRING, ['length' => $this->configuration->idColumnLength]);
        $table->addColumn($this->configuration->groupColumn, Types::STRING, ['length' => $this->configuration->groupColumnLength, 'default' => '']);
        $table->addColumn($this->configuration->statusColumn, Types::STRING, ['length' => 16]);
        $table->addColumn($this->configuration->executedAtColumn, Types::DATETIME_IMMUTABLE);
        $table->addColumn($this->configuration->errorColumn, Types::TEXT, ['notnull' => false]);

        /** @var non-empty-string $pkIdColumn */
        $pkIdColumn = $this->configuration->idColumn;
        /** @var non-empty-string $pkGroupColumn */
        $pkGroupColumn = $this->configuration->groupColumn;

        $table->addPrimaryKeyConstraint(
            PrimaryKeyConstraint::editor()
                ->setUnquotedColumnNames($pkIdColumn, $pkGroupColumn)
                ->create(),
        );

        return $schema->toSql($this->connection->getDatabasePlatform());
    }

    /**
     * @param array<string, mixed> $row
     *
     * @throws StorageException
     * @throws DbalException
     */
    private function hydrate(array $row): TaskExecution
    {
        /** @var string $id */
        $id = $row[$this->configuration->idColumn] ?? '';
        /** @var string $statusRaw */
        $statusRaw = $row[$this->configuration->statusColumn] ?? '';
        $executedAtRaw = $row[$this->configuration->executedAtColumn] ?? null;
        /** @var string|null $error */
        $error = $row[$this->configuration->errorColumn] ?? null;
        /** @var string $groupRaw */
        $groupRaw = $row[$this->configuration->groupColumn] ?? '';

        try {
            $executedAtConverted = $this->connection->convertToPHPValue($executedAtRaw, Types::DATETIME_IMMUTABLE);
        } catch (\Doctrine\DBAL\Types\ConversionException $e) {
            throw new StorageException(\sprintf('Invalid executed_at value "%s" in storage row.', \is_scalar($executedAtRaw) ? (string) $executedAtRaw : \gettype($executedAtRaw)), 0, $e);
        }

        if (!$executedAtConverted instanceof \DateTimeImmutable) {
            throw new StorageException(\sprintf('Invalid executed_at value "%s" in storage row.', \is_scalar($executedAtRaw) ? (string) $executedAtRaw : \gettype($executedAtRaw)));
        }

        // DBAL DATETIME_IMMUTABLE stores wall-clock time without a timezone tag. We
        // always persist in UTC (normalised in save()), so we must reinterpret the
        // reconstructed value as UTC rather than re-converting from the PHP system TZ.
        $executedAt = new \DateTimeImmutable($executedAtConverted->format('Y-m-d H:i:s.u'), new \DateTimeZone('UTC'));

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
