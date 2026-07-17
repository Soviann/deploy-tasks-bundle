<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Tests\Unit\Bundle\DependencyInjection;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Soviann\DeployTasksBundle\Command\DeployTasksCreateSchemaCommand;
use Soviann\DeployTasksBundle\DependencyInjection\Compiler\RegisterCreateSchemaCommandPass;
use Soviann\DeployTasksBundle\Storage\Dbal\DbalStorage;
use Soviann\DeployTasksBundle\Storage\Dbal\DbalStorageConfiguration;
use Soviann\DeployTasksBundle\Storage\InMemory\InMemoryStorage;
use Soviann\DeployTasksBundle\Tests\Fixtures\SchemaManagingStorageFixture;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

#[CoversClass(RegisterCreateSchemaCommandPass::class)]
final class RegisterCreateSchemaCommandPassTest extends TestCase
{
    private const COMMAND_ID = 'soviann_deploy_tasks.command.create_schema';
    private const CONNECTION_NAME_PARAM = 'soviann_deploy_tasks.storage.dbal_connection_name';

    public function testCommandIsRegisteredForSchemaManageableStorage(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition('soviann_deploy_tasks.storage', new Definition(SchemaManagingStorageFixture::class));

        (new RegisterCreateSchemaCommandPass())->process($container);

        self::assertTrue($container->hasDefinition(self::COMMAND_ID));

        $definition = $container->getDefinition(self::COMMAND_ID);
        self::assertSame(DeployTasksCreateSchemaCommand::class, $definition->getClass());
        self::assertTrue($definition->hasTag('console.command'));

        $arguments = $definition->getArguments();
        self::assertArrayHasKey('$storage', $arguments);
        self::assertInstanceOf(Reference::class, $arguments['$storage']);
        self::assertSame('soviann_deploy_tasks.storage', (string) $arguments['$storage']);

        // Without the DBAL details, $configuration/$connectionName stay at their
        // null defaults so the command emits the generic success message.
        self::assertArrayNotHasKey('$configuration', $arguments);
        self::assertArrayNotHasKey('$connectionName', $arguments);
    }

    public function testDbalDetailsAreWiredForDatabaseStorage(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition('soviann_deploy_tasks.storage', new Definition(DbalStorage::class));
        $container->setDefinition('soviann_deploy_tasks.storage.configuration', new Definition(DbalStorageConfiguration::class));
        $container->setParameter(self::CONNECTION_NAME_PARAM, 'default');

        (new RegisterCreateSchemaCommandPass())->process($container);

        self::assertTrue($container->hasDefinition(self::COMMAND_ID));

        $arguments = $container->getDefinition(self::COMMAND_ID)->getArguments();
        self::assertArrayHasKey('$configuration', $arguments);
        self::assertInstanceOf(Reference::class, $arguments['$configuration']);
        self::assertSame('soviann_deploy_tasks.storage.configuration', (string) $arguments['$configuration']);
        self::assertSame('default', $arguments['$connectionName']);

        // Internal parameter must not leak into the compiled container.
        self::assertFalse($container->hasParameter(self::CONNECTION_NAME_PARAM));
    }

    public function testAliasedCustomStorageIsResolvedThroughTheAlias(): void
    {
        // storage.type: custom aliases soviann_deploy_tasks.storage to the
        // user's service — the capability check must follow the alias.
        $container = new ContainerBuilder();
        $container->setDefinition('test.custom_storage', new Definition(SchemaManagingStorageFixture::class));
        $container->setAlias('soviann_deploy_tasks.storage', 'test.custom_storage');

        (new RegisterCreateSchemaCommandPass())->process($container);

        self::assertTrue($container->hasDefinition(self::COMMAND_ID));
    }

    public function testSyntheticStorageWithoutClassIsSkippedWithoutCrashing(): void
    {
        // A synthetic service's class can be unresolvable at pass time —
        // conservative behavior: no registration, no crash.
        $definition = new Definition();
        $definition->setSynthetic(true);

        $container = new ContainerBuilder();
        $container->setDefinition('soviann_deploy_tasks.storage', $definition);

        (new RegisterCreateSchemaCommandPass())->process($container);

        self::assertFalse($container->hasDefinition(self::COMMAND_ID));
    }

    public function testParameterPlaceholderClassIsSkippedWithoutCrashing(): void
    {
        // Same limitation as RegisterTasksCompilerPass: a %parameter% class is
        // not resolvable at TYPE_BEFORE_OPTIMIZATION time — skip, don't guess.
        $container = new ContainerBuilder();
        $container->setDefinition('soviann_deploy_tasks.storage', new Definition('%app.storage_class%'));

        (new RegisterCreateSchemaCommandPass())->process($container);

        self::assertFalse($container->hasDefinition(self::COMMAND_ID));
    }

    public function testNonSchemaManageableStorageGetsNoCommand(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition('soviann_deploy_tasks.storage', new Definition(InMemoryStorage::class));

        (new RegisterCreateSchemaCommandPass())->process($container);

        self::assertFalse($container->hasDefinition(self::COMMAND_ID));
    }

    public function testMissingStorageServiceIsSkippedWithoutCrashing(): void
    {
        // Hand-built containers without the extension (no storage service at all).
        $container = new ContainerBuilder();

        (new RegisterCreateSchemaCommandPass())->process($container);

        self::assertFalse($container->hasDefinition(self::COMMAND_ID));
    }
}
