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
use Soviann\DeployTasks\Tests\Fixtures\CustomIdResolverFixture;
use Soviann\DeployTasks\Tests\Fixtures\CustomOrderResolverFixture;
use Soviann\DeployTasks\Tests\Functional\CustomResolverTestKernel;
use Soviann\DeployTasks\Tests\Functional\EventsEnabledTestKernel;
use Soviann\DeployTasks\Tests\Functional\FunctionalTestCase;
use Soviann\DeployTasks\Tests\Functional\LockEnabledTestKernel;
use Soviann\DeployTasks\Tests\Functional\TestKernel;
use Symfony\Component\Lock\LockFactory;

#[CoversClass(DeployTasksBundle::class)]
final class DeployTasksBundleTest extends FunctionalTestCase
{
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

    public function testKernelBootsWithEventsEnabled(): void
    {
        static::$class = EventsEnabledTestKernel::class;
        self::bootKernel();

        // If we get here, the kernel compiled without errors with events=true
        $container = self::getContainer();
        self::assertTrue($container->has(TaskRunner::class));
    }

    public function testEventDispatcherIsWiredOnRunner(): void
    {
        static::$class = EventsEnabledTestKernel::class;
        self::bootKernel();
        $container = self::getContainer();

        // The event_dispatcher service must be available and the runner must have it
        self::assertTrue($container->has('event_dispatcher'));

        // Use reflection to verify the runner received the dispatcher
        $runner = $container->get(TaskRunner::class);
        \assert($runner instanceof TaskRunner);

        $reflection = new \ReflectionProperty(TaskRunner::class, 'dispatcher');
        self::assertNotNull($reflection->getValue($runner), 'dispatcher must be wired when events.enabled=true');
    }

    public function testKernelBootsWithLockEnabled(): void
    {
        static::$class = LockEnabledTestKernel::class;
        self::bootKernel();

        $container = self::getContainer();
        self::assertTrue($container->has(TaskRunner::class));
    }

    public function testLockFactoryIsWiredOnRunner(): void
    {
        static::$class = LockEnabledTestKernel::class;
        self::bootKernel();
        $container = self::getContainer();

        self::assertTrue($container->has(LockFactory::class));

        $runner = $container->get(TaskRunner::class);
        \assert($runner instanceof TaskRunner);

        $reflection = new \ReflectionProperty(TaskRunner::class, 'lockFactory');
        self::assertNotNull($reflection->getValue($runner), 'lockFactory must be wired when lock.enabled=true');
    }

    public function testCustomIdResolverIsUsed(): void
    {
        static::$class = CustomResolverTestKernel::class;
        self::bootKernel();
        $container = self::getContainer();

        $resolver = $container->get(TaskIdResolverInterface::class);
        self::assertInstanceOf(CustomIdResolverFixture::class, $resolver);
    }

    public function testCustomOrderResolverIsUsed(): void
    {
        static::$class = CustomResolverTestKernel::class;
        self::bootKernel();
        $container = self::getContainer();

        $resolver = $container->get(TaskOrderResolverInterface::class);
        self::assertInstanceOf(CustomOrderResolverFixture::class, $resolver);
    }

    protected static function getKernelClass(): string
    {
        return TestKernel::class;
    }
}
