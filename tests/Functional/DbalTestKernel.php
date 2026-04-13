<?php

declare(strict_types=1);

namespace Soviann\DeployTasks\Tests\Functional;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Soviann\DeployTasks\Tests\Fixtures\SimpleTask;
use Soviann\DeployTasks\Tests\Fixtures\TransactionalTask;
use Soviann\DeployTasksBundle\DeployTasksBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;

final class DbalTestKernel extends Kernel
{
    use MicroKernelTrait;

    public static function createConnection(): Connection
    {
        return DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
    }

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new DeployTasksBundle();
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

        $container->extension('deploy_tasks', [
            'storage' => [
                'type' => 'database',
                'database' => [
                    'connection' => 'default',
                    'table' => 'deploy_task_executions',
                    'transaction_wrap' => false,
                ],
            ],
            'events' => ['enabled' => false],
            'lock' => ['enabled' => false],
        ]);

        $services = $container->services();

        $services->set('doctrine.dbal.default_connection', Connection::class)
            ->factory([self::class, 'createConnection'])
            ->public()
        ;

        $services->set('test.task.simple', SimpleTask::class)
            ->args(['test.simple', 'A simple test task'])
            ->tag('deploy_tasks.task')
        ;

        $services->set('test.task.transactional', TransactionalTask::class)
            ->tag('deploy_tasks.task')
        ;
    }

    public function getCacheDir(): string
    {
        return \sys_get_temp_dir().'/deploy-tasks-dbal-cache/'.$this->environment;
    }

    public function getLogDir(): string
    {
        return \sys_get_temp_dir().'/deploy-tasks-dbal-logs';
    }
}
