<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Tests\Functional\Scenario\Kernel;

use Psr\Log\NullLogger;
use Soviann\DeployTasksBundle\Tests\Fixtures\GroupedFailingTask;
use Soviann\DeployTasksBundle\Tests\Fixtures\MultiGroupTask;
use Soviann\DeployTasksBundle\Tests\Fixtures\SimpleTask;
use Soviann\DeployTasksBundle\Tests\Functional\AbstractTestKernel;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

abstract class AbstractLifecycleScenarioKernel extends AbstractTestKernel
{
    public const FIXTURE_TASK_ID = 'scenario.simple';

    /** Mirrors the #[AsDeployTask] metadata declared on {@see MultiGroupTask}. */
    public const GROUPED_TASK_ID = 'test.multi_group';
    public const GROUPED_TASK_GROUP_A = 'predeploy';
    public const GROUPED_TASK_GROUP_B = 'postdeploy';

    /** Mirrors the #[AsDeployTask] metadata declared on {@see GroupedFailingTask}. */
    public const FAILING_TASK_ID = 'test.grouped_failing';
    public const FAILING_TASK_GROUP = 'unstable';

    final protected function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', $this->frameworkConfig());
        $container->extension('soviann_deploy_tasks', [
            'storage' => $this->storageConfig(),
            'events' => ['enabled' => false],
            'lock' => ['enabled' => false],
        ]);

        $this->registerAdditionalServices($container);

        // Silence Symfony's default Logger: the failure-path scenario
        // (GroupedFailingTask) otherwise emits "[error] Deploy task failed" to
        // stderr. Infection's InitialTestsRunner SIGTERMs PHPUnit on the first
        // stderr byte, which kills the mutation job before coverage XML is
        // written. Mirrors the same override in TestKernel.
        $container->services()->set('logger', NullLogger::class)->public();

        // A bare deploytasks:run targets every slot (Phase 3 group semantics),
        // so the grouped fixtures participate in the full-lifecycle scenario:
        // it asserts their slots and the failing task's exit code accordingly.
        $container->services()
            ->set('scenario.task.simple', SimpleTask::class)
                ->args([self::FIXTURE_TASK_ID, 'Lifecycle scenario task'])
                ->tag('soviann_deploy_tasks.task')
            ->set('scenario.task.multi_group', MultiGroupTask::class)
                ->tag('soviann_deploy_tasks.task')
            ->set('scenario.task.grouped_failing', GroupedFailingTask::class)
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
