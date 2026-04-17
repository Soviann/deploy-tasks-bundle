<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Tests\Functional\Bundle;

use PHPUnit\Framework\Attributes\CoversClass;
use Soviann\DeployTasksBundle\DeployTasksBundle;
use Soviann\DeployTasksBundle\Exception\IncompatibleStorageException;
use Soviann\DeployTasksBundle\Identifier\TaskIdGeneratorInterface;
use Soviann\DeployTasksBundle\Identifier\TaskIdResolver;
use Soviann\DeployTasksBundle\Ordering\DefaultTaskOrderResolver;
use Soviann\DeployTasksBundle\Ordering\TaskOrderResolverInterface;
use Soviann\DeployTasksBundle\Runner\TaskRegistry;
use Soviann\DeployTasksBundle\Runner\TaskRunner;
use Soviann\DeployTasksBundle\Storage\Dbal\DbalStorageConfiguration;
use Soviann\DeployTasksBundle\Storage\Filesystem\FilesystemStorage;
use Soviann\DeployTasksBundle\Storage\InMemory\InMemoryStorage;
use Soviann\DeployTasksBundle\Storage\TaskStorageInterface;
use Soviann\DeployTasksBundle\Storage\TransactionalStorageInterface;
use Soviann\DeployTasksBundle\Tests\Fixtures\CustomOrderResolverFixture;
use Soviann\DeployTasksBundle\Tests\Fixtures\TransactionalInMemoryStorageFixture;
use Soviann\DeployTasksBundle\Tests\Functional\AutoconfigTaskKernel;
use Soviann\DeployTasksBundle\Tests\Functional\CustomResolverTestKernel;
use Soviann\DeployTasksBundle\Tests\Functional\CustomStorageMissingServiceTestKernel;
use Soviann\DeployTasksBundle\Tests\Functional\CustomStorageTestKernel;
use Soviann\DeployTasksBundle\Tests\Functional\CustomTransactionalStorageTestKernel;
use Soviann\DeployTasksBundle\Tests\Functional\DbalTestKernel;
use Soviann\DeployTasksBundle\Tests\Functional\EventsEnabledTestKernel;
use Soviann\DeployTasksBundle\Tests\Functional\FunctionalTestCase;
use Soviann\DeployTasksBundle\Tests\Functional\IncompatibleAllOrNothingTestKernel;
use Soviann\DeployTasksBundle\Tests\Functional\LockEnabledTestKernel;
use Soviann\DeployTasksBundle\Tests\Functional\TestKernel;
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

    public function testIdResolverIsRegistered(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        self::assertInstanceOf(TaskIdResolver::class, $container->get('deploy_tasks.id_resolver'));
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

    public function testCustomOrderResolverIsUsed(): void
    {
        static::$class = CustomResolverTestKernel::class;
        self::bootKernel();
        $container = self::getContainer();

        $resolver = $container->get(TaskOrderResolverInterface::class);
        self::assertInstanceOf(CustomOrderResolverFixture::class, $resolver);
    }

    public function testCustomStorageIsAliased(): void
    {
        static::$class = CustomStorageTestKernel::class;
        self::bootKernel();
        $container = self::getContainer();

        self::assertInstanceOf(InMemoryStorage::class, $container->get(TaskStorageInterface::class));
    }

    public function testCustomStorageWithoutTransactionalInterfaceIsNotAliasedAsTransactional(): void
    {
        static::$class = CustomStorageTestKernel::class;
        self::bootKernel();
        $container = self::getContainer();

        self::assertFalse($container->has(TransactionalStorageInterface::class));
    }

    public function testCustomStorageWithTransactionalInterfaceIsAliasedAsTransactional(): void
    {
        static::$class = CustomTransactionalStorageTestKernel::class;
        self::bootKernel();
        $container = self::getContainer();

        self::assertInstanceOf(TransactionalInMemoryStorageFixture::class, $container->get(TaskStorageInterface::class));
        self::assertInstanceOf(TransactionalInMemoryStorageFixture::class, $container->get(TransactionalStorageInterface::class));
    }

    public function testCustomStorageWithoutServiceThrows(): void
    {
        static::$class = CustomStorageMissingServiceTestKernel::class;

        self::expectException(\InvalidArgumentException::class);
        self::expectExceptionMessage('"deploy_tasks.storage.custom.service" must be set when "deploy_tasks.storage.type" is "custom".');

        self::bootKernel();
    }

    public function testBootFailsWhenAllOrNothingWithNonTransactionalStorage(): void
    {
        static::$class = IncompatibleAllOrNothingTestKernel::class;

        self::expectException(IncompatibleStorageException::class);
        self::expectExceptionMessage(FilesystemStorage::class);

        self::bootKernel();
    }

    public function testDefaultTimeoutConfigValueIsThreeHundred(): void
    {
        // Pins the `default_timeout` default literal (300) at line 58 — kills Increment/DecrementInteger mutants.
        self::bootKernel();
        $runner = self::getContainer()->get(TaskRunner::class);
        \assert($runner instanceof TaskRunner);

        $defaultTimeout = new \ReflectionProperty(TaskRunner::class, 'defaultTimeout');
        self::assertSame(300, $defaultTimeout->getValue($runner));
    }

    public function testDbalIdColumnLengthDefaultIsTwoHundredFiftyFive(): void
    {
        // Pins the `id_column_length` default literal (255) at line 102 — kills Increment/DecrementInteger mutants.
        static::$class = DbalTestKernel::class;
        self::bootKernel();
        $config = self::getContainer()->get('deploy_tasks.storage.configuration');
        \assert($config instanceof DbalStorageConfiguration);

        self::assertSame(255, $config->idColumnLength);
    }

    public function testTaskIdGeneratorInterfaceAliasIsPublic(): void
    {
        // Kills MethodCallRemoval on the `$services->alias(TaskIdGeneratorInterface::class, ...)->public()` call at line 383.
        self::bootKernel();
        $container = self::getContainer();

        self::assertTrue($container->has(TaskIdGeneratorInterface::class));
        self::assertInstanceOf(TaskIdGeneratorInterface::class, $container->get(TaskIdGeneratorInterface::class));
    }

    public function testDeployTaskInterfaceIsAutoconfiguredWithTaskTag(): void
    {
        // Kills MethodCallRemoval on `registerForAutoconfiguration()` (line 292) and `->addTag()` (line 294).
        // AutoconfigTaskKernel registers a DeployTaskInterface implementation without an explicit tag;
        // only autoconfiguration can pull it into the registry.
        static::$class = AutoconfigTaskKernel::class;
        self::bootKernel();

        $registry = self::getContainer()->get(TaskRegistry::class);
        \assert($registry instanceof TaskRegistry);

        self::assertTrue($registry->has('test.autoconfigured'));
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function commandProvider(): iterable
    {
        yield 'run' => ['deploy_tasks.command.run', 'deploytasks:run'];
        yield 'status' => ['deploy_tasks.command.status', 'deploytasks:status'];
        yield 'skip' => ['deploy_tasks.command.skip', 'deploytasks:skip'];
        yield 'reset' => ['deploy_tasks.command.reset', 'deploytasks:reset'];
        yield 'rollup' => ['deploy_tasks.command.rollup', 'deploytasks:rollup'];
        yield 'generate' => ['deploy_tasks.command.generate', 'deploytasks:generate'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('commandProvider')]
    public function testConsoleCommandsAreInstantiableAndRoutable(string $serviceId, string $commandName): void
    {
        // Instantiating via container validates the args-array (kills ArrayItemRemoval on lines 240/245/253/261/269/280).
        // Looking up via command_loader validates the `console.command` tag (kills MethodCallRemoval on lines 239/244/252/260/268/279).
        self::bootKernel();
        $container = self::getContainer();

        $command = $container->get($serviceId);
        self::assertInstanceOf(\Symfony\Component\Console\Command\Command::class, $command);

        $loader = $container->get('console.command_loader');
        \assert($loader instanceof \Symfony\Component\Console\CommandLoader\CommandLoaderInterface);
        self::assertTrue($loader->has($commandName), \sprintf('Command "%s" must be registered in the command loader.', $commandName));
    }

    protected static function getKernelClass(): string
    {
        return TestKernel::class;
    }
}
