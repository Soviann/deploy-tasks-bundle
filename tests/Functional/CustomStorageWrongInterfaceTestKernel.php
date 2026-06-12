<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Tests\Functional;

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

/**
 * Points storage.custom.service at a class that does NOT implement
 * TaskStorageInterface — the container build must refuse it.
 */
final class CustomStorageWrongInterfaceTestKernel extends AbstractTestKernel
{
    protected static function kernelName(): string
    {
        return 'custom-storage-wrong-interface';
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', $this->frameworkConfig());

        $container->extension('deploy_tasks', [
            'storage' => [
                'type' => 'custom',
                'custom' => ['service' => 'test.wrong_interface_storage'],
            ],
            'events' => ['enabled' => false],
            'lock' => ['enabled' => false],
        ]);

        $container->services()
            ->set('test.wrong_interface_storage', \ArrayObject::class)->public()
        ;
    }
}
