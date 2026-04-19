<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Tests\Functional;

use Soviann\DeployTasksBundle\Tests\Fixtures\CustomSorterFixture;
use Soviann\DeployTasksBundle\Tests\Fixtures\SimpleTask;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

final class CustomSorterTestKernel extends AbstractTestKernel
{
    protected static function kernelName(): string
    {
        return 'custom';
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', $this->frameworkConfig());

        $storagePath = \sys_get_temp_dir().'/deploy-tasks-custom-'.$this->environment;

        $container->extension('deploy_tasks', [
            'sorter' => 'test.custom_sorter',
            'storage' => [
                'type' => 'filesystem',
                'filesystem' => ['path' => $storagePath],
            ],
            'events' => ['enabled' => false],
            'lock' => ['enabled' => false],
        ]);

        $services = $container->services();

        $services->set('test.custom_sorter', CustomSorterFixture::class)->public();

        $services->set('test.task.simple', SimpleTask::class)
            ->args(['test.simple', 'A simple test task'])
            ->tag('deploy_tasks.task')
        ;
    }
}
