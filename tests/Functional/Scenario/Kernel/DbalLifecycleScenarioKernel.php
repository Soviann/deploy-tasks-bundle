<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Tests\Functional\Scenario\Kernel;

use Doctrine\DBAL\Connection;
use Soviann\DeployTasksBundle\Tests\Functional\KernelConfig;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

final class DbalLifecycleScenarioKernel extends AbstractLifecycleScenarioKernel
{
    protected static function kernelName(): string
    {
        return 'scenario-dbal';
    }

    protected function storageConfig(): array
    {
        return [
            'type' => 'database',
            'database' => [
                'connection' => 'default',
                'table' => 'deploy_task_executions',
                'transactional' => false,
                'all_or_nothing' => false,
            ],
        ];
    }

    protected function registerAdditionalServices(ContainerConfigurator $container): void
    {
        $container->services()
            ->set('doctrine.dbal.default_connection', Connection::class)
            ->factory([KernelConfig::class, 'createConnection'])
            ->public()
        ;
    }
}
