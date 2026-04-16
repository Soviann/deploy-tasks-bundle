<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Tests\Functional;

use Soviann\DeployTasksBundle\Tests\Fixtures\TransactionalInMemoryStorageFixture;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

final class CustomTransactionalStorageTestKernel extends AbstractTestKernel
{
    protected static function kernelName(): string
    {
        return 'custom-transactional-storage';
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', $this->frameworkConfig());

        $container->extension('deploy_tasks', [
            'storage' => [
                'type' => 'custom',
                'custom' => [
                    'service' => 'test.custom_transactional_storage',
                    'transactional' => true,
                    'all_or_nothing' => true,
                ],
            ],
            'events' => ['enabled' => false],
            'lock' => ['enabled' => false],
        ]);

        $container->services()
            ->set('test.custom_transactional_storage', TransactionalInMemoryStorageFixture::class)->public()
        ;
    }
}
