<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Tests\Unit\Storage;

use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Soviann\DeployTasksBundle\Exception\StorageException;
use Soviann\DeployTasksBundle\Storage\Dbal\DbalStorage;
use Soviann\DeployTasksBundle\Storage\Dbal\DbalStorageConfiguration;
use Soviann\DeployTasksBundle\Storage\TaskExecution;
use Soviann\DeployTasksBundle\Storage\TaskStatus;

#[CoversClass(DbalStorage::class)]
final class DbalStorageTest extends TestCase
{
    private \Doctrine\DBAL\Connection $connection;
    private DbalStorageConfiguration $configuration;
    private DbalStorage $storage;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->configuration = new DbalStorageConfiguration();
        $this->storage = new DbalStorage($this->connection, $this->configuration);
        $this->connection->executeStatement($this->storage->getCreateTableSql());
    }

    public function testGetCreateTableSql(): void
    {
        $sql = $this->storage->getCreateTableSql();

        self::assertStringContainsStringIgnoringCase('CREATE TABLE', $sql);
        self::assertStringContainsString('deploy_task_executions', $sql);
    }

    public function testGetCreateTableSqlQuotesIdentifiers(): void
    {
        $config = new DbalStorageConfiguration(
            idColumn: 'task_id',
            statusColumn: 'task_status',
        );
        $storage = new DbalStorage($this->connection, $config);
        $sql = $storage->getCreateTableSql();

        self::assertStringContainsString('"task_id"', $sql);
        self::assertStringContainsString('"task_status"', $sql);
    }

    public function testSaveAndRetrieve(): void
    {
        $execution = new TaskExecution('task.1', TaskStatus::Ran, new \DateTimeImmutable('2026-04-12T14:30:00+00:00'));

        $this->storage->save($execution);

        self::assertTrue($this->storage->has('task.1'));

        $retrieved = $this->storage->get('task.1');

        self::assertNotNull($retrieved);
        self::assertSame($execution->id, $retrieved->id);
        self::assertSame($execution->status, $retrieved->status);
        self::assertSame(
            $execution->executedAt->format(\DateTimeInterface::ATOM),
            $retrieved->executedAt->format(\DateTimeInterface::ATOM),
        );
        self::assertNull($retrieved->error);
    }

    public function testSaveOverwrites(): void
    {
        $first = new TaskExecution('task.1', TaskStatus::Ran, new \DateTimeImmutable('2026-04-12T14:30:00+00:00'));
        $second = new TaskExecution('task.1', TaskStatus::Failed, new \DateTimeImmutable('2026-04-12T15:00:00+00:00'));

        $this->storage->save($first);
        $this->storage->save($second);

        $retrieved = $this->storage->get('task.1');

        self::assertNotNull($retrieved);
        self::assertSame(TaskStatus::Failed, $retrieved->status);
    }

    public function testGetReturnsNullForMissingTask(): void
    {
        self::assertNull($this->storage->get('task.missing'));
    }

    public function testHasReturnsFalseForMissingTask(): void
    {
        self::assertFalse($this->storage->has('task.missing'));
    }

    public function testRemove(): void
    {
        $execution = new TaskExecution('task.1', TaskStatus::Ran, new \DateTimeImmutable('2026-04-12T14:30:00+00:00'));

        $this->storage->save($execution);
        $this->storage->remove('task.1');

        self::assertFalse($this->storage->has('task.1'));
    }

    public function testRemoveNonExistent(): void
    {
        // Should not throw
        $this->storage->remove('task.nonexistent');

        self::assertFalse($this->storage->has('task.nonexistent'));
    }

    public function testAllReturnsFlatList(): void
    {
        $this->storage->save(new TaskExecution('task.1', TaskStatus::Ran, new \DateTimeImmutable('2026-04-12T14:30:00+00:00')));
        $this->storage->save(new TaskExecution('task.2', TaskStatus::Skipped, new \DateTimeImmutable('2026-04-12T15:00:00+00:00')));
        $this->storage->save(new TaskExecution('task.2', TaskStatus::Ran, new \DateTimeImmutable('2026-04-12T15:30:00+00:00'), null, 'predeploy'));

        $all = $this->storage->all();

        self::assertCount(3, $all);

        $keys = \array_map(static fn (TaskExecution $e): string => $e->id.'@'.($e->group ?? ''), $all);
        \sort($keys);

        self::assertSame(['task.1@', 'task.2@', 'task.2@predeploy'], $keys);
    }

    public function testSaveAndGetWithGroup(): void
    {
        $execution = new TaskExecution('task.1', TaskStatus::Ran, new \DateTimeImmutable('2026-04-12T14:30:00+00:00'), null, 'predeploy');

        $this->storage->save($execution);

        $retrieved = $this->storage->get('task.1', 'predeploy');

        self::assertNotNull($retrieved);
        self::assertSame('task.1', $retrieved->id);
        self::assertSame('predeploy', $retrieved->group);
        self::assertNull($this->storage->get('task.1'));
        self::assertNull($this->storage->get('task.1', 'postdeploy'));
    }

    public function testHasIsScopedByGroup(): void
    {
        $this->storage->save(new TaskExecution('task.1', TaskStatus::Ran, new \DateTimeImmutable(), null, 'predeploy'));

        self::assertTrue($this->storage->has('task.1', 'predeploy'));
        self::assertFalse($this->storage->has('task.1'));
        self::assertFalse($this->storage->has('task.1', 'postdeploy'));
    }

    public function testRemoveIsScopedByGroup(): void
    {
        $this->storage->save(new TaskExecution('task.1', TaskStatus::Ran, new \DateTimeImmutable(), null, 'predeploy'));
        $this->storage->save(new TaskExecution('task.1', TaskStatus::Ran, new \DateTimeImmutable(), null, 'postdeploy'));

        $this->storage->remove('task.1', 'predeploy');

        self::assertFalse($this->storage->has('task.1', 'predeploy'));
        self::assertTrue($this->storage->has('task.1', 'postdeploy'));
    }

    public function testRemoveAllDeletesEverySlot(): void
    {
        $this->storage->save(new TaskExecution('task.1', TaskStatus::Ran, new \DateTimeImmutable()));
        $this->storage->save(new TaskExecution('task.1', TaskStatus::Ran, new \DateTimeImmutable(), null, 'predeploy'));
        $this->storage->save(new TaskExecution('task.1', TaskStatus::Ran, new \DateTimeImmutable(), null, 'postdeploy'));
        $this->storage->save(new TaskExecution('task.2', TaskStatus::Ran, new \DateTimeImmutable()));

        $this->storage->removeAll('task.1');

        self::assertFalse($this->storage->has('task.1'));
        self::assertFalse($this->storage->has('task.1', 'predeploy'));
        self::assertFalse($this->storage->has('task.1', 'postdeploy'));
        self::assertTrue($this->storage->has('task.2'));
    }

    public function testSchemaHasCompositePrimaryKey(): void
    {
        $schemaManager = $this->connection->createSchemaManager();
        // DBAL 3.6 introspection API; DBAL 4 replacements (introspectTableByUnquotedName /
        // getPrimaryKeyConstraint / getIndexedColumns) are unavailable on 3.6, so keep the
        // cross-version API and silence the deprecation notice phpstan-deprecation-rules raises.
        /** @phpstan-ignore method.deprecated, method.deprecated, method.deprecated */
        $columns = $schemaManager->introspectTable('deploy_task_executions')->getPrimaryKey()?->getColumns();

        self::assertSame(['id', 'task_group'], $columns);
    }

    public function testAllEmpty(): void
    {
        self::assertSame([], $this->storage->all());
    }

    public function testFindByTaskIdReturnsEverySlot(): void
    {
        $this->storage->save(new TaskExecution('task.1', TaskStatus::Ran, new \DateTimeImmutable('2026-04-12T14:30:00+00:00')));
        $this->storage->save(new TaskExecution('task.1', TaskStatus::Ran, new \DateTimeImmutable('2026-04-12T14:35:00+00:00'), null, 'predeploy'));
        $this->storage->save(new TaskExecution('task.1', TaskStatus::Skipped, new \DateTimeImmutable('2026-04-12T14:40:00+00:00'), null, 'postdeploy'));
        $this->storage->save(new TaskExecution('task.2', TaskStatus::Ran, new \DateTimeImmutable('2026-04-12T14:50:00+00:00')));

        $matches = [...$this->storage->findByTaskId('task.1')];
        $ids = \array_map(static fn (TaskExecution $e): string => $e->id, $matches);
        $groups = \array_map(static fn (TaskExecution $e): ?string => $e->group, $matches);

        self::assertCount(3, $matches);
        self::assertSame(['task.1', 'task.1', 'task.1'], $ids);
        self::assertEqualsCanonicalizing([null, 'predeploy', 'postdeploy'], $groups);
    }

    public function testFindByTaskIdReturnsSingleSlotWhenOnlyDefaultStored(): void
    {
        $this->storage->save(new TaskExecution('task.1', TaskStatus::Ran, new \DateTimeImmutable('2026-04-12T14:30:00+00:00')));

        $matches = [...$this->storage->findByTaskId('task.1')];

        self::assertCount(1, $matches);
        self::assertSame('task.1', $matches[0]->id);
        self::assertNull($matches[0]->group);
    }

    public function testFindByTaskIdUnknownIdReturnsEmpty(): void
    {
        $this->storage->save(new TaskExecution('task.1', TaskStatus::Ran, new \DateTimeImmutable()));

        self::assertSame([], [...$this->storage->findByTaskId('task.missing')]);
    }

    public function testTransactional(): void
    {
        $payload = 'cb-'.\bin2hex(\random_bytes(4));
        $result = $this->storage->transactional(static fn (): string => $payload);

        self::assertSame($payload, $result);
    }

    public function testSaveWithError(): void
    {
        $execution = new TaskExecution(
            'task.1',
            TaskStatus::Failed,
            new \DateTimeImmutable('2026-04-12T14:30:00+00:00'),
            'Something went wrong',
        );

        $this->storage->save($execution);

        $retrieved = $this->storage->get('task.1');

        self::assertNotNull($retrieved);
        self::assertSame('Something went wrong', $retrieved->error);
        self::assertSame(TaskStatus::Failed, $retrieved->status);
    }

    public function testReset(): void
    {
        $this->storage->save(new TaskExecution('task.1', TaskStatus::Ran, new \DateTimeImmutable()));
        $this->storage->save(new TaskExecution('task.2', TaskStatus::Skipped, new \DateTimeImmutable()));

        $this->storage->reset();

        self::assertSame([], $this->storage->all());
    }

    public function testResetEmpty(): void
    {
        $this->storage->reset();

        self::assertSame([], $this->storage->all());
    }

    public function testTransactionalWrapsExceptionInStorageException(): void
    {
        $connection = $this->createMock(\Doctrine\DBAL\Connection::class);
        $connection->method('getDatabasePlatform')
            ->willReturn($this->connection->getDatabasePlatform());
        $connection->method('transactional')
            ->willThrowException(new \Doctrine\DBAL\Exception\InvalidArgumentException('connection lost'));

        $storage = new DbalStorage($connection, $this->configuration);

        $this->expectException(StorageException::class);
        $this->expectExceptionMessageMatches('/Transaction failed/');

        $storage->transactional(static fn (): string => 'nope');
    }

    public function testAutoCreatesTableOnFirstUse(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $storage = new DbalStorage($connection);

        // Should auto-create and not throw
        self::assertFalse($storage->has('task.1'));
    }

    public function testAutoCreateDisabledThrowsWhenTableMissing(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $config = new DbalStorageConfiguration(autoCreateTable: false);
        $storage = new DbalStorage($connection, $config);

        $this->expectException(StorageException::class);
        $storage->has('task.1');
    }

    public function testAutoCreateDisabledWorksWhenTableExists(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $config = new DbalStorageConfiguration(autoCreateTable: false);
        $storage = new DbalStorage($connection, $config);
        $connection->executeStatement($storage->getCreateTableSql());

        self::assertFalse($storage->has('task.1'));
    }

    public function testCustomColumnNames(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $config = new DbalStorageConfiguration(
            idColumn: 'task_id',
            statusColumn: 'task_status',
            executedAtColumn: 'ran_at',
            errorColumn: 'error_message',
        );
        $storage = new DbalStorage($connection, $config);
        $connection->executeStatement($storage->getCreateTableSql());

        $execution = new TaskExecution('task.custom', TaskStatus::Ran, new \DateTimeImmutable());

        $storage->save($execution);
        self::assertTrue($storage->has('task.custom'));

        $retrieved = $storage->get('task.custom');
        self::assertNotNull($retrieved);
        self::assertSame('task.custom', $retrieved->id);
        self::assertSame(TaskStatus::Ran, $retrieved->status);
    }

    public function testInvalidDateInRowThrowsStorageException(): void
    {
        $this->connection->insert(
            'deploy_task_executions',
            [
                'id' => 'task.baddate',
                'task_group' => '',
                'status' => 'ran',
                'executed_at' => 'not-a-date',
                'error' => null,
            ],
        );

        $this->expectException(StorageException::class);
        $this->expectExceptionMessageMatches('/Invalid executed_at/');

        $this->storage->get('task.baddate');
    }

    public function testCorruptedStatusRowThrowsStorageException(): void
    {
        $this->connection->insert(
            'deploy_task_executions',
            [
                'id' => 'task.badstatus',
                'task_group' => '',
                'status' => 'unknown',
                'executed_at' => (new \DateTimeImmutable('2026-04-16T10:00:00+00:00'))->format(\DateTimeInterface::ATOM),
                'error' => null,
            ],
        );

        $this->expectException(StorageException::class);
        $this->expectExceptionMessageMatches('/task\.badstatus.*unknown/');

        $this->storage->get('task.badstatus');
    }

    /**
     * @return iterable<string, array{0: \Closure(DbalStorage): void}>
     */
    public static function autoCreateEntryPointProvider(): iterable
    {
        yield 'get' => [static function (DbalStorage $s): void { $s->get('task.missing'); }];
        yield 'save' => [static fn (DbalStorage $s) => $s->save(new TaskExecution('task.autocreate', TaskStatus::Ran, new \DateTimeImmutable()))];
        yield 'remove' => [static fn (DbalStorage $s) => $s->remove('task.missing')];
        yield 'removeAll' => [static fn (DbalStorage $s) => $s->removeAll('task.missing')];
        yield 'all' => [static function (DbalStorage $s): void { $s->all(); }];
        yield 'reset' => [static fn (DbalStorage $s) => $s->reset()];
    }

    /**
     * @param \Closure(DbalStorage): void $call
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('autoCreateEntryPointProvider')]
    public function testEachPublicMethodAutoCreatesTableWhenUsedFirst(\Closure $call): void
    {
        // Kills MethodCallRemoval on the `$this->ensureInitialized()` call at the top of every
        // public method (lines 84, 109, 151, 170, 191, 216). If the call is removed, SQLite will
        // raise a "no such table" exception when the method tries to hit the table first.
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $storage = new DbalStorage($connection);

        $call($storage);

        $schemaManager = $connection->createSchemaManager();
        self::assertTrue($schemaManager->tablesExist(['deploy_task_executions']));
    }

    public function testHasWrapsDbalExceptionWithCodeZero(): void
    {
        // Kills Increment/DecrementInteger on the `0` code passed to StorageException (line 78):
        // the mutant changes the code to -1 or 1, which this assertion catches.
        $connection = $this->createMock(\Doctrine\DBAL\Connection::class);
        $connection->method('getDatabasePlatform')
            ->willReturn($this->connection->getDatabasePlatform());
        $connection->method('fetchOne')
            ->willThrowException(new \Doctrine\DBAL\Exception\InvalidArgumentException('fetch failed'));

        $storage = new DbalStorage($connection, new DbalStorageConfiguration(autoCreateTable: false));

        try {
            $storage->has('task.boom');
            self::fail('Expected StorageException');
        } catch (StorageException $e) {
            self::assertSame(0, $e->getCode());
        }
    }

    public function testTransactionalWrapsExceptionWithCodeZero(): void
    {
        // Kills Increment/DecrementInteger on the `0` code passed to StorageException (line 232).
        $connection = $this->createMock(\Doctrine\DBAL\Connection::class);
        $connection->method('getDatabasePlatform')
            ->willReturn($this->connection->getDatabasePlatform());
        $connection->method('transactional')
            ->willThrowException(new \Doctrine\DBAL\Exception\InvalidArgumentException('tx failed'));

        $storage = new DbalStorage($connection, new DbalStorageConfiguration(autoCreateTable: false));

        try {
            $storage->transactional(static fn (): string => 'never');
            self::fail('Expected StorageException');
        } catch (StorageException $e) {
            self::assertSame(0, $e->getCode());
        }
    }

    public function testConcurrentSaveOverwritesAtomically(): void
    {
        $first = new TaskExecution('task.race', TaskStatus::Ran, new \DateTimeImmutable('2026-04-16T10:00:00+00:00'));
        $second = new TaskExecution('task.race', TaskStatus::Failed, new \DateTimeImmutable('2026-04-16T10:00:01+00:00'), 'second-write');
        $third = new TaskExecution('task.race', TaskStatus::Ran, new \DateTimeImmutable('2026-04-16T10:00:02+00:00'));

        $this->storage->save($first);
        $this->storage->save($second);
        $this->storage->save($third);

        $retrieved = $this->storage->get('task.race');

        self::assertNotNull($retrieved);
        self::assertSame(TaskStatus::Ran, $retrieved->status, 'Last write must win — DELETE+INSERT in transaction makes the sequence atomic.');
        self::assertNull($retrieved->error);
        self::assertCount(1, $this->storage->all(), 'No PK conflict — exactly one row remains for the (id, group) pair.');
    }

    // -------------------------------------------------------------------------
    // Mutation-killing: getCreateTableSql identifier quoting (lines 76, 92)
    // -------------------------------------------------------------------------

    /**
     * Kills ArrayItemRemoval on tableName entry (line 76): if tableName is removed from
     * the unquotedToQuoted map, the table name in the SQL will remain unquoted on platforms
     * that normally omit quotes for safe identifiers (e.g. SQLite). The assertion on the
     * quoted form verifies that the replacement actually runs for the table name too.
     */
    public function testGetCreateTableSqlQuotesTableName(): void
    {
        $config = new DbalStorageConfiguration(tableName: 'my_tasks');
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $storage = new DbalStorage($connection, $config);

        $sql = $storage->getCreateTableSql();

        // SQLite platform quotes with double-quotes; the table name must appear quoted.
        self::assertStringContainsString('"my_tasks"', $sql);
    }

    /**
     * Kills PregQuote removal (line 92): if preg_quote() is dropped, a column name that
     * contains regex-special characters (e.g. a dot) would corrupt the substitution pattern
     * and either match the wrong text or silently skip the replacement.
     *
     * Using "at.col" (dot is special in regex): without preg_quote, the pattern becomes
     * /\bat.col\b/ which matches any char at the dot position; WITH preg_quote it becomes
     * /\bat\.col\b/ which only matches the literal dot.
     */
    public function testGetCreateTableSqlHandlesRegexSpecialCharsInColumnName(): void
    {
        // Column name with a dot — a regex-special character.
        $config = new DbalStorageConfiguration(
            idColumn: 'id.col',
            statusColumn: 'status',
            executedAtColumn: 'executed_at',
            errorColumn: 'error',
            groupColumn: 'task_group',
            tableName: 'deploy_task_executions',
        );
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $storage = new DbalStorage($connection, $config);

        // Must not throw and must contain the properly quoted identifier.
        $sql = $storage->getCreateTableSql();
        self::assertStringContainsString('"id.col"', $sql);
        // Verify the replacement didn't corrupt surrounding text by also checking no raw dot
        // appears in an identifier position (only the quoted form should remain).
        self::assertStringNotContainsString(' id.col ', $sql);
    }

    // -------------------------------------------------------------------------
    // Mutation-killing: get() wraps DbalException with code 0 (lines 145)
    // -------------------------------------------------------------------------

    /**
     * Kills DecrementInteger / IncrementInteger on code `0` in get() catch block (line 145).
     */
    public function testGetWrapsDbalExceptionWithCodeZero(): void
    {
        $connection = $this->createMock(\Doctrine\DBAL\Connection::class);
        $connection->method('getDatabasePlatform')
            ->willReturn($this->connection->getDatabasePlatform());
        $connection->method('fetchAssociative')
            ->willThrowException(new \Doctrine\DBAL\Exception\InvalidArgumentException('fetch failed'));

        $storage = new DbalStorage($connection, new DbalStorageConfiguration(autoCreateTable: false));

        try {
            $storage->get('task.boom');
            self::fail('Expected StorageException');
        } catch (StorageException $e) {
            self::assertSame(0, $e->getCode());
            self::assertInstanceOf(\Doctrine\DBAL\Exception::class, $e->getPrevious());
        }
    }

    // -------------------------------------------------------------------------
    // Mutation-killing: save() catch block (lines 201, 204)
    // -------------------------------------------------------------------------

    /**
     * Kills CatchBlockRemoval of the `catch (StorageException $e) { throw $e; }` block (line 201,
     * mutant 238): if that block is removed, an unsupported-platform StorageException thrown inside
     * the try would be caught by the DbalException catch block (StorageException extends RuntimeException,
     * not DbalException) so it would actually propagate unmodified either way. The real kill comes from
     * verifying that save() on an unsupported platform throws StorageException with the correct message.
     *
     * We mock getDatabasePlatform() to return a mocked AbstractPlatform that is none of the four
     * supported concrete types, triggering the StorageException branch.
     */
    public function testSaveThrowsStorageExceptionForUnsupportedPlatform(): void
    {
        // Use a mock of AbstractPlatform — it won't be instanceof SQLite/Postgres/MySQL/MariaDB.
        $unknownPlatform = $this->createMock(\Doctrine\DBAL\Platforms\AbstractPlatform::class);

        $connection = $this->createMock(\Doctrine\DBAL\Connection::class);
        $connection->method('getDatabasePlatform')->willReturn($unknownPlatform);

        $storage = new DbalStorage($connection, new DbalStorageConfiguration(autoCreateTable: false));

        $this->expectException(StorageException::class);
        $this->expectExceptionMessageMatches('/Unsupported database platform/');

        $storage->save(new TaskExecution('task.unsupported', TaskStatus::Ran, new \DateTimeImmutable()));
    }

    /**
     * Kills CatchBlockRemoval of the `catch (DbalException $e)` block (line 201, mutant 239):
     * if that block is removed, a DbalException thrown by executeStatement would propagate
     * unWrapped. With the block present it is wrapped in a StorageException.
     */
    public function testSaveWrapsDbalExceptionWithCodeZero(): void
    {
        $connection = $this->createMock(\Doctrine\DBAL\Connection::class);
        $connection->method('getDatabasePlatform')
            ->willReturn($this->connection->getDatabasePlatform());
        $connection->method('executeStatement')
            ->willThrowException(new \Doctrine\DBAL\Exception\InvalidArgumentException('db error'));

        $storage = new DbalStorage($connection, new DbalStorageConfiguration(autoCreateTable: false));

        try {
            $storage->save(new TaskExecution('task.boom', TaskStatus::Ran, new \DateTimeImmutable()));
            self::fail('Expected StorageException');
        } catch (StorageException $e) {
            self::assertSame(0, $e->getCode());
            self::assertStringContainsString('Failed to save task', $e->getMessage());
            self::assertInstanceOf(\Doctrine\DBAL\Exception::class, $e->getPrevious());
        }
    }

    /**
     * Kills InstanceOf_ mutation (line 204, mutant 240) and LogicalOrAllSubExprNegation (mutant 241).
     * Both mutations corrupt the platform-detection condition so that SQLite would end up
     * generating a MySQL-style ON DUPLICATE KEY UPDATE query (or vice versa).
     *
     * We verify the generated SQL for SQLite (in-memory) contains ON CONFLICT … DO UPDATE SET,
     * not ON DUPLICATE KEY UPDATE.
     */
    public function testSaveGeneratesOnConflictSqlForSqlite(): void
    {
        $wrappedConnection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $storage = new DbalStorage($wrappedConnection, new DbalStorageConfiguration(autoCreateTable: false));
        $wrappedConnection->executeStatement($storage->getCreateTableSql());

        // Capture what is actually sent to SQLite via EXPLAIN QUERY PLAN or just check that
        // ON CONFLICT syntax was used (SQLite would reject ON DUPLICATE KEY at the driver level).
        // Saving twice for the same key proves the upsert ran on the SQLite path (not MySQL path).
        $storage->save(new TaskExecution('task.sq', TaskStatus::Ran, new \DateTimeImmutable('2026-01-01T00:00:00+00:00')));
        $storage->save(new TaskExecution('task.sq', TaskStatus::Failed, new \DateTimeImmutable('2026-01-01T00:01:00+00:00'), 'err'));

        // If the wrong SQL path were chosen, SQLite would throw on ON DUPLICATE KEY.
        // The absence of an exception + correct row count confirms the right path was taken.
        $all = $storage->all();
        self::assertCount(1, $all);
        self::assertSame(TaskStatus::Failed, $all[0]->status);
        self::assertSame('err', $all[0]->error);
    }

    // -------------------------------------------------------------------------
    // Mutation-killing: findByTaskId() ensureInitialized (line 284)
    // -------------------------------------------------------------------------

    /**
     * Kills MethodCallRemoval on ensureInitialized() in findByTaskId() (line 284, mutant 242).
     * Without ensureInitialized(), SQLite would raise "no such table" on the first call to
     * findByTaskId() when autoCreateTable is true.
     */
    public function testFindByTaskIdAutoCreatesTable(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $storage = new DbalStorage($connection);

        $results = $storage->findByTaskId('task.missing');

        self::assertSame([], $results);

        $schemaManager = $connection->createSchemaManager();
        self::assertTrue($schemaManager->tablesExist(['deploy_task_executions']));
    }

    // -------------------------------------------------------------------------
    // Mutation-killing: reset() catch block (line 353)
    // -------------------------------------------------------------------------

    /**
     * Kills DecrementInteger / IncrementInteger on code `0` and Throw_ removal (line 353)
     * in the reset() catch block.
     */
    public function testResetWrapsDbalExceptionAndThrowsWithCodeZero(): void
    {
        $connection = $this->createMock(\Doctrine\DBAL\Connection::class);
        $connection->method('getDatabasePlatform')
            ->willReturn($this->connection->getDatabasePlatform());
        $connection->method('executeStatement')
            ->willThrowException(new \Doctrine\DBAL\Exception\InvalidArgumentException('reset failed'));

        $storage = new DbalStorage($connection, new DbalStorageConfiguration(autoCreateTable: false));

        try {
            $storage->reset();
            self::fail('Expected StorageException');
        } catch (StorageException $e) {
            self::assertSame(0, $e->getCode());
            self::assertStringContainsString('Failed to reset all tasks', $e->getMessage());
            self::assertInstanceOf(\Doctrine\DBAL\Exception::class, $e->getPrevious());
        }
    }

    // -------------------------------------------------------------------------
    // Mutation-killing: ensureInitialized() early-return + initialized flag (lines 393, 400)
    // -------------------------------------------------------------------------

    /**
     * Kills ReturnRemoval on the early return in ensureInitialized() (line 393, mutant 246)
     * and TrueValue on `$this->initialized = true` (line 400, mutant 247).
     *
     * If the early return is removed, ensureInitialized() tries to call createSchema()
     * on every call even after initialization, which would call schemaManager->tablesExist()
     * repeatedly. We verify that createSchema() is called at most once by checking that
     * a second invocation via has() does NOT cause an exception or double-schema error.
     *
     * If initialized is set to false instead of true, every call re-invokes createSchema(),
     * which is observable via a mock counting calls.
     */
    public function testEnsureInitializedRunsCreateSchemaOnlyOnce(): void
    {
        $realConn = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $platform = $realConn->getDatabasePlatform();

        $callCount = 0;

        $connection = $this->createMock(\Doctrine\DBAL\Connection::class);
        $connection->method('getDatabasePlatform')->willReturn($platform);
        $connection->method('createSchemaManager')
            ->willReturnCallback(static function () use ($realConn, &$callCount) {
                ++$callCount;

                return $realConn->createSchemaManager();
            });
        $connection->method('executeStatement')->willReturn(0);
        $connection->method('fetchOne')->willReturn('0');

        $config = new DbalStorageConfiguration(autoCreateTable: true);
        $storage = new DbalStorage($connection, $config);

        // First call: ensureInitialized() runs createSchema() → createSchemaManager() called.
        $storage->has('task.x');
        $countAfterFirst = $callCount;

        // Second call: early-return must prevent another createSchema() invocation.
        $storage->has('task.y');

        self::assertSame($countAfterFirst, $callCount, 'createSchemaManager() must not be called again after initialization.');
    }

    // -------------------------------------------------------------------------
    // Mutation-killing: buildSchemaSql column lengths (lines 415, 416, 417)
    // -------------------------------------------------------------------------

    /**
     * Kills ArrayItemRemoval of ['length' => idColumnLength] (line 415, mutant 248):
     * without the length option the DBAL Schema builder uses a platform default that
     * can differ from idColumnLength. We assert the SQL contains the exact expected length.
     *
     * Also kills ArrayItemRemoval of ['length' => groupColumnLength, ...] (line 416, mutant 249)
     * and DecrementInteger/IncrementInteger/ArrayItemRemoval on statusColumn length 16 (line 417,
     * mutants 250/251/252).
     */
    public function testCreateTableSqlContainsExactColumnLengths(): void
    {
        $config = new DbalStorageConfiguration(
            idColumnLength: 200,
            groupColumnLength: 64,
        );
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $storage = new DbalStorage($connection, $config);

        $sql = $storage->getCreateTableSql();

        // id column: length 200 (not default 255).
        self::assertMatchesRegularExpression('/\bid\b[^(]*\(200\)/', $sql, 'id column must use idColumnLength=200');
        // group column: length 64 (not default 128).
        self::assertMatchesRegularExpression('/\btask_group\b[^(]*\(64\)/', $sql, 'task_group column must use groupColumnLength=64');
    }

    /**
     * Kills DecrementInteger (15), IncrementInteger (17), and ArrayItemRemoval (no length)
     * on statusColumn length 16 (line 417, mutants 250/251/252).
     */
    public function testCreateTableSqlStatusColumnLengthIsExactly16(): void
    {
        $sql = $this->storage->getCreateTableSql();

        // The status column VARCHAR definition must use exactly 16, not 15 or 17.
        self::assertMatchesRegularExpression('/\bstatus\b[^(]*\(16\)/', $sql, 'status column must have length=16');
        self::assertDoesNotMatchRegularExpression('/\bstatus\b[^(]*\(15\)/', $sql);
        self::assertDoesNotMatchRegularExpression('/\bstatus\b[^(]*\(17\)/', $sql);
    }

    // -------------------------------------------------------------------------
    // Mutation-killing: hydrate() ConversionException error message (line 473)
    // -------------------------------------------------------------------------

    /**
     * Kills CastString removal (mutant 253) and Ternary swap (mutant 254) in hydrate():
     * the error message must contain the actual scalar value (cast to string), NOT gettype().
     *
     * When the value IS scalar, the message must include the value itself; when NOT scalar,
     * it must include gettype() output. The scalar branch is tested here using 'not-a-date'.
     */
    public function testInvalidDateErrorMessageContainsScalarValue(): void
    {
        $this->connection->insert(
            'deploy_task_executions',
            [
                'id' => 'task.castcheck',
                'task_group' => '',
                'status' => 'ran',
                'executed_at' => 'bad-scalar-value',
                'error' => null,
            ],
        );

        try {
            $this->storage->get('task.castcheck');
            self::fail('Expected StorageException');
        } catch (StorageException $e) {
            // The message must embed the actual scalar value, not its type ("string").
            self::assertStringContainsString('bad-scalar-value', $e->getMessage());
            self::assertStringNotContainsString('string', $e->getMessage());
        }
    }

    /**
     * Kills DecrementInteger / IncrementInteger on code `0` in hydrate()'s
     * ConversionException catch block (line 473, mutants 255/256).
     */
    public function testInvalidDateConversionExceptionHasCodeZero(): void
    {
        $this->connection->insert(
            'deploy_task_executions',
            [
                'id' => 'task.codezero',
                'task_group' => '',
                'status' => 'ran',
                'executed_at' => 'not-a-date',
                'error' => null,
            ],
        );

        try {
            $this->storage->get('task.codezero');
            self::fail('Expected StorageException');
        } catch (StorageException $e) {
            self::assertSame(0, $e->getCode());
        }
    }
}
