<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Tests\Functional\Scenario\Kernel;

use Soviann\DeployTasksBundle\Storage\InMemory\InMemoryStorage;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

final class CustomLifecycleScenarioKernel extends AbstractLifecycleScenarioKernel
{
    protected static function kernelName(): string
    {
        return 'scenario-custom';
    }

    protected function storageConfig(): array
    {
        return [
            'type' => 'custom',
            'custom' => ['service' => 'scenario.custom_storage'],
        ];
    }

    protected function registerAdditionalServices(ContainerConfigurator $container): void
    {
        $container->services()
            ->set('scenario.custom_storage', InMemoryStorage::class)->public()
        ;
    }
}
