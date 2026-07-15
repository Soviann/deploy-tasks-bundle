<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Tests\Functional;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Soviann\DeployTasksBundle\Storage\InMemory\InMemoryStorage;
use Soviann\DeployTasksBundle\Tests\Fixtures\SimpleTask;
use Soviann\DeployTasksBundle\Tests\Fixtures\TransactionalTask;

/**
 * ConfigurableTestKernel fragments shared by several test classes.
 * Configs used by a single test class stay inline in that class.
 *
 * @phpstan-import-type ServiceSpec from ConfigurableTestKernel
 */
final class KernelConfig
{
    private function __construct()
    {
    }

    /**
     * Factory for the `doctrine.dbal.default_connection` test service.
     */
    public static function createConnection(): Connection
    {
        return DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
    }

    /**
     * @return ServiceSpec
     */
    public static function sqliteConnection(): array
    {
        return [
            'class' => Connection::class,
            'factory' => [self::class, 'createConnection'],
            'public' => true,
        ];
    }

    /**
     * Extension config of the canonical database-storage scenario
     * (in-memory sqlite, per-task transactions — the mode where the
     * TransactionalTask fixture's attribute flag is honored).
     *
     * @return array<string, mixed>
     */
    public static function dbalExtension(): array
    {
        return [
            'storage' => [
                'type' => 'database',
                'database' => [
                    'connection' => 'default',
                    'table' => 'deploy_task_executions',
                    'transaction_mode' => 'per_task',
                ],
            ],
            'events' => ['enabled' => false],
            'lock' => ['enabled' => false],
        ];
    }

    /**
     * Services of the canonical database-storage scenario: the sqlite
     * connection plus a simple and a transactional task.
     *
     * @return array<string, ServiceSpec>
     */
    public static function dbalServices(): array
    {
        return [
            'doctrine.dbal.default_connection' => self::sqliteConnection(),
            'test.task.simple' => [
                'class' => SimpleTask::class,
                'args' => ['test.simple', 'A simple test task'],
                'tags' => ['soviann_deploy_tasks.task'],
            ],
            'test.task.transactional' => [
                'class' => TransactionalTask::class,
                'tags' => ['soviann_deploy_tasks.task'],
            ],
        ];
    }

    /**
     * Extension config of the canonical custom-storage scenario, pointing at
     * the in-memory storage service from {@see customStorageServices()}.
     *
     * @return array<string, mixed>
     */
    public static function customStorageExtension(): array
    {
        return [
            'storage' => [
                'type' => 'custom',
                'custom' => ['service' => 'test.custom_storage'],
            ],
            'events' => ['enabled' => false],
            'lock' => ['enabled' => false],
        ];
    }

    /**
     * Services of the canonical custom-storage scenario: a non-transactional
     * in-memory storage.
     *
     * @return array<string, ServiceSpec>
     */
    public static function customStorageServices(): array
    {
        return [
            'test.custom_storage' => ['class' => InMemoryStorage::class, 'public' => true],
        ];
    }
}
