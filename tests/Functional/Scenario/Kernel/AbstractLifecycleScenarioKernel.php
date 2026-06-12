<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Tests\Functional\Scenario\Kernel;

use Soviann\DeployTasksBundle\Tests\Fixtures\SimpleTask;
use Soviann\DeployTasksBundle\Tests\Functional\AbstractTestKernel;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

abstract class AbstractLifecycleScenarioKernel extends AbstractTestKernel
{
    public const FIXTURE_TASK_ID = 'scenario.simple';

    final protected function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', $this->frameworkConfig());
        $container->extension('soviann_deploy_tasks', [
            'storage' => $this->storageConfig(),
            'events' => ['enabled' => false],
            'lock' => ['enabled' => false],
        ]);

        $this->registerAdditionalServices($container);

        $container->services()
            ->set('scenario.task.simple', SimpleTask::class)
            ->args([self::FIXTURE_TASK_ID, 'Lifecycle scenario task'])
            ->tag('soviann_deploy_tasks.task')
        ;
    }

    /**
     * @return array<string, mixed>
     */
    abstract protected function storageConfig(): array;

    protected function registerAdditionalServices(ContainerConfigurator $container): void
    {
    }
}
