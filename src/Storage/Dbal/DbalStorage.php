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
    /**
     * Hard byte cap applied to error text before save().
     *
     * The error column is created as a platform CLOB without a length, but a host
     * pointing the bundle at a pre-existing table may well have a MySQL/MariaDB
     * TEXT column — 65,535 bytes, the smallest limit among the supported platforms.
     * Anything longer would either fail the INSERT (strict mode), losing the whole
     * execution record over diagnostic payload, or be truncated mid-byte by the
     * server. Note the contrast with assertKeyLengths(): keys must round-trip
     * exactly so over-long keys are REJECTED, while error text is diagnostic, so
     * losing its tail is acceptable and it is TRUNCATED.
     */
    private const ERROR_MAX_BYTES = 65535;

    /** Appended (within ERROR_MAX_BYTES) so a cut error message is recognizably cut. */
    private const ERROR_TRUNCATION_MARKER = ' [truncated]';

    private readonly string $quotedTable;
    private readonly string $quotedIdColumn;
    private readonly string $quotedGroupColumn;
    private readonly string $quotedStatusColumn;
    private readonly string $quotedExecutedAtColumn;
    private readonly string $quotedErrorColumn;
    private readonly string $quotedDurationColumn;

    /** Comma-separated, platform-quoted column list for SELECT projections. */
    private readonly string $selectColumns;

    /** Platform-specific upsert statement, built once on first save(). */
    private ?string $upsertSql = null;

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
        $this->quotedDurationColumn = $platform->quoteSingleIdentifier($configuration->durationColumn);

        $this->selectColumns = \implode(', ', [
            $this->quotedIdColumn,
            $this->quotedGroupColumn,
            $this->quotedStatusColumn,
            $this->quotedExecutedAtColumn,
            $this->quotedErrorColumn,
            $this->quotedDurationColumn,
        ]);
    }

    /**
     * Returns a CREATE TABLE SQL string for the current platform, using quoted identifiers.
     *
     * The statement uses the DBAL Schema builder internally for platform-native column
     * types (e.g. DATETIME on SQLite/MySQL, TIMESTAMP on PostgreSQL). Every asset name is
     * built as a quoted Doctrine identifier up front, so the platform emits its own
     * correctly-quoted DDL directly — no post-hoc rewriting is needed, and this method
     * emits exactly the statements createSchema() executes.
     *
     * @throws StorageException When the DDL cannot be generated for the connection's platform
     */
    public function getCreateTableSql(): string
    {
        try {
            return \implode(";\n", $this->buildSchemaSql());
        } catch (DbalException $e) {
            throw new StorageException(\sprintf('Failed to generate the CREATE TABLE SQL for storage table "%s": %s', $this->configuration->tableName, $e->getMessage()), 0, $e);
        }
    }

    /**
     * @throws \InvalidArgumentException When the group is the empty string
     * @throws StorageException
     */
    public function has(string $taskId, ?string $group = null): bool
    {
        $this->assertGroupIsNotEmptyString($group);
        $this->assertKeyLengths($taskId, $group);
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
     * @throws \InvalidArgumentException When the group is the empty string
     * @throws StorageException
     */
    public function get(string $taskId, ?string $group = null): ?TaskExecution
    {
        $this->assertGroupIsNotEmptyString($group);
        $this->assertKeyLengths($taskId, $group);
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
     * Error text longer than {@see self::ERROR_MAX_BYTES} is truncated (multibyte-safe,
     * with a marker) before the statement runs — an over-long diagnostic must never
     * cost the execution record itself.
     *
     * @throws \InvalidArgumentException When the execution's group is the empty string
     * @throws StorageException
     */
    public function save(TaskExecution $execution): void
    {
        $this->assertGroupIsNotEmptyString($execution->group);
        $this->assertKeyLengths($execution->id, $execution->group);
        $this->ensureInitialized();

        // Normalize to UTC so cross-timezone ORDER BY works on platforms that store
        // wall-clock time without a TZ offset (SQLite, MySQL DATETIME).
        $executedAtUtc = $execution->executedAt->setTimezone(new \DateTimeZone('UTC'));

        $parameters = [
            $execution->id,
            $execution->group ?? '',
            $execution->status->value,
            $executedAtUtc,
            $this->truncateError($execution->error),
            $execution->durationMs,
        ];
        $types = [
            Types::STRING,
            Types::STRING,
            Types::STRING,
            Types::DATETIME_IMMUTABLE,
            Types::TEXT,
            Types::INTEGER,
        ];

        try {
            $this->upsertSql ??= $this->buildUpsertSql();

            $this->connection->executeStatement($this->upsertSql, $parameters, $types);
        } catch (DbalException $e) {
            throw new StorageException(\sprintf('Failed to save task "%s": %s', $execution->id, $e->getMessage()), 0, $e);
        }
    }

    /**
     * @throws \InvalidArgumentException When the group is the empty string
     * @throws StorageException
     */
    public function remove(string $taskId, ?string $group = null): void
    {
        $this->assertGroupIsNotEmptyString($group);
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
     * Rows come back ordered by the executed-at column (ascending, UTC) — a stable
     * order for display output, but a property of this backend, not of the
     * interface, which guarantees no ordering.
     *
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
     * @throws StorageException When the transaction machinery (begin/commit/rollback) fails;
     *                          exceptions thrown by the callback propagate unchanged
     */
    public function transactional(\Closure $callback): mixed
    {
        /** @var DbalException|null $callbackFailure */
        $callbackFailure = null;

        try {
            // Run any pending auto-create DDL *before* opening the transaction:
            // MySQL/MariaDB DDL implicitly commits, which would silently void an
            // all_or_nothing run's rollback guarantee on first use.
            $this->ensureInitialized();

            return $this->connection->transactional(static function () use ($callback, &$callbackFailure): mixed {
                try {
                    return $callback();
                } catch (DbalException $e) {
                    // Remember the instance so the outer catch can tell the callback's
                    // own database error apart from a transaction-machinery failure.
                    $callbackFailure = $e;

                    throw $e;
                }
            });
        } catch (DbalException $e) {
            if ($e === $callbackFailure) {
                // The task's own database error — propagate unchanged per the
                // TransactionalStorageInterface contract (rollback already happened).
                throw $e;
            }

            throw new StorageException(\sprintf('Transaction failed: %s', $e->getMessage()), 0, $e);
        }
    }

    /**
     * Creates the storage table if it does not exist (idempotent).
     *
     * @throws StorageException When the table cannot be created
     */
    public function createSchema(): void
    {
        try {
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
        } catch (DbalException $e) {
            throw new StorageException(\sprintf('Failed to create storage table "%s": %s', $this->configuration->tableName, $e->getMessage()), 0, $e);
        }
    }

    /**
     * Builds the platform-specific upsert statement — a pure function of the
     * constructor-fixed configuration and connection platform, so save() caches it.
     *
     * @throws StorageException When the platform supports no known upsert dialect
     */
    private function buildUpsertSql(): string
    {
        $id = $this->quotedIdColumn;
        $group = $this->quotedGroupColumn;
        $status = $this->quotedStatusColumn;
        $executedAt = $this->quotedExecutedAtColumn;
        $error = $this->quotedErrorColumn;
        $duration = $this->quotedDurationColumn;

        $columnList = \implode(', ', [$id, $group, $status, $executedAt, $error, $duration]);
        $placeholderList = '?, ?, ?, ?, ?, ?';

        $platform = $this->connection->getDatabasePlatform();

        if ($platform instanceof SQLitePlatform || $platform instanceof PostgreSQLPlatform) {
            return \sprintf(
                'INSERT INTO %s (%s) VALUES (%s) ON CONFLICT (%s, %s) DO UPDATE SET %s',
                $this->quotedTable,
                $columnList,
                $placeholderList,
                $id,
                $group,
                \implode(', ', [
                    \sprintf('%s = excluded.%s', $status, $status),
                    \sprintf('%s = excluded.%s', $executedAt, $executedAt),
                    \sprintf('%s = excluded.%s', $error, $error),
                    \sprintf('%s = excluded.%s', $duration, $duration),
                ]),
            );
        }

        if ($platform instanceof MySQLPlatform || $platform instanceof MariaDBPlatform) {
            return \sprintf(
                'INSERT INTO %s (%s) VALUES (%s) ON DUPLICATE KEY UPDATE %s',
                $this->quotedTable,
                $columnList,
                $placeholderList,
                \implode(', ', [
                    \sprintf('%s = VALUES(%s)', $status, $status),
                    \sprintf('%s = VALUES(%s)', $executedAt, $executedAt),
                    \sprintf('%s = VALUES(%s)', $error, $error),
                    \sprintf('%s = VALUES(%s)', $duration, $duration),
                ]),
            );
        }

        throw new StorageException(\sprintf('Unsupported database platform "%s". Supported: SQLite, PostgreSQL, MySQL, MariaDB.', $platform::class));
    }

    /**
     * @throws StorageException When first-use table auto-creation fails
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
     * Guards get()/has()/save() against keys longer than their configured columns.
     *
     * The compile-time id_column_length check cannot cover tasks whose id only exists
     * at runtime (TaskIdProviderInterface), and a lenient database (e.g. MySQL without
     * STRICT_TRANS_TABLES) silently truncates an over-long value instead of erroring:
     * the stored key then differs from the runtime key, the pending check misses it,
     * and the task re-runs on every deploy. Rejecting before the query keeps truncated
     * keys out of the table — the pending check (get()) fails before the task executes.
     *
     * Lengths are compared in bytes via strlen(), which equals the character count
     * VARCHAR(n) constrains because ids and groups are limited to single-byte ASCII
     * (AsDeployTask::IDENTIFIER_CHAR) — the same convention as the compile-time check.
     *
     * @throws StorageException When the id or group exceeds its configured column length
     */
    private function assertKeyLengths(string $taskId, ?string $group): void
    {
        if (\strlen($taskId) > $this->configuration->idColumnLength) {
            throw new StorageException(\sprintf('Task id "%s" is %d characters, exceeding the configured id_column_length of %d — the database could silently truncate it. Increase soviann_deploy_tasks.storage.database.id_column_length or shorten the task id.', $taskId, \strlen($taskId), $this->configuration->idColumnLength));
        }

        if (null !== $group && \strlen($group) > $this->configuration->groupColumnLength) {
            throw new StorageException(\sprintf('Group name "%s" is %d characters, exceeding the configured group_column_length of %d — the database could silently truncate it. Increase soviann_deploy_tasks.storage.database.group_column_length or shorten the group name.', $group, \strlen($group), $this->configuration->groupColumnLength));
        }
    }

    /**
     * The group column stores '' for the default slot (SQL forbids NULL in a primary
     * key), so an explicit '' from a caller would silently alias the default slot.
     * Rejected as an input-contract violation, matching the other backends (see
     * TaskStorageInterface).
     *
     * @throws \InvalidArgumentException
     */
    private function assertGroupIsNotEmptyString(?string $group): void
    {
        if ('' === $group) {
            throw new \InvalidArgumentException('Group name must not be the empty string; use null to target the default group slot.');
        }
    }

    /**
     * Caps error text at {@see self::ERROR_MAX_BYTES}, cutting on a UTF-8 character
     * boundary (mb_strcut never splits a sequence; symfony/string's mbstring polyfill
     * guarantees its availability) and appending a marker so the cut is visible.
     */
    private function truncateError(?string $error): ?string
    {
        if (null === $error || \strlen($error) <= self::ERROR_MAX_BYTES) {
            return $error;
        }

        return \mb_strcut($error, 0, self::ERROR_MAX_BYTES - \strlen(self::ERROR_TRUNCATION_MARKER), 'UTF-8')
            .self::ERROR_TRUNCATION_MARKER;
    }

    /**
     * Builds the CREATE TABLE SQL statements for the current platform using the Schema builder.
     *
     * Every asset name is wrapped in quotes ("…") before being handed to Doctrine, which
     * marks it as a quoted identifier — the platform then emits its own correctly-quoted
     * DDL for it, safe even for keyword-shaped configured names (e.g. an `order` table or
     * a `default` column, both legal per {@see DbalStorageConfiguration::SQL_IDENTIFIER_PATTERN}).
     *
     * @return list<string>
     *
     * @throws DbalException
     */
    private function buildSchemaSql(): array
    {
        $schema = new Schema();
        $table = $schema->createTable($this->quotedAssetName($this->configuration->tableName));

        $table->addColumn(
            $this->quotedAssetName($this->configuration->idColumn),
            Types::STRING,
            ['length' => $this->configuration->idColumnLength],
        );
        $table->addColumn(
            $this->quotedAssetName($this->configuration->groupColumn),
            Types::STRING,
            ['length' => $this->configuration->groupColumnLength, 'default' => ''],
        );
        $table->addColumn($this->quotedAssetName($this->configuration->statusColumn), Types::STRING, ['length' => 16]);
        $table->addColumn($this->quotedAssetName($this->configuration->executedAtColumn), Types::DATETIME_IMMUTABLE);
        $table->addColumn($this->quotedAssetName($this->configuration->errorColumn), Types::TEXT, ['notnull' => false]);
        $table->addColumn($this->quotedAssetName($this->configuration->durationColumn), Types::INTEGER, ['notnull' => false]);

        /** @var non-empty-string $pkIdColumn */
        $pkIdColumn = $this->configuration->idColumn;
        /** @var non-empty-string $pkGroupColumn */
        $pkGroupColumn = $this->configuration->groupColumn;

        $table->addPrimaryKeyConstraint(
            PrimaryKeyConstraint::editor()
                ->setQuotedColumnNames($pkIdColumn, $pkGroupColumn)
                ->create(),
        );

        return $schema->toSql($this->connection->getDatabasePlatform());
    }

    /** Wraps an identifier in SQL quotes so Doctrine's Schema marks it as a quoted asset. */
    private function quotedAssetName(string $identifier): string
    {
        return '"'.$identifier.'"';
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
        $executedAtRaw = $row[$this->configuration->executedAtColumn] ?? null;
        /** @var string|null $error */
        $error = $row[$this->configuration->errorColumn] ?? null;
        /** @var string $groupRaw */
        $groupRaw = $row[$this->configuration->groupColumn] ?? '';
        /** @var int|numeric-string|null $durationRaw */
        $durationRaw = $row[$this->configuration->durationColumn] ?? null;

        try {
            $executedAtConverted = $this->connection->convertToPHPValue($executedAtRaw, Types::DATETIME_IMMUTABLE);
        } catch (DbalException $e) {
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

        return new TaskExecution(
            id: $id,
            status: TaskStatus::fromStored($statusRaw, $id, $group),
            executedAt: $executedAt,
            error: $error,
            group: $group,
            // Some drivers return integer columns as numeric strings.
            durationMs: null === $durationRaw ? null : (int) $durationRaw,
        );
    }
}
