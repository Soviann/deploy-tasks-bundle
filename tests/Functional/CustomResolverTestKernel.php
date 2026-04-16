<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Tests\Functional;

use Soviann\DeployTasksBundle\Tests\Fixtures\CustomOrderResolverFixture;
use Soviann\DeployTasksBundle\Tests\Fixtures\SimpleTask;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

final class CustomResolverTestKernel extends AbstractTestKernel
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
            'order_resolver' => 'test.custom_order_resolver',
            'storage' => [
                'type' => 'filesystem',
                'filesystem' => ['path' => $storagePath],
            ],
            'events' => ['enabled' => false],
            'lock' => ['enabled' => false],
        ]);

        $services = $container->services();

        $services->set('test.custom_order_resolver', CustomOrderResolverFixture::class)->public();

        $services->set('test.task.simple', SimpleTask::class)
            ->args(['test.simple', 'A simple test task'])
            ->tag('deploy_tasks.task')
        ;
    }
}
