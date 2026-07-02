<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Tests\Functional;

use Psr\Log\NullLogger;
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
use Symfony\Component\Filesystem\Filesystem;

final class TestKernel extends AbstractTestKernel
{
    private readonly string $optionsHash;

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
        // Symfony's debug freshness check tracks file resources, not
        // constructor args: name-keyed dirs would let kernels with different
        // options silently reuse one compiled container. Hash every
        // constructor argument so that can never happen, whatever args are
        // added later. Same rationale as ConfigurableTestKernel::configHash().
        $this->optionsHash = \substr(\sha1(\serialize(\func_get_args())), 0, 12);

        parent::__construct($environment, $debug);
    }

    public function getCacheDir(): string
    {
        $cacheDir = \sys_get_temp_dir().'/deploy-tasks-'.$this->variant().'-'.$this->optionsHash.'-cache-'.\getmypid().'/'.$this->environment;

        if (!\is_dir($cacheDir)) {
            (new Filesystem())->mkdir($cacheDir);
        }

        return $cacheDir;
    }

    public function getLogDir(): string
    {
        return \sys_get_temp_dir().'/deploy-tasks-'.$this->variant().'-'.$this->optionsHash.'-logs-'.\getmypid();
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

        $storagePath = \sys_get_temp_dir().'/deploy-tasks-'.$this->variant().'-'.$this->optionsHash.'-'.\getmypid().'-'.$this->environment;

        $container->extension('soviann_deploy_tasks', [
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

        // Silence Symfony's default Logger: failure-path tests (FailingTask in
        // extraTasks) otherwise emit "[error] Deploy task failed" to stderr.
        // Infection's InitialTestsRunner SIGTERMs PHPUnit on the first stderr
        // byte, which kills the mutation job before coverage XML is written.
        $services->set('logger', NullLogger::class)->public();

        $services->set('test.task.simple', SimpleTask::class)
            ->args(['test.simple', 'A simple test task'])
            ->tag('soviann_deploy_tasks.task')
        ;

        $services->set('test.task.prod_only', ProdOnlyTask::class)
            ->tag('soviann_deploy_tasks.task')
        ;

        $services->set('test.task.prioritized', PrioritizedTask::class)
            ->tag('soviann_deploy_tasks.task')
        ;

        $services->set('test.task.skipping', SkippingTask::class)
            ->tag('soviann_deploy_tasks.task')
        ;

        $services->set('test.task.multi_env', MultiEnvTask::class)
            ->tag('soviann_deploy_tasks.task')
        ;

        $services->set('test.task.predeploy', PredeployTask::class)
            ->tag('soviann_deploy_tasks.task')
        ;

        $services->set('test.task.multi_group', MultiGroupTask::class)
            ->tag('soviann_deploy_tasks.task')
        ;

        $services->set('test.task.attribute_description', AttributeDescriptionOnlyTask::class)
            ->tag('soviann_deploy_tasks.task')
        ;

        foreach ($this->extraTasks as $class) {
            $services->set('test.task.extra.'.(new \ReflectionClass($class))->getShortName(), $class)
                ->tag('soviann_deploy_tasks.task')
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
