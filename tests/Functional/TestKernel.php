<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Tests\Functional;

use Soviann\DeployTasksBundle\DeployTaskInterface;
use Soviann\DeployTasksBundle\Tests\Fixtures\AttributeDescriptionOnlyTask;
use Soviann\DeployTasksBundle\Tests\Fixtures\MultiEnvTask;
use Soviann\DeployTasksBundle\Tests\Fixtures\MultiGroupTask;
use Soviann\DeployTasksBundle\Tests\Fixtures\PredeployTask;
use Soviann\DeployTasksBundle\Tests\Fixtures\PrioritizedTask;
use Soviann\DeployTasksBundle\Tests\Fixtures\ProdOnlyTask;
use Soviann\DeployTasksBundle\Tests\Fixtures\SimpleTask;
use Soviann\DeployTasksBundle\Tests\Fixtures\SkippingTask;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

final class TestKernel extends AbstractTestKernel
{
    /**
     * @param list<class-string<DeployTaskInterface>> $extraTasks
     */
    public function __construct(
        string $environment,
        bool $debug,
        private readonly bool $eventsEnabled = false,
        private readonly bool $lockEnabled = false,
        private readonly array $extraTasks = [],
    ) {
        parent::__construct($environment, $debug);
    }

    public function getCacheDir(): string
    {
        return \sys_get_temp_dir().'/deploy-tasks-'.$this->variant().'-cache-'.\getmypid().'/'.$this->environment;
    }

    public function getLogDir(): string
    {
        return \sys_get_temp_dir().'/deploy-tasks-'.$this->variant().'-logs-'.\getmypid();
    }

    protected static function kernelName(): string
    {
        return 'bundle';
    }

    /**
     * @return array<string, mixed>
     */
    protected function frameworkConfig(): array
    {
        $config = parent::frameworkConfig();

        if ($this->lockEnabled) {
            $config['lock'] = true;
        }

        return $config;
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', $this->frameworkConfig());

        $storagePath = \sys_get_temp_dir().'/deploy-tasks-'.$this->variant().'-'.$this->environment;

        $container->extension('deploy_tasks', [
            'storage' => [
                'type' => 'filesystem',
                'filesystem' => [
                    'path' => $storagePath,
                ],
            ],
            'events' => ['enabled' => $this->eventsEnabled],
            'lock' => ['enabled' => $this->lockEnabled],
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

        $services->set('test.task.predeploy', PredeployTask::class)
            ->tag('deploy_tasks.task')
        ;

        $services->set('test.task.multi_group', MultiGroupTask::class)
            ->tag('deploy_tasks.task')
        ;

        $services->set('test.task.attribute_description', AttributeDescriptionOnlyTask::class)
            ->tag('deploy_tasks.task')
        ;

        foreach ($this->extraTasks as $class) {
            $services->set('test.task.extra.'.(new \ReflectionClass($class))->getShortName(), $class)
                ->tag('deploy_tasks.task')
            ;
        }
    }

    private function variant(): string
    {
        return match (true) {
            $this->eventsEnabled => 'events',
            $this->lockEnabled => 'lock',
            [] !== $this->extraTasks => 'extra',
            default => 'functional',
        };
    }
}
