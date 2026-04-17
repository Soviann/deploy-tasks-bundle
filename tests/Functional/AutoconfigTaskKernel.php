<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Tests\Functional;

use Soviann\DeployTasksBundle\Tests\Fixtures\AutoconfiguredTask;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

final class AutoconfigTaskKernel extends AbstractTestKernel
{
    protected static function kernelName(): string
    {
        return 'autoconfig';
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', $this->frameworkConfig());

        $container->extension('deploy_tasks', [
            'storage' => [
                'type' => 'filesystem',
                'filesystem' => [
                    'path' => \sys_get_temp_dir().'/deploy-tasks-autoconfig-'.$this->environment,
                ],
            ],
            'events' => ['enabled' => false],
            'lock' => ['enabled' => false],
        ]);

        // Intentionally no `->tag('deploy_tasks.task')` — autoconfiguration must apply it.
        $container->services()
            ->set('test.task.autoconfigured', AutoconfiguredTask::class)
            ->autoconfigure()
        ;
    }
}
