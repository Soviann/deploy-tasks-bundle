<?php

declare(strict_types=1);

namespace Soviann\DeployTasks\Tests\Functional;

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

final class CustomStorageMissingServiceTestKernel extends AbstractTestKernel
{
    protected static function kernelName(): string
    {
        return 'custom-storage-missing';
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', $this->frameworkConfig());

        $container->extension('deploy_tasks', [
            'storage' => [
                'type' => 'custom',
            ],
            'events' => ['enabled' => false],
            'lock' => ['enabled' => false],
        ]);
    }
}
