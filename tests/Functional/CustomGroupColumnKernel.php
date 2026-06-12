<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Tests\Functional;

use Doctrine\DBAL\Connection;
use Soviann\DeployTasksBundle\Tests\Fixtures\SimpleTask;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

final class CustomGroupColumnKernel extends AbstractTestKernel
{
    protected static function kernelName(): string
    {
        return 'dbal-custom-group-column';
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', $this->frameworkConfig());

        $container->extension('soviann_deploy_tasks', [
            'storage' => [
                'type' => 'database',
                'database' => [
                    'connection' => 'default',
                    'table' => 'deploy_task_executions',
                    'group_column' => 'grp',
                    'group_column_length' => 64,
                    'transactional' => false,
                    'all_or_nothing' => false,
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

        $services->set('test.task.simple', SimpleTask::class)
            ->args(['test.simple', 'A simple test task'])
            ->tag('soviann_deploy_tasks.task')
        ;
    }
}
