<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Tests\Functional;

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

final class IncompatibleAllOrNothingTestKernel extends AbstractTestKernel
{
    protected static function kernelName(): string
    {
        return 'incompatible-all-or-nothing';
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', $this->frameworkConfig());

        $container->extension('soviann_deploy_tasks', [
            'storage' => [
                'type' => 'filesystem',
                'filesystem' => [
                    'all_or_nothing' => true,
                ],
            ],
            'events' => ['enabled' => false],
            'lock' => ['enabled' => false],
        ]);
    }
}
