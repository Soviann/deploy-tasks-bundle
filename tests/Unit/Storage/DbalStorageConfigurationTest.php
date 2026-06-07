<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Tests\Unit\Storage;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Soviann\DeployTasksBundle\Storage\Dbal\DbalStorageConfiguration;

#[CoversClass(DbalStorageConfiguration::class)]
final class DbalStorageConfigurationTest extends TestCase
{
    public function testDefaults(): void
    {
        $config = new DbalStorageConfiguration();

        self::assertTrue($config->autoCreateTable);
        self::assertSame('error', $config->errorColumn);
        self::assertSame('executed_at', $config->executedAtColumn);
        self::assertSame('task_group', $config->groupColumn);
        self::assertSame(128, $config->groupColumnLength);
        self::assertSame('id', $config->idColumn);
        self::assertSame(255, $config->idColumnLength);
        self::assertSame('status', $config->statusColumn);
        self::assertSame('deploy_task_executions', $config->tableName);
    }

    public function testCustomValues(): void
    {
        $config = new DbalStorageConfiguration(
            autoCreateTable: false,
            errorColumn: 'err',
            executedAtColumn: 'ran_at',
            idColumn: 'task_id',
            idColumnLength: 128,
            statusColumn: 'task_status',
            tableName: 'custom_tasks',
        );

        self::assertFalse($config->autoCreateTable);
        self::assertSame('err', $config->errorColumn);
        self::assertSame('ran_at', $config->executedAtColumn);
        self::assertSame('task_id', $config->idColumn);
        self::assertSame(128, $config->idColumnLength);
        self::assertSame('task_status', $config->statusColumn);
        self::assertSame('custom_tasks', $config->tableName);
    }
}
