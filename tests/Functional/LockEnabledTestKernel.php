<?php

declare(strict_types=1);

namespace Soviann\DeployTasks\Tests\Functional;

use Soviann\DeployTasks\Tests\Fixtures\SimpleTask;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

final class LockEnabledTestKernel extends AbstractTestKernel
{
    protected static function kernelName(): string
    {
        return 'lock';
    }

    protected function frameworkConfig(): array
    {
        return \array_merge(parent::frameworkConfig(), [
            'lock' => true,
        ]);
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', $this->frameworkConfig());

        $storagePath = \sys_get_temp_dir().'/deploy-tasks-lock-'.$this->environment;

        $container->extension('deploy_tasks', [
            'storage' => [
                'type' => 'filesystem',
                'filesystem' => ['path' => $storagePath],
            ],
            'events' => ['enabled' => false],
            'lock' => ['enabled' => true],
        ]);

        $container->services()
            ->set('test.task.simple', SimpleTask::class)
            ->args(['test.simple', 'A simple test task'])
            ->tag('deploy_tasks.task')
        ;
    }
}
