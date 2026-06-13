<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Tests\Functional;

use PHPUnit\Framework\Attributes\CoversClass;
use Soviann\DeployTasksBundle\DependencyInjection\Compiler\RegisterTasksCompilerPass;
use Soviann\DeployTasksBundle\DependencyInjection\Configuration\StorageConfigNode;
use Soviann\DeployTasksBundle\Exception\IncompatibleStorageException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

/**
 * Verifies that impossible storage configurations are rejected at boot.
 *
 * - `filesystem.transactional: true` → caught at config-tree validation (InvalidConfigurationException)
 * - `filesystem.all_or_nothing: true` → caught at compiler-pass validation (IncompatibleStorageException)
 * - custom storage service not implementing TaskStorageInterface → caught at compiler-pass validation (IncompatibleStorageException)
 */
#[CoversClass(StorageConfigNode::class)]
#[CoversClass(RegisterTasksCompilerPass::class)]
final class StorageConfigValidationTest extends KernelTestCase
{
    public function testFilesystemTransactionalTrueIsRejectedAtConfigTree(): void
    {
        $kernel = new class('test', true) extends AbstractTestKernel {
            protected static function kernelName(): string
            {
                return 'fs-transactional-true-'.\uniqid('', true);
            }

            protected function configureContainer(ContainerConfigurator $container): void
            {
                $container->extension('framework', $this->frameworkConfig());

                $container->extension('soviann_deploy_tasks', [
                    'storage' => [
                        'type' => 'filesystem',
                        'filesystem' => [
                            'transactional' => true,
                        ],
                    ],
                    'events' => ['enabled' => false],
                    'lock' => ['enabled' => false],
                ]);
            }
        };

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessageMatches('/Filesystem storage does not support transactions/');

        $kernel->boot();
    }

    public function testFilesystemAllOrNothingTrueIsRejectedAtCompilerPass(): void
    {
        $kernel = new class('test', true) extends AbstractTestKernel {
            protected static function kernelName(): string
            {
                return 'fs-all-or-nothing-true-'.\uniqid('', true);
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
        };

        $this->expectException(IncompatibleStorageException::class);

        $kernel->boot();
    }

    public function testCustomStorageNotImplementingInterfaceFailsAtCompileTime(): void
    {
        // Points storage.custom.service at a class that does NOT implement
        // TaskStorageInterface — the container build must refuse it.
        $kernel = new ConfigurableTestKernel('test', true, [
            'storage' => [
                'type' => 'custom',
                'custom' => ['service' => 'test.wrong_interface_storage'],
            ],
            'events' => ['enabled' => false],
            'lock' => ['enabled' => false],
        ], [
            'test.wrong_interface_storage' => ['class' => \ArrayObject::class, 'public' => true],
        ]);

        $this->expectException(IncompatibleStorageException::class);
        $this->expectExceptionMessageMatches('/must implement/');

        $kernel->boot();
    }
}
