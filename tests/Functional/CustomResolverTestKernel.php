<?php

declare(strict_types=1);

namespace Soviann\DeployTasks\Tests\Functional;

use Soviann\DeployTasks\Tests\Fixtures\CustomIdResolverFixture;
use Soviann\DeployTasks\Tests\Fixtures\CustomOrderResolverFixture;
use Soviann\DeployTasks\Tests\Fixtures\SimpleTask;
use Soviann\DeployTasksBundle\DeployTasksBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;

final class CustomResolverTestKernel extends Kernel
{
    use MicroKernelTrait;

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new DeployTasksBundle();
    }

    public function getCacheDir(): string
    {
        return \sys_get_temp_dir().'/deploy-tasks-custom-cache/'.$this->environment;
    }

    public function getLogDir(): string
    {
        return \sys_get_temp_dir().'/deploy-tasks-custom-logs';
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', [
            'test' => true,
            'secret' => 'test',
            'http_method_override' => false,
            'handle_all_throwables' => true,
            'php_errors' => ['log' => true],
        ]);

        $storagePath = \sys_get_temp_dir().'/deploy-tasks-custom-'.$this->environment;

        $container->extension('deploy_tasks', [
            'id_resolver' => 'test.custom_id_resolver',
            'order_resolver' => 'test.custom_order_resolver',
            'storage' => [
                'type' => 'filesystem',
                'filesystem' => ['path' => $storagePath],
            ],
            'events' => ['enabled' => false],
            'lock' => ['enabled' => false],
        ]);

        $services = $container->services();

        $services->set('test.custom_id_resolver', CustomIdResolverFixture::class)->public();
        $services->set('test.custom_order_resolver', CustomOrderResolverFixture::class)->public();

        $services->set('test.task.simple', SimpleTask::class)
            ->args(['test.simple', 'A simple test task'])
            ->tag('deploy_tasks.task')
        ;
    }
}
