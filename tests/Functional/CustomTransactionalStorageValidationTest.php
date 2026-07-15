<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Tests\Functional;

use PHPUnit\Framework\Attributes\CoversClass;
use Soviann\DeployTasksBundle\DependencyInjection\Compiler\RegisterTasksCompilerPass;
use Soviann\DeployTasksBundle\Exception\IncompatibleStorageException;
use Soviann\DeployTasksBundle\Storage\InMemory\InMemoryStorage;
use Soviann\DeployTasksBundle\Storage\TransactionalStorageInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

/**
 * Verifies that the compiler pass rejects a custom non-transactional storage
 * configured with a transaction_mode that requires transactions.
 */
#[CoversClass(RegisterTasksCompilerPass::class)]
final class CustomTransactionalStorageValidationTest extends KernelTestCase
{
    public function testCustomStorageWithPerTaskModeButWithoutInterfaceIsRejected(): void
    {
        $kernel = new class('test', true) extends AbstractTestKernel {
            protected static function kernelName(): string
            {
                return 'custom-non-transactional-'.\uniqid('', true);
            }

            protected function configureContainer(ContainerConfigurator $container): void
            {
                $container->extension('framework', $this->frameworkConfig());

                $container->extension('soviann_deploy_tasks', [
                    'storage' => [
                        'type' => 'custom',
                        'custom' => [
                            'service' => 'test.non_transactional_storage',
                            'transaction_mode' => 'per_task',
                        ],
                    ],
                    'events' => ['enabled' => false],
                    'lock' => ['enabled' => false],
                ]);

                $container->services()
                    ->set('test.non_transactional_storage', InMemoryStorage::class)->public()
                ;
            }
        };

        $this->expectException(IncompatibleStorageException::class);
        $this->expectExceptionMessageMatches('/'.\preg_quote(InMemoryStorage::class, '/').'/');

        $kernel->boot();
    }

    public function testSyntheticNonTransactionalCustomStorageIsRefusedAtRuntime(): void
    {
        // The hole the compiler pass structurally cannot see: a synthetic service
        // carries no class, so the capability check reads null and skips rather than
        // guessing — the container builds. The runner then refuses on the real
        // instance: no false positives, and no run without the rollback the mode
        // promises. (Symfony requires a class on factory-defined services, so
        // "synthetic" — not "factory" — is what actually reaches the null branch.)
        $kernel = new class('test', true) extends AbstractTestKernel {
            protected static function kernelName(): string
            {
                return 'custom-synthetic-non-transactional-'.\uniqid('', true);
            }

            protected function configureContainer(ContainerConfigurator $container): void
            {
                $container->extension('framework', $this->frameworkConfig());

                $container->extension('soviann_deploy_tasks', [
                    'storage' => [
                        'type' => 'custom',
                        'custom' => [
                            'service' => 'test.synthetic_storage',
                            'transaction_mode' => 'per_task',
                        ],
                    ],
                    'events' => ['enabled' => false],
                    'lock' => ['enabled' => false],
                ]);

                $container->services()
                    ->set('test.synthetic_storage')->synthetic()->public()
                ;
            }
        };

        // The container builds: the storage's class is unknowable at compile time.
        $kernel->boot();

        try {
            $container = $kernel->getContainer();
            $container->set('test.synthetic_storage', new InMemoryStorage());

            $testContainer = $container->get('test.service_container');
            \assert($testContainer instanceof \Symfony\Component\DependencyInjection\ContainerInterface);

            $this->expectException(IncompatibleStorageException::class);
            $this->expectExceptionMessage(\sprintf(
                'Configuration "transaction_mode: per_task" requires a storage backend that supports transactions. Configured storage ("%s") does not.',
                InMemoryStorage::class,
            ));

            $testContainer->get('soviann_deploy_tasks.runner');
        } finally {
            $kernel->shutdown();
            \restore_exception_handler();
        }
    }

    public function testCustomStorageWithPerTaskModeAndInterfaceBoots(): void
    {
        $kernel = new class('test', true) extends AbstractTestKernel {
            protected static function kernelName(): string
            {
                return 'custom-transactional-ok-'.\uniqid('', true);
            }

            protected function configureContainer(ContainerConfigurator $container): void
            {
                $container->extension('framework', $this->frameworkConfig());

                $container->extension('soviann_deploy_tasks', [
                    'storage' => [
                        'type' => 'custom',
                        'custom' => [
                            'service' => 'test.transactional_storage',
                            'transaction_mode' => 'per_task',
                        ],
                    ],
                    'events' => ['enabled' => false],
                    'lock' => ['enabled' => false],
                ]);

                $container->services()
                    ->set(
                        'test.transactional_storage',
                        \Soviann\DeployTasksBundle\Tests\Fixtures\TransactionalInMemoryStorageFixture::class,
                    )
                    ->public()
                ;
            }
        };

        $kernel->boot();

        try {
            // If we reach here, the kernel booted without exceptions — correct.
            // The alias is private (autowiring-only), so fetch it through the
            // test container, which exposes private services and aliases.
            $testContainer = $kernel->getContainer()->get('test.service_container');
            \assert($testContainer instanceof \Symfony\Component\DependencyInjection\ContainerInterface);

            self::assertInstanceOf(
                TransactionalStorageInterface::class,
                $testContainer->get(TransactionalStorageInterface::class),
            );
        } finally {
            $kernel->shutdown();
            \restore_exception_handler();
        }
    }
}
