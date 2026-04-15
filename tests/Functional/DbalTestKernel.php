<?php

declare(strict_types=1);

namespace Soviann\DeployTasks\Tests\Functional;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Soviann\DeployTasks\Tests\Fixtures\SimpleTask;
use Soviann\DeployTasks\Tests\Fixtures\TransactionalTask;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

final class DbalTestKernel extends AbstractTestKernel
{
    public static function createConnection(): Connection
    {
        return DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
    }

    protected static function kernelName(): string
    {
        return 'dbal';
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', $this->frameworkConfig());

        $container->extension('deploy_tasks', [
            'storage' => [
                'type' => 'database',
                'database' => [
                    'connection' => 'default',
                    'table' => 'deploy_task_executions',
                ],
            ],
            'transactional' => false,
            'all_or_nothing' => false,
            'events' => ['enabled' => false],
            'lock' => ['enabled' => false],
        ]);

        $services = $container->services();

        $services->set('doctrine.dbal.default_connection', Connection::class)
            ->factory([self::class, 'createConnection'])
            ->public()
        ;

        $services->set('test.task.simple', SimpleTask::class)
            ->args(['test.simple', 'A simple test task'])
            ->tag('deploy_tasks.task')
        ;

        $services->set('test.task.transactional', TransactionalTask::class)
            ->tag('deploy_tasks.task')
        ;
    }
}
