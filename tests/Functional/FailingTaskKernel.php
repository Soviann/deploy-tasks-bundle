<?php

declare(strict_types=1);

namespace Soviann\DeployTasks\Tests\Functional;

use Soviann\DeployTasks\Tests\Fixtures\FailingTask;
use Soviann\DeployTasks\Tests\Fixtures\SimpleTask;
use Soviann\DeployTasksBundle\DeployTasksBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;

final class FailingTaskKernel extends Kernel
{
    use MicroKernelTrait;

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new DeployTasksBundle();
    }

    public function getCacheDir(): string
    {
        return \sys_get_temp_dir().'/deploy-tasks-failing-cache/'.$this->environment;
    }

    public function getLogDir(): string
    {
        return \sys_get_temp_dir().'/deploy-tasks-failing-logs';
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

        $storagePath = \sys_get_temp_dir().'/deploy-tasks-failing-'.$this->environment;

        $container->extension('deploy_tasks', [
            'storage' => [
                'type' => 'filesystem',
                'filesystem' => ['path' => $storagePath],
            ],
            'events' => ['enabled' => false],
            'lock' => ['enabled' => false],
        ]);

        $services = $container->services();

        $services->set('test.task.simple', SimpleTask::class)
            ->args(['test.simple', 'A simple test task'])
            ->tag('deploy_tasks.task')
        ;

        $services->set('test.task.failing', FailingTask::class)
            ->tag('deploy_tasks.task')
        ;
    }
}
