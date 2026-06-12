<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Tests\Functional;

use Doctrine\DBAL\Connection;
use Psr\Log\NullLogger;
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

        $container->extension('soviann_deploy_tasks', [
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

        // Silence Symfony's default Logger: this kernel exercises the failure
        // path, which otherwise writes [critical] to stderr. Infection's
        // InitialTestsRunner kills PHPUnit on the first stderr byte.
        $services->set('logger', NullLogger::class)->public();

        $services->set('doctrine.dbal.default_connection', Connection::class)
            ->factory([DbalTestKernel::class, 'createConnection'])
            ->public()
        ;

        $services->set('auto_create_table.task.simple', SimpleTask::class)
            ->args(['auto_create_table.simple', 'Auto-create-table disabled fixture'])
            ->tag('soviann_deploy_tasks.task')
        ;
    }
}
