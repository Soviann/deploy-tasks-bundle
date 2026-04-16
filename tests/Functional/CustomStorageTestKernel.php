<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Tests\Functional;

use Soviann\DeployTasksBundle\Storage\InMemory\InMemoryStorage;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

final class CustomStorageTestKernel extends AbstractTestKernel
{
    protected static function kernelName(): string
    {
        return 'custom-storage';
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', $this->frameworkConfig());

        $container->extension('deploy_tasks', [
            'storage' => [
                'type' => 'custom',
                'custom' => ['service' => 'test.custom_storage'],
            ],
            'events' => ['enabled' => false],
            'lock' => ['enabled' => false],
        ]);

        $container->services()
            ->set('test.custom_storage', InMemoryStorage::class)->public()
        ;
    }
}
