<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Tests\Functional\Bundle;

use PHPUnit\Framework\Attributes\CoversClass;
use Soviann\DeployTasksBundle\Event\AfterTaskEvent;
use Soviann\DeployTasksBundle\Identifier\TaskIdGeneratorInterface;
use Soviann\DeployTasksBundle\Identifier\TaskIdResolver;
use Soviann\DeployTasksBundle\Runner\RunOptions;
use Soviann\DeployTasksBundle\Runner\TaskRegistry;
use Soviann\DeployTasksBundle\Runner\TaskRunner;
use Soviann\DeployTasksBundle\Sorting\DefaultTaskSorter;
use Soviann\DeployTasksBundle\Sorting\TaskSorterInterface;
use Soviann\DeployTasksBundle\SoviannDeployTasksBundle;
use Soviann\DeployTasksBundle\Storage\Dbal\DbalStorageConfiguration;
use Soviann\DeployTasksBundle\Storage\Filesystem\FilesystemStorage;
use Soviann\DeployTasksBundle\Storage\InMemory\InMemoryStorage;
use Soviann\DeployTasksBundle\Storage\TaskStorageInterface;
use Soviann\DeployTasksBundle\Storage\TransactionalStorageInterface;
use Soviann\DeployTasksBundle\Tests\Fixtures\AutoconfiguredTask;
use Soviann\DeployTasksBundle\Tests\Fixtures\CustomSorterFixture;
use Soviann\DeployTasksBundle\Tests\Fixtures\SimpleTask;
use Soviann\DeployTasksBundle\Tests\Fixtures\TransactionalInMemoryStorageFixture;
use Soviann\DeployTasksBundle\Tests\Functional\FunctionalTestCase;
use Soviann\DeployTasksBundle\Tests\Functional\KernelConfig;
use Soviann\DeployTasksBundle\Tests\Functional\TestKernel;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Lock\LockFactory;

#[CoversClass(SoviannDeployTasksBundle::class)]
final class SoviannDeployTasksBundleTest extends FunctionalTestCase
{
    public function testServicesAreRegistered(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        self::assertTrue($container->has(TaskRegistry::class));
        self::assertTrue($container->has(TaskRunner::class));
        self::assertTrue($container->has(TaskStorageInterface::class));
        self::assertTrue($container->has(TaskSorterInterface::class));
    }

    public function testFilesystemStorageIsDefault(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        self::assertInstanceOf(FilesystemStorage::class, $container->get(TaskStorageInterface::class));
    }

    public function testDefaultSorter(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        self::assertInstanceOf(DefaultTaskSorter::class, $container->get(TaskSorterInterface::class));
    }

    public function testIdResolverIsRegistered(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        self::assertInstanceOf(TaskIdResolver::class, $container->get('soviann_deploy_tasks.id_resolver'));
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
        static::$class = TestKernel::class;
        self::$testKernelOptions = ['eventsEnabled' => true];
        self::bootKernel();

        // If we get here, the kernel compiled without errors with events=true
        $container = self::getContainer();
        self::assertTrue($container->has(TaskRunner::class));
    }

    public function testEventDispatcherIsWiredOnRunner(): void
    {
        static::$class = TestKernel::class;
        self::$testKernelOptions = ['eventsEnabled' => true];
        self::bootKernel();
        $container = self::getContainer();

        self::assertTrue($container->has('event_dispatcher'));

        $dispatcher = $container->get('event_dispatcher');
        \assert($dispatcher instanceof EventDispatcherInterface);

        $captured = [];
        $dispatcher->addListener(AfterTaskEvent::class, static function (AfterTaskEvent $event) use (&$captured): void {
            $captured[] = $event->taskId;
        });

        $runner = $container->get(TaskRunner::class);
        \assert($runner instanceof TaskRunner);
        $runner->runOne('test.simple', new BufferedOutput(), new RunOptions(rerunAll: true));

        self::assertSame(['test.simple'], $captured, 'AfterTaskEvent must fire when dispatcher wired on TaskRunner.');
    }

    public function testKernelBootsWithLockEnabled(): void
    {
        static::$class = TestKernel::class;
        self::$testKernelOptions = ['lockEnabled' => true];
        self::bootKernel();

        $container = self::getContainer();
        self::assertTrue($container->has(TaskRunner::class));
    }

    public function testLockFactoryIsWiredOnRunner(): void
    {
        static::$class = TestKernel::class;
        self::$testKernelOptions = ['lockEnabled' => true];
        self::bootKernel();
        $container = self::getContainer();

        self::assertTrue($container->has(LockFactory::class));

        $runner = $container->get(TaskRunner::class);
        \assert($runner instanceof TaskRunner);

        $output = new BufferedOutput(OutputInterface::VERBOSITY_VERBOSE);
        $runner->runAll($output, new RunOptions(dryRun: true));

        self::assertStringNotContainsString(
            'No lock factory configured',
            $output->fetch(),
            'Verbose runAll output must not emit the missing-lock-factory warning when lock.enabled=true.',
        );
    }

    public function testCustomSorterIsUsed(): void
    {
        self::useConfigurableKernel([
            'sorter' => 'test.custom_sorter',
            'storage' => [
                'type' => 'filesystem',
                'filesystem' => ['path' => \sys_get_temp_dir().'/deploy-tasks-custom-'.\getmypid().'-test'],
            ],
            'events' => ['enabled' => false],
            'lock' => ['enabled' => false],
        ], [
            'test.custom_sorter' => ['class' => CustomSorterFixture::class, 'public' => true],
            'test.task.simple' => [
                'class' => SimpleTask::class,
                'args' => ['test.simple', 'A simple test task'],
                'tags' => ['soviann_deploy_tasks.task'],
            ],
        ]);
        self::bootKernel();
        $container = self::getContainer();

        $sorter = $container->get(TaskSorterInterface::class);
        self::assertInstanceOf(CustomSorterFixture::class, $sorter);
    }

    public function testCustomStorageIsAliased(): void
    {
        self::useConfigurableKernel(KernelConfig::customStorageExtension(), KernelConfig::customStorageServices());
        self::bootKernel();
        $container = self::getContainer();

        self::assertInstanceOf(InMemoryStorage::class, $container->get(TaskStorageInterface::class));
    }

    public function testCustomStorageWithoutTransactionalInterfaceIsNotAliasedAsTransactional(): void
    {
        self::useConfigurableKernel(KernelConfig::customStorageExtension(), KernelConfig::customStorageServices());
        self::bootKernel();
        $container = self::getContainer();

        self::assertFalse($container->has(TransactionalStorageInterface::class));
    }

    public function testCustomStorageWithTransactionalInterfaceIsAliasedAsTransactional(): void
    {
        self::useConfigurableKernel([
            'storage' => [
                'type' => 'custom',
                'custom' => [
                    'service' => 'test.custom_transactional_storage',
                    'transaction_mode' => 'all_or_nothing',
                ],
            ],
            'events' => ['enabled' => false],
            'lock' => ['enabled' => false],
        ], [
            'test.custom_transactional_storage' => [
                'class' => TransactionalInMemoryStorageFixture::class,
                'public' => true,
            ],
        ]);
        self::bootKernel();
        $container = self::getContainer();

        self::assertInstanceOf(
            TransactionalInMemoryStorageFixture::class,
            $container->get(TaskStorageInterface::class),
        );
        self::assertInstanceOf(
            TransactionalInMemoryStorageFixture::class,
            $container->get(TransactionalStorageInterface::class),
        );
    }

    public function testCustomStorageWithoutServiceThrows(): void
    {
        self::useConfigurableKernel([
            'storage' => ['type' => 'custom'],
            'events' => ['enabled' => false],
            'lock' => ['enabled' => false],
        ]);

        self::expectException(\InvalidArgumentException::class);
        self::expectExceptionMessage(
            '"soviann_deploy_tasks.storage.custom.service" must be set when "soviann_deploy_tasks.storage.type" is "custom".',
        );

        self::bootKernel();
    }

    public function testBootFailsWhenFilesystemStorageConfiguresAllOrNothing(): void
    {
        // storage.filesystem has no `all_or_nothing` key — filesystem storage is inherently
        // non-transactional, so the config tree rejects it as an unrecognized option.
        self::useConfigurableKernel([
            'storage' => [
                'type' => 'filesystem',
                'filesystem' => ['all_or_nothing' => true],
            ],
            'events' => ['enabled' => false],
            'lock' => ['enabled' => false],
        ]);

        self::expectException(InvalidConfigurationException::class);
        self::expectExceptionMessageMatches('/Unrecognized option "all_or_nothing"/');

        self::bootKernel();
    }

    public function testSlowTaskThresholdConfigValueIsThreeHundred(): void
    {
        // Pins the `slow_task_threshold` config default (300) — kills Increment/DecrementInteger mutants.
        // Reflection is intentional here: the literal is a constructor-arg integer with no public observable.
        self::bootKernel();
        $runner = self::getContainer()->get(TaskRunner::class);
        \assert($runner instanceof TaskRunner);

        $slowTaskThreshold = new \ReflectionProperty(TaskRunner::class, 'slowTaskThreshold');
        self::assertSame(300, $slowTaskThreshold->getValue($runner));
    }

    public function testDbalIdColumnLengthDefaultIsTwoHundredFiftyFive(): void
    {
        // Pins the `id_column_length` default literal (255) at line 102 — kills Increment/DecrementInteger mutants.
        self::useConfigurableKernel(KernelConfig::dbalExtension(), KernelConfig::dbalServices());
        self::bootKernel();
        $config = self::getContainer()->get('soviann_deploy_tasks.storage.configuration');
        \assert($config instanceof DbalStorageConfiguration);

        self::assertSame(255, $config->idColumnLength);
    }

    public function testDbalGroupColumnDefaultIsTaskGroup(): void
    {
        // Pins the `group_column` default literal ('task_group') — kills constant mutation on
        // DbalStorageConfiguration.
        self::useConfigurableKernel(KernelConfig::dbalExtension(), KernelConfig::dbalServices());
        self::bootKernel();
        $config = self::getContainer()->get('soviann_deploy_tasks.storage.configuration');
        \assert($config instanceof DbalStorageConfiguration);

        self::assertSame('task_group', $config->groupColumn);
        self::assertSame(128, $config->groupColumnLength);
    }

    public function testCustomGroupColumnAndLengthAreWiredFromConfig(): void
    {
        // Verifies that group_column / group_column_length DI config keys flow through to DbalStorageConfiguration
        // and are reflected in the generated CREATE TABLE SQL.
        self::useConfigurableKernel([
            'storage' => [
                'type' => 'database',
                'database' => [
                    'connection' => 'default',
                    'table' => 'deploy_task_executions',
                    'group_column' => 'grp',
                    'group_column_length' => 64,
                    'transaction_mode' => 'none',
                ],
            ],
            'events' => ['enabled' => false],
            'lock' => ['enabled' => false],
        ], [
            'doctrine.dbal.default_connection' => KernelConfig::sqliteConnection(),
            'test.task.simple' => [
                'class' => SimpleTask::class,
                'args' => ['test.simple', 'A simple test task'],
                'tags' => ['soviann_deploy_tasks.task'],
            ],
        ]);
        self::bootKernel();
        $config = self::getContainer()->get('soviann_deploy_tasks.storage.configuration');
        \assert($config instanceof DbalStorageConfiguration);

        self::assertSame('grp', $config->groupColumn);
        self::assertSame(64, $config->groupColumnLength);

        $storage = self::getContainer()->get('soviann_deploy_tasks.storage');
        \assert($storage instanceof \Soviann\DeployTasksBundle\Storage\Dbal\DbalStorage);

        self::assertStringContainsString('grp', $storage->getCreateTableSql());
        self::assertStringContainsString('VARCHAR(64)', $storage->getCreateTableSql());
    }

    public function testTaskIdGeneratorInterfaceAliasIsAutowirable(): void
    {
        // Kills MethodCallRemoval on the `$services->alias(TaskIdGeneratorInterface::class, ...)` call.
        // The alias is private; the test container exposes it the same way autowiring would.
        self::bootKernel();
        $container = self::getContainer();

        self::assertTrue($container->has(TaskIdGeneratorInterface::class));
        self::assertInstanceOf(TaskIdGeneratorInterface::class, $container->get(TaskIdGeneratorInterface::class));
    }

    public function testDeployTaskInterfaceIsAutoconfiguredWithTaskTag(): void
    {
        // Kills MethodCallRemoval on `registerForAutoconfiguration()` (line 292) and `->addTag()` (line 294).
        // Registers a DeployTaskInterface implementation without an explicit tag;
        // only autoconfiguration can pull it into the registry.
        self::useConfigurableKernel([
            'storage' => [
                'type' => 'filesystem',
                'filesystem' => ['path' => \sys_get_temp_dir().'/deploy-tasks-autoconfig-'.\getmypid().'-test'],
            ],
            'events' => ['enabled' => false],
            'lock' => ['enabled' => false],
        ], [
            'test.task.autoconfigured' => ['class' => AutoconfiguredTask::class, 'autoconfigure' => true],
        ]);
        self::bootKernel();

        $registry = self::getContainer()->get(TaskRegistry::class);
        \assert($registry instanceof TaskRegistry);

        self::assertTrue($registry->has('test.autoconfigured'));
    }

    public function testIdResolverReceivesIdGeneratorAsArgument(): void
    {
        // Mutant 148: ArrayItemRemoval removes service('soviann_deploy_tasks.id_generator') from id_resolver args.
        // Without the generator arg, TaskIdResolver cannot delegate to the custom generator.
        // We verify that the id_resolver definition has the id_generator as its first argument.
        self::bootKernel();
        // The simplest observable check: if the arg is missing, instantiating TaskIdResolver
        // via the container would fail with a missing constructor argument.
        // getContainer() compiles the container, so if the arg is removed, boot or get() would fail.
        $container = self::getContainer();
        $resolver = $container->get('soviann_deploy_tasks.id_resolver');
        self::assertInstanceOf(TaskIdResolver::class, $resolver);
    }

    public function testShowCommandIsRegistered(): void
    {
        // Mutant 150: MethodCallRemoval removes the entire soviann_deploy_tasks.command.show registration.
        // Mutant 151: ArrayItemRemoval removes service('soviann_deploy_tasks.registry') from show command args.
        self::bootKernel();
        $container = self::getContainer();

        $command = $container->get('soviann_deploy_tasks.command.show');
        self::assertInstanceOf(\Symfony\Component\Console\Command\Command::class, $command);

        $loader = $container->get('console.command_loader');
        \assert($loader instanceof \Symfony\Component\Console\CommandLoader\CommandLoaderInterface);
        self::assertTrue($loader->has('deploytasks:show'));
    }

    public function testDatabaseStorageIsAliasedAsTransactionalStorageInterface(): void
    {
        // Mutant 153: MethodCallRemoval removes the alias(TransactionalStorageInterface,
        // 'soviann_deploy_tasks.storage') call in the database storage case.
        self::useConfigurableKernel(KernelConfig::dbalExtension(), KernelConfig::dbalServices());
        self::bootKernel();
        $container = self::getContainer();

        self::assertTrue(
            $container->has(TransactionalStorageInterface::class),
            'Database storage must be aliased as TransactionalStorageInterface.',
        );
        self::assertInstanceOf(
            \Soviann\DeployTasksBundle\Storage\Dbal\DbalStorage::class,
            $container->get(TransactionalStorageInterface::class),
        );
    }

    public function testCreateSchemaCommandIsRegisteredForDatabaseStorage(): void
    {
        // Mutant 154: MethodCallRemoval removes the soviann_deploy_tasks.command.create_schema registration.
        // Mutant 155: ArrayItemRemoval removes service('soviann_deploy_tasks.storage') from create_schema args.
        self::useConfigurableKernel(KernelConfig::dbalExtension(), KernelConfig::dbalServices());
        self::bootKernel();
        $container = self::getContainer();

        $command = $container->get('soviann_deploy_tasks.command.create_schema');
        self::assertInstanceOf(\Symfony\Component\Console\Command\Command::class, $command);

        $loader = $container->get('console.command_loader');
        \assert($loader instanceof \Symfony\Component\Console\CommandLoader\CommandLoaderInterface);
        self::assertTrue($loader->has('deploytasks:create-schema'));
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function commandProvider(): iterable
    {
        yield 'run' => ['soviann_deploy_tasks.command.run', 'deploytasks:run'];
        yield 'status' => ['soviann_deploy_tasks.command.status', 'deploytasks:status'];
        yield 'skip' => ['soviann_deploy_tasks.command.skip', 'deploytasks:skip'];
        yield 'reset' => ['soviann_deploy_tasks.command.reset', 'deploytasks:reset'];
        yield 'rollup' => ['soviann_deploy_tasks.command.rollup', 'deploytasks:rollup'];
        yield 'generate:container' => ['soviann_deploy_tasks.command.generate', 'deploytasks:generate:container'];
        yield 'generate:host' => ['soviann_deploy_tasks.command.generate.host', 'deploytasks:generate:host'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('commandProvider')]
    public function testConsoleCommandsAreInstantiableAndRoutable(string $serviceId, string $commandName): void
    {
        // Instantiating via container validates the args-array (kills ArrayItemRemoval on lines
        // 240/245/253/261/269/280).
        // Looking up via command_loader validates the `console.command` tag (kills MethodCallRemoval on lines
        // 239/244/252/260/268/279).
        self::bootKernel();
        $container = self::getContainer();

        $command = $container->get($serviceId);
        self::assertInstanceOf(\Symfony\Component\Console\Command\Command::class, $command);

        $loader = $container->get('console.command_loader');
        \assert($loader instanceof \Symfony\Component\Console\CommandLoader\CommandLoaderInterface);
        self::assertTrue(
            $loader->has($commandName),
            \sprintf('Command "%s" must be registered in the command loader.', $commandName),
        );
    }

    protected static function getKernelClass(): string
    {
        return TestKernel::class;
    }
}
