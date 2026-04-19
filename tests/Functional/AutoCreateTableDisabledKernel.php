<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Tests\Functional;

use Doctrine\DBAL\Connection;
use Soviann\DeployTasksBundle\Tests\Fixtures\SimpleTask;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

final class AutoCreateTableDisabledKernel extends AbstractTestKernel
{
    protected static function kernelName(): string
    {
        return 'dbal-no-auto-create';
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
                    'transactional' => false,
                    'all_or_nothing' => false,
                    'auto_create_table' => false,
                ],
            ],
            'events' => ['enabled' => false],
            'lock' => ['enabled' => false],
        ]);

        $services = $container->services();

        $services->set('doctrine.dbal.default_connection', Connection::class)
            ->factory([DbalTestKernel::class, 'createConnection'])
            ->public()
        ;

        $services->set('auto_create_table.task.simple', SimpleTask::class)
            ->args(['auto_create_table.simple', 'Auto-create-table disabled fixture'])
            ->tag('deploy_tasks.task')
        ;
    }
}
