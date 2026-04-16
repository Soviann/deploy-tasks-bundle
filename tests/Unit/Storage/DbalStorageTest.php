<?php

declare(strict_types=1);

namespace Soviann\DeployTasks\Tests\Unit\Storage;

use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Soviann\DeployTasks\Contract\TaskExecution;
use Soviann\DeployTasks\Contract\TaskStatus;
use Soviann\DeployTasks\Exception\StorageException;
use Soviann\DeployTasks\Storage\DbalStorage;
use Soviann\DeployTasks\Storage\DbalStorageConfiguration;

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
        $table = $schemaManager->introspectTable('deploy_task_executions');
        $primaryKey = $table->getPrimaryKey();

        self::assertNotNull($primaryKey);
        self::assertSame(['id', 'task_group'], $primaryKey->getColumns());
    }

    public function testAllEmpty(): void
    {
        self::assertSame([], $this->storage->all());
    }

    public function testTransactional(): void
    {
        $result = $this->storage->transactional(static function (): string {
            return 'callback-result';
        });

        self::assertSame('callback-result', $result);
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
}
