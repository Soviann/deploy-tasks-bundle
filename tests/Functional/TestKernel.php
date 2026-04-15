<?php

declare(strict_types=1);

namespace Soviann\DeployTasks\Tests\Functional;

use Soviann\DeployTasks\Tests\Fixtures\MultiEnvTask;
use Soviann\DeployTasks\Tests\Fixtures\PrioritizedTask;
use Soviann\DeployTasks\Tests\Fixtures\ProdOnlyTask;
use Soviann\DeployTasks\Tests\Fixtures\SimpleTask;
use Soviann\DeployTasks\Tests\Fixtures\SkippingTask;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

final class TestKernel extends AbstractTestKernel
{
    protected static function kernelName(): string
    {
        return 'bundle';
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', $this->frameworkConfig());

        $storagePath = \sys_get_temp_dir().'/deploy-tasks-functional-'.$this->environment;

        $container->extension('deploy_tasks', [
            'storage' => [
                'type' => 'filesystem',
                'filesystem' => [
                    'path' => $storagePath,
                ],
            ],
            'events' => ['enabled' => false],
            'lock' => ['enabled' => false],
        ]);

        $services = $container->services();

        $services->set('test.task.simple', SimpleTask::class)
            ->args(['test.simple', 'A simple test task'])
            ->tag('deploy_tasks.task')
        ;

        $services->set('test.task.prod_only', ProdOnlyTask::class)
            ->tag('deploy_tasks.task')
        ;

        $services->set('test.task.prioritized', PrioritizedTask::class)
            ->tag('deploy_tasks.task')
        ;

        $services->set('test.task.skipping', SkippingTask::class)
            ->tag('deploy_tasks.task')
        ;

        $services->set('test.task.multi_env', MultiEnvTask::class)
            ->tag('deploy_tasks.task')
        ;
    }
}
