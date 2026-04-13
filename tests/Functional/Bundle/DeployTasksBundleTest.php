<?php

declare(strict_types=1);

namespace Soviann\DeployTasks\Tests\Functional\Bundle;

use PHPUnit\Framework\Attributes\CoversClass;
use Soviann\DeployTasks\Bundle\DeployTasksBundle;
use Soviann\DeployTasks\Contract\TaskIdResolverInterface;
use Soviann\DeployTasks\Contract\TaskOrderResolverInterface;
use Soviann\DeployTasks\Contract\TaskStorageInterface;
use Soviann\DeployTasks\DefaultTaskIdResolver;
use Soviann\DeployTasks\DefaultTaskOrderResolver;
use Soviann\DeployTasks\Storage\FilesystemStorage;
use Soviann\DeployTasks\TaskRegistry;
use Soviann\DeployTasks\TaskRunner;
use Soviann\DeployTasks\Tests\Functional\TestKernel;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

#[CoversClass(DeployTasksBundle::class)]
final class DeployTasksBundleTest extends KernelTestCase
{
    protected static function getKernelClass(): string
    {
        return TestKernel::class;
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        \restore_exception_handler();
    }

    public function testServicesAreRegistered(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        self::assertTrue($container->has(TaskRegistry::class));
        self::assertTrue($container->has(TaskRunner::class));
        self::assertTrue($container->has(TaskStorageInterface::class));
        self::assertTrue($container->has(TaskOrderResolverInterface::class));
        self::assertTrue($container->has(TaskIdResolverInterface::class));
    }

    public function testFilesystemStorageIsDefault(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        self::assertInstanceOf(FilesystemStorage::class, $container->get(TaskStorageInterface::class));
    }

    public function testDefaultOrderResolver(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        self::assertInstanceOf(DefaultTaskOrderResolver::class, $container->get(TaskOrderResolverInterface::class));
    }

    public function testDefaultIdResolver(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        self::assertInstanceOf(DefaultTaskIdResolver::class, $container->get(TaskIdResolverInterface::class));
    }

    public function testRegistryContainsRegisteredTasks(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        /** @var TaskRegistry $registry */
        $registry = $container->get(TaskRegistry::class);

        self::assertTrue($registry->has('test.simple'));
        self::assertTrue($registry->has('test.prod_only'));
    }
}
