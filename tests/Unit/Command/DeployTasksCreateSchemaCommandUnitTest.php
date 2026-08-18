<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Tests\Unit\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Soviann\DeployTasksBundle\Command\DeployTasksCreateSchemaCommand;
use Soviann\DeployTasksBundle\Storage\Dbal\DbalStorageConfiguration;
use Soviann\DeployTasksBundle\Storage\SchemaManageableInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(DeployTasksCreateSchemaCommand::class)]
final class DeployTasksCreateSchemaCommandUnitTest extends TestCase
{
    public function testExecuteWithNullConfigAndNullConnection(): void
    {
        $storage = $this->createMock(SchemaManageableInterface::class);
        $storage->expects(self::once())->method('createSchema');

        $command = new DeployTasksCreateSchemaCommand($storage, null, null);
        $tester = new CommandTester($command);

        $tester->execute([]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        $display = (string) \preg_replace('/\s+/', ' ', $tester->getDisplay());
        self::assertStringContainsString('Storage schema was created.', $display);
    }

    public function testExecuteWithConfigButNullConnection(): void
    {
        $storage = $this->createMock(SchemaManageableInterface::class);
        $storage->expects(self::once())->method('createSchema');

        $config = new DbalStorageConfiguration();
        $command = new DeployTasksCreateSchemaCommand($storage, $config, null);
        $tester = new CommandTester($command);

        $tester->execute([]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        $display = (string) \preg_replace('/\s+/', ' ', $tester->getDisplay());
        self::assertStringContainsString('Storage schema was created.', $display);
        self::assertStringNotContainsString('Storage table', $display);
    }

    public function testExecuteWithNullConfigButConnectionName(): void
    {
        $storage = $this->createMock(SchemaManageableInterface::class);
        $storage->expects(self::once())->method('createSchema');

        $command = new DeployTasksCreateSchemaCommand($storage, null, 'default');
        $tester = new CommandTester($command);

        $tester->execute([]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        $display = (string) \preg_replace('/\s+/', ' ', $tester->getDisplay());
        self::assertStringContainsString('Storage schema was created.', $display);
        self::assertStringNotContainsString('Storage table', $display);
    }

    public function testExecuteWithBothConfigAndConnectionName(): void
    {
        $storage = $this->createMock(SchemaManageableInterface::class);
        $storage->expects(self::once())->method('createSchema');

        $config = new DbalStorageConfiguration();
        $command = new DeployTasksCreateSchemaCommand($storage, $config, 'my_conn');
        $tester = new CommandTester($command);

        $tester->execute([]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        $display = (string) \preg_replace('/\s+/', ' ', $tester->getDisplay());
        self::assertStringContainsString('Storage table "deploy_task_executions"', $display);
        self::assertStringContainsString('was created on my_conn.', $display);
    }

    public function testExecuteDumpSqlOption(): void
    {
        $storage = $this->createMock(SchemaManageableInterface::class);
        $storage->expects(self::never())->method('createSchema');
        $storage->method('getCreateTableSql')->willReturn('CREATE TABLE custom_table (id VARCHAR(255))');

        $command = new DeployTasksCreateSchemaCommand($storage, null, null);
        $tester = new CommandTester($command);

        $tester->execute(['--dump-sql' => true]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertSame("CREATE TABLE custom_table (id VARCHAR(255));\n", $tester->getDisplay());
    }
}
