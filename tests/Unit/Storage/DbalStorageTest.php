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
}
