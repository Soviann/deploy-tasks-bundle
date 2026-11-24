<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Storage\Dbal;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Exception\TableExistsException;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Soviann\DeployTasksBundle\Exception\StorageException;
use Soviann\DeployTasksBundle\Storage\SchemaManageableInterface;
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
final class DbalStorage implements SchemaManageableInterface, TransactionalStorageInterface
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

    /**
     * MySQL/MariaDB ER_TRUNCATED_WRONG_VALUE_FOR_FIELD ("Incorrect string value"):
     * raised in strict mode when a string cannot be represented in the connection or
     * column character set — typically a 4-byte UTF-8 character (emoji, U+1D11E…)
     * sent over a `utf8` (utf8mb3) connection.
     */
    private const MYSQL_INCORRECT_STRING_VALUE = 1366;

    /**
     * Replaces each 4-byte character when the server rejects them: U+FFFD (the
     * Unicode replacement character) is 3 bytes, so it fits utf8mb3 and still marks
     * the spot of the lost character.
     */
    private const FOUR_BYTE_REPLACEMENT = "\u{FFFD}";

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

    private readonly LoggerInterface $logger;

    /**
     * @throws DbalException When the platform cannot be determined from the connection
     */
    public function __construct(
        private readonly Connection $connection,
        private readonly DbalStorageConfiguration $configuration = new DbalStorageConfiguration(),
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
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
     * When a MySQL/MariaDB server rejects the error text with error 1366 (4-byte
     * characters over a utf8mb3 connection or column), the write is retried once with
     * every 4-byte character replaced by U+FFFD, and a warning is logged: the
     * failure record survives with a slightly degraded message. Any other failure,
     * or a failing retry, propagates.
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

            try {
                $this->connection->executeStatement($this->upsertSql, $parameters, $types);
            } catch (DriverException $e) {
                $sanitizedError = $this->replaceRejectedFourByteCharacters($e, $parameters[4]);

                if (null === $sanitizedError) {
                    throw $e;
                }

                $parameters[4] = $sanitizedError;
                $this->connection->executeStatement($this->upsertSql, $parameters, $types);

                $this->logger->warning(
                    'The database rejected 4-byte UTF-8 characters in the error message of a deploy task (MySQL/MariaDB error 1366): they were stored as U+FFFD. Use a utf8mb4 connection charset to keep them.',
                    ['task_id' => $execution->id, 'group' => $execution->group],
                );
            }
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
     * Returns the error text with its 4-byte characters replaced when $e is a
     * MySQL/MariaDB "Incorrect string value" (1366) rejection that replacing them can
     * cure; null when the failure must propagate unchanged: another error code,
     * another platform, no error text, or no 4-byte character in it (the rejection
     * then concerns something else, e.g. a latin1 column, and a retry would fail
     * the same way).
     *
     * @throws DbalException When the platform cannot be determined from the connection
     */
    private function replaceRejectedFourByteCharacters(DriverException $e, mixed $error): ?string
    {
        if (!\is_string($error)
            || self::MYSQL_INCORRECT_STRING_VALUE !== $e->getCode()
            || !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform
        ) {
            return null;
        }

        $sanitized = \preg_replace('/[\x{10000}-\x{10FFFF}]/u', self::FOUR_BYTE_REPLACEMENT, $error);

        return null === $sanitized || $sanitized === $error ? null : $sanitized;
    }

    /**
     * Builds the CREATE TABLE SQL statements for the current platform, through the
     * DBAL 4.3+ schema editors when installed, the DBAL 3 Table API otherwise.
     *
     * Mutants here are equivalent by design: both builders emit identical DDL
     * (pinned by DbalSchemaParityTest), so forcing either path changes no output.
     *
     * @return list<string>
     *
     * @throws DbalException
     *
     * @infection-ignore-all
     */
    private function buildSchemaSql(): array
    {
        return \method_exists(Table::class, 'editor')
            ? $this->buildEditorSchemaSql()
            : $this->buildLegacySchemaSql();
    }

    /**
     * Builds the CREATE TABLE SQL statements with the DBAL 4.3+ schema editors.
     *
     * Every asset name is marked as quoted so the platform emits its own correctly-quoted
     * DDL for it, safe even for keyword-shaped configured names (e.g. an `order` table or
     * a `default` column, both legal per {@see DbalStorageConfiguration::SQL_IDENTIFIER_PATTERN}).
     *
     * @return list<string>
     *
     * @throws DbalException
     */
    private function buildEditorSchemaSql(): array
    {
        /** @var non-empty-string $tableName */
        $tableName = $this->configuration->tableName;
        /** @var non-empty-string $idColumn */
        $idColumn = $this->configuration->idColumn;
        /** @var non-empty-string $groupColumn */
        $groupColumn = $this->configuration->groupColumn;
        /** @var non-empty-string $statusColumn */
        $statusColumn = $this->configuration->statusColumn;
        /** @var non-empty-string $executedAtColumn */
        $executedAtColumn = $this->configuration->executedAtColumn;
        /** @var non-empty-string $errorColumn */
        $errorColumn = $this->configuration->errorColumn;
        /** @var non-empty-string $durationColumn */
        $durationColumn = $this->configuration->durationColumn;

        $table = Table::editor()
            ->setQuotedName($tableName)
            ->addColumn(
                Column::editor()
                    ->setQuotedName($idColumn)
                    ->setTypeName(Types::STRING)
                    ->setLength($this->configuration->idColumnLength)
                    ->create(),
            )
            ->addColumn(
                Column::editor()
                    ->setQuotedName($groupColumn)
                    ->setTypeName(Types::STRING)
                    ->setLength($this->configuration->groupColumnLength)
                    ->setDefaultValue('')
                    ->create(),
            )
            ->addColumn(
                Column::editor()
                    ->setQuotedName($statusColumn)
                    ->setTypeName(Types::STRING)
                    ->setLength(16)
                    ->create(),
            )
            ->addColumn(
                Column::editor()
                    ->setQuotedName($executedAtColumn)
                    ->setTypeName(Types::DATETIME_IMMUTABLE)
                    ->create(),
            )
            ->addColumn(
                Column::editor()
                    ->setQuotedName($errorColumn)
                    ->setTypeName(Types::TEXT)
                    ->setNotNull(false)
                    ->create(),
            )
            ->addColumn(
                Column::editor()
                    ->setQuotedName($durationColumn)
                    ->setTypeName(Types::INTEGER)
                    ->setNotNull(false)
                    ->create(),
            )
            ->addPrimaryKeyConstraint(
                PrimaryKeyConstraint::editor()
                    ->setQuotedColumnNames($idColumn, $groupColumn)
                    ->create(),
            )
            ->create();

        $schema = new Schema([$table]);

        return $schema->toSql($this->connection->getDatabasePlatform());
    }

    /**
     * DBAL 3.x counterpart of buildEditorSchemaSql(), for platforms without the
     * 4.3 schema editors: the same table, columns, defaults and composite primary key,
     * built through the mutable Table API.
     *
     * Every asset name is wrapped in double quotes, which DBAL 3 parses as a quoted
     * identifier and re-emits with the platform's own quoting — the equivalent of
     * the editors' setQuotedName(). Safe because configured names are constrained to
     * {@see DbalStorageConfiguration::SQL_IDENTIFIER_PATTERN} (no quote characters).
     *
     * Production only reaches it on DBAL < 4.3; DbalSchemaParityTest also runs it on
     * DBAL 4.3+ and pins its DDL to the editors' reference output, platform by platform.
     *
     * @return list<string>
     *
     * @throws DbalException
     */
    private function buildLegacySchemaSql(): array
    {
        $config = $this->configuration;
        $quote = static fn (string $name): string => '"'.$name.'"';

        $table = new Table($quote($config->tableName));
        $table->addColumn($quote($config->idColumn), Types::STRING, ['length' => $config->idColumnLength]);
        $table->addColumn($quote($config->groupColumn), Types::STRING, ['length' => $config->groupColumnLength, 'default' => '']);
        $table->addColumn($quote($config->statusColumn), Types::STRING, ['length' => 16]);
        // DATETIME_MUTABLE: same SQL type as the editors' DATETIME_IMMUTABLE, without the
        // "(DC2Type:datetime_immutable)" comment hint DBAL 3 adds for immutable types.
        // Binding/hydration still use DATETIME_IMMUTABLE (save()/hydrate()).
        $table->addColumn($quote($config->executedAtColumn), Types::DATETIME_MUTABLE);
        $table->addColumn($quote($config->errorColumn), Types::TEXT, ['notnull' => false]);
        $table->addColumn($quote($config->durationColumn), Types::INTEGER, ['notnull' => false]);
        $table->setPrimaryKey([$quote($config->idColumn), $quote($config->groupColumn)]);
        // An explicit (empty) table_options stops DBAL 3's MySQL/MariaDB platforms from
        // injecting `DEFAULT CHARACTER SET utf8 COLLATE utf8_unicode_ci ENGINE = InnoDB`
        // (3-byte utf8: a 4-byte character in an error message would then fail the
        // INSERT). DBAL 4 adds no table options either: the server defaults apply.
        $table->addOption('table_options', '');

        return (new Schema([$table]))->toSql($this->connection->getDatabasePlatform());
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
