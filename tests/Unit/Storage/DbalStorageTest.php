<?php

declare(strict_types=1);

namespace Soviann\DeployTasks\Tests\Unit\Storage;

use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Soviann\DeployTasks\Contract\TaskExecution;
use Soviann\DeployTasks\Contract\TaskStatus;
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
        $this->connection->executeStatement(DbalStorage::getCreateTableSql($this->configuration));
        $this->storage = new DbalStorage($this->connection, $this->configuration);
    }

    public function testGetCreateTableSql(): void
    {
        $sql = DbalStorage::getCreateTableSql();

        self::assertStringContainsStringIgnoringCase('CREATE TABLE', $sql);
        self::assertStringContainsString('deploy_task_executions', $sql);
        self::assertStringContainsString('id', $sql);
        self::assertStringContainsString('status', $sql);
        self::assertStringContainsString('executed_at', $sql);
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

    public function testAll(): void
    {
        $first = new TaskExecution('task.1', TaskStatus::Ran, new \DateTimeImmutable('2026-04-12T14:30:00+00:00'));
        $second = new TaskExecution('task.2', TaskStatus::Skipped, new \DateTimeImmutable('2026-04-12T15:00:00+00:00'));

        $this->storage->save($first);
        $this->storage->save($second);

        $all = $this->storage->all();

        self::assertCount(2, $all);
        self::assertArrayHasKey('task.1', $all);
        self::assertArrayHasKey('task.2', $all);
        self::assertSame(TaskStatus::Ran, $all['task.1']->status);
        self::assertSame(TaskStatus::Skipped, $all['task.2']->status);
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

        $this->expectException(\Soviann\DeployTasks\Exception\StorageException::class);
        $storage->has('task.1');
    }

    public function testAutoCreateDisabledWorksWhenTableExists(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $config = new DbalStorageConfiguration(autoCreateTable: false);
        $connection->executeStatement(DbalStorage::getCreateTableSql($config));
        $storage = new DbalStorage($connection, $config);

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
        $connection->executeStatement(DbalStorage::getCreateTableSql($config));
        $storage = new DbalStorage($connection, $config);

        $execution = new TaskExecution('task.custom', TaskStatus::Ran, new \DateTimeImmutable());

        $storage->save($execution);
        self::assertTrue($storage->has('task.custom'));

        $retrieved = $storage->get('task.custom');
        self::assertNotNull($retrieved);
        self::assertSame('task.custom', $retrieved->id);
        self::assertSame(TaskStatus::Ran, $retrieved->status);
    }
}
