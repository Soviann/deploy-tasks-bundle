<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Tests\Functional;

use Soviann\DeployTasksBundle\Tests\Fixtures\ArrayLogger;
use Soviann\DeployTasksBundle\Tests\Fixtures\SimpleTask;
use Soviann\DeployTasksBundle\Tests\Fixtures\SkippingTask;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

final class LoggerTestKernel extends AbstractTestKernel
{
    protected static function kernelName(): string
    {
        return 'logger';
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', $this->frameworkConfig());

        $storagePath = \sys_get_temp_dir().'/deploy-tasks-logger-'.$this->environment;

        $container->extension('deploy_tasks', [
            'storage' => [
                'type' => 'filesystem',
                'filesystem' => ['path' => $storagePath],
            ],
            'events' => ['enabled' => false],
            'lock' => ['enabled' => false],
            'logger' => 'app.array_logger',
        ]);

        $services = $container->services();

        $services->set('app.array_logger', ArrayLogger::class)
            ->public()
        ;

        $services->set('test.task.simple', SimpleTask::class)
            ->args(['test.simple', 'Simple task'])
            ->tag('deploy_tasks.task')
        ;

        $services->set('test.task.skipping', SkippingTask::class)
            ->tag('deploy_tasks.task')
        ;
    }
}
