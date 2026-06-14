<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Tests\Unit\Bundle\DependencyInjection;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Soviann\DeployTasksBundle\DependencyInjection\Compiler\RegisterTasksCompilerPass;
use Soviann\DeployTasksBundle\Identifier\DefaultTaskIdGenerator;
use Soviann\DeployTasksBundle\Identifier\TaskIdResolver;
use Soviann\DeployTasksBundle\Storage\Dbal\DbalStorageConfiguration;
use Soviann\DeployTasksBundle\Tests\Fixtures\AttributeOnlyTask;
use Soviann\DeployTasksBundle\Tests\Fixtures\PredeployTask;
use Soviann\DeployTasksBundle\Tests\Fixtures\ProviderClash\A\ClashTask as ClashTaskA;
use Soviann\DeployTasksBundle\Tests\Fixtures\ProviderClash\B\ClashTask as ClashTaskB;
use Soviann\DeployTasksBundle\Tests\Fixtures\TransactionalInMemoryStorageFixture;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

#[CoversClass(RegisterTasksCompilerPass::class)]
final class RegisterTasksCompilerPassTest extends TestCase
{
    public function testDuplicateTaskIdThrowsLogicException(): void
    {
        $container = $this->baseContainer();

        // Two services with the same attribute ID ('attribute_only')
        $def1 = new Definition(AttributeOnlyTask::class);
        $def1->addTag('soviann_deploy_tasks.task');
        $container->setDefinition('service.first', $def1);

        $def2 = new Definition(AttributeOnlyTask::class);
        $def2->addTag('soviann_deploy_tasks.task');
        $container->setDefinition('service.second', $def2);

        $pass = new RegisterTasksCompilerPass();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/Duplicate deploy task ID "attribute_only"/');

        $pass->process($container);
    }

    public function testTaskIdExceedingConfiguredColumnLengthThrows(): void
    {
        $container = $this->baseContainer();
        $this->withDbalColumnLengths($container, idColumnLength: 3, groupColumnLength: 128);

        $def = new Definition(AttributeOnlyTask::class);
        $def->addTag('soviann_deploy_tasks.task');
        $container->setDefinition('service.long_id', $def);

        $pass = new RegisterTasksCompilerPass();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/"attribute_only".*exceeding the configured id_column_length of 3/');

        $pass->process($container);
    }

    public function testTaskGroupExceedingConfiguredColumnLengthThrows(): void
    {
        $container = $this->baseContainer();
        $this->withDbalColumnLengths($container, idColumnLength: 255, groupColumnLength: 3);

        $def = new Definition(PredeployTask::class);
        $def->addTag('soviann_deploy_tasks.task');
        $container->setDefinition('service.long_group', $def);

        $pass = new RegisterTasksCompilerPass();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/group "predeploy".*exceeding the configured group_column_length of 3/');

        $pass->process($container);
    }

    public function testWithinConfiguredColumnLengthsPasses(): void
    {
        $container = $this->baseContainer();
        $this->withDbalColumnLengths($container, idColumnLength: 255, groupColumnLength: 128);

        $def = new Definition(PredeployTask::class);
        $def->addTag('soviann_deploy_tasks.task');
        $container->setDefinition('service.ok', $def);

        $pass = new RegisterTasksCompilerPass();
        $pass->process($container);

        $this->expectNotToPerformAssertions();
    }

    // -------------------------------------------------------------------------
    // Column-length boundary tests (mutants 108, 109: > vs >=)
    // -------------------------------------------------------------------------

    public function testTaskIdExactlyAtColumnLengthDoesNotThrow(): void
    {
        // ID 'attribute_only' is 14 chars — limit of exactly 14 must pass (> not >=).
        $container = $this->baseContainer();
        $this->withDbalColumnLengths($container, idColumnLength: 14, groupColumnLength: 128);

        $def = new Definition(AttributeOnlyTask::class);
        $def->addTag('soviann_deploy_tasks.task');
        $container->setDefinition('service.exact_id', $def);

        $pass = new RegisterTasksCompilerPass();
        $pass->process($container);

        self::assertTrue(
            $container->hasDefinition('service.exact_id'),
            'the exact-length task survived the pass without rejection',
        );
    }

    public function testTaskGroupExactlyAtColumnLengthDoesNotThrow(): void
    {
        // PredeployTask group 'predeploy' is 9 chars — limit of exactly 9 must pass (> not >=).
        $container = $this->baseContainer();
        $this->withDbalColumnLengths($container, idColumnLength: 255, groupColumnLength: 9);

        $def = new Definition(PredeployTask::class);
        $def->addTag('soviann_deploy_tasks.task');
        $container->setDefinition('service.exact_group', $def);

        $pass = new RegisterTasksCompilerPass();
        $pass->process($container);

        self::assertTrue(
            $container->hasDefinition('service.exact_group'),
            'the exact-length group survived the pass without rejection',
        );
    }

    // -------------------------------------------------------------------------
    // resolveGeneratorClass — null class falls back to DefaultTaskIdGenerator
    // (mutants 110–114)
    // -------------------------------------------------------------------------

    public function testResolveGeneratorClassReturnsDefaultWhenDefinitionHasNullClass(): void
    {
        // A Definition with no class set returns null from getClass() — the pass
        // must fall back to DefaultTaskIdGenerator::class rather than using null.
        $container = $this->baseContainer();

        // Override soviann_deploy_tasks.id_generator with a class-less definition.
        $container->setDefinition('soviann_deploy_tasks.id_generator', new Definition());

        // Add a task so validateTaggedTasks actually calls resolveGeneratorClass().
        $def = new Definition(AttributeOnlyTask::class);
        $def->addTag('soviann_deploy_tasks.task');
        $container->setDefinition('service.task', $def);

        $pass = new RegisterTasksCompilerPass();
        $pass->process($container); // must not throw or fail

        // AttributeOnlyTask has an explicit attribute ID, so it gets registered —
        // verifying the pass completed successfully without crashing on a null class.
        self::assertTrue($container->hasDefinition('service.task'));
    }

    // -------------------------------------------------------------------------
    // wireOptionalDependencies — parameter cleanup (mutants 102, 103, 127, 128)
    // -------------------------------------------------------------------------

    public function testAllOrNothingParameterIsRemovedAfterCompilation(): void
    {
        // Mutant 101: validateAllOrNothingStorage removal. The parameter must be
        // consumed (removed) by the pass so it does not pollute the compiled container.
        $container = $this->baseContainer();
        $container->setParameter('soviann_deploy_tasks.runner.all_or_nothing', false);

        // Minimal storage definition so validateAllOrNothingStorage can read it.
        $storageDef = new Definition('Soviann\DeployTasksBundle\Storage\Filesystem\FilesystemStorage');
        $container->setDefinition('soviann_deploy_tasks.storage', $storageDef);

        $pass = new RegisterTasksCompilerPass();
        $pass->process($container);

        self::assertFalse($container->hasParameter('soviann_deploy_tasks.runner.all_or_nothing'));
    }

    public function testCustomStorageServiceIdParameterIsRemovedAfterCompilation(): void
    {
        // Mutant 103: remove('soviann_deploy_tasks.storage.custom_service_id') removal.
        $container = $this->baseContainer();
        $container->setParameter('soviann_deploy_tasks.storage.custom_service_id', 'my.custom_storage');

        // Register a minimal custom storage service (non-transactional).
        $storageDef = new Definition(\Soviann\DeployTasksBundle\Storage\InMemory\InMemoryStorage::class);
        $container->setDefinition('my.custom_storage', $storageDef);

        $pass = new RegisterTasksCompilerPass();
        $pass->process($container);

        self::assertFalse($container->hasParameter('soviann_deploy_tasks.storage.custom_service_id'));
    }

    public function testEventsEnabledParameterIsRemovedAfterCompilation(): void
    {
        // Mutant 127: remove('soviann_deploy_tasks.events.enabled') removal.
        $container = $this->baseContainer();
        // events.enabled is already set in baseContainer; run the pass and check.
        $pass = new RegisterTasksCompilerPass();
        $pass->process($container);

        self::assertFalse($container->hasParameter('soviann_deploy_tasks.events.enabled'));
    }

    public function testLockEnabledParameterIsRemovedAfterCompilation(): void
    {
        // Mutant 128: remove('soviann_deploy_tasks.lock.enabled') removal.
        $container = $this->baseContainer();
        $pass = new RegisterTasksCompilerPass();
        $pass->process($container);

        self::assertFalse($container->hasParameter('soviann_deploy_tasks.lock.enabled'));
    }

    // -------------------------------------------------------------------------
    // wireOptionalDependencies — without runner, wiring must be skipped (mutant 115)
    // -------------------------------------------------------------------------

    public function testProcessWithoutRunnerDefinitionDoesNotThrow(): void
    {
        // Mutant 115: `!$container->hasDefinition('soviann_deploy_tasks.runner')` flipped to
        // `$container->hasDefinition(...)`. Without the early return the pass would
        // attempt to fetch the runner definition and crash with a ServiceNotFoundException.
        // Verify that when no runner is defined, the pass completes silently.
        $container = new ContainerBuilder();
        $container->setDefinition('soviann_deploy_tasks.id_resolver', new Definition(TaskIdResolver::class));
        $container->setDefinition('soviann_deploy_tasks.id_generator', new Definition(DefaultTaskIdGenerator::class));
        // No 'soviann_deploy_tasks.runner' definition.
        $container->setParameter('soviann_deploy_tasks.events.enabled', false);
        $container->setParameter('soviann_deploy_tasks.lock.enabled', false);

        $pass = new RegisterTasksCompilerPass();
        $pass->process($container);

        // Parameters are NOT cleaned up when the runner is absent (early return path).
        // The observable outcome is simply that the pass completes without throwing.
        self::assertTrue(
            $container->hasParameter('soviann_deploy_tasks.events.enabled'),
            'Params remain untouched when runner is absent.',
        );
    }

    // -------------------------------------------------------------------------
    // wireOptionalDependencies — event dispatcher wiring (mutants 116–121)
    // -------------------------------------------------------------------------

    public function testEventDispatcherIsWiredWhenEventsEnabledAndDispatcherPresent(): void
    {
        // Mutants 116–119: various logic corruption of `$eventsEnabled && has('event_dispatcher')`.
        $container = $this->baseContainer();
        $container->setParameter('soviann_deploy_tasks.events.enabled', true);
        $container->setDefinition(
            'event_dispatcher',
            new Definition(\Symfony\Component\EventDispatcher\EventDispatcher::class),
        );

        $pass = new RegisterTasksCompilerPass();
        $pass->process($container);

        $runnerDef = $container->getDefinition('soviann_deploy_tasks.runner');
        $dispatcherArg = $runnerDef->getArgument('$dispatcher');

        self::assertInstanceOf(Reference::class, $dispatcherArg);
        self::assertSame('event_dispatcher', (string) $dispatcherArg);
    }

    public function testEventDispatcherIsNotWiredWhenEventsDisabled(): void
    {
        // Mutant 120: `$eventsEnabled && !$container->has('event_dispatcher')` — must NOT wire dispatcher.
        $container = $this->baseContainer();
        // events.enabled is false in baseContainer.
        $container->setDefinition(
            'event_dispatcher',
            new Definition(\Symfony\Component\EventDispatcher\EventDispatcher::class),
        );

        // Set runner dispatcher to a known sentinel so we can check it was not changed.
        $runnerDef = $container->getDefinition('soviann_deploy_tasks.runner');
        $runnerDef->setArgument('$dispatcher', null);

        $pass = new RegisterTasksCompilerPass();
        $pass->process($container);

        $dispatcherArg = $container->getDefinition('soviann_deploy_tasks.runner')->getArgument('$dispatcher');
        self::assertNull($dispatcherArg, 'Dispatcher must NOT be wired when events.enabled=false.');
    }

    public function testEventDispatcherIsNotWiredWhenEventsEnabledButDispatcherAbsent(): void
    {
        // Mutant 121: elseif negation — when events=true but no dispatcher, must NOT wire (but log).
        $container = $this->baseContainer();
        $container->setParameter('soviann_deploy_tasks.events.enabled', true);
        // No 'event_dispatcher' registered.

        $runnerDef = $container->getDefinition('soviann_deploy_tasks.runner');
        $runnerDef->setArgument('$dispatcher', null);

        $pass = new RegisterTasksCompilerPass();
        $pass->process($container);

        $dispatcherArg = $container->getDefinition('soviann_deploy_tasks.runner')->getArgument('$dispatcher');
        self::assertNull($dispatcherArg, 'Dispatcher must remain null when event_dispatcher service is absent.');
    }

    // -------------------------------------------------------------------------
    // wireOptionalDependencies — lock factory wiring (mutants 122–126)
    // -------------------------------------------------------------------------

    public function testLockFactoryIsWiredWhenLockEnabledAndFactoryPresent(): void
    {
        // Mutants 122–125: various logic corruption of `$lockEnabled && has('lock.factory')`.
        $container = $this->baseContainer();
        $container->setParameter('soviann_deploy_tasks.lock.enabled', true);
        $container->setDefinition('lock.factory', new Definition(\Symfony\Component\Lock\LockFactory::class));

        $pass = new RegisterTasksCompilerPass();
        $pass->process($container);

        $runnerDef = $container->getDefinition('soviann_deploy_tasks.runner');
        $lockArg = $runnerDef->getArgument('$lockFactory');

        self::assertInstanceOf(Reference::class, $lockArg);
        self::assertSame('lock.factory', (string) $lockArg);
    }

    public function testLockFactoryIsNotWiredWhenLockDisabled(): void
    {
        // Mutant 125: lock=false but factory present — must NOT wire.
        $container = $this->baseContainer();
        // lock.enabled is false in baseContainer.
        $container->setDefinition('lock.factory', new Definition(\Symfony\Component\Lock\LockFactory::class));

        $runnerDef = $container->getDefinition('soviann_deploy_tasks.runner');
        $runnerDef->setArgument('$lockFactory', null);

        $pass = new RegisterTasksCompilerPass();
        $pass->process($container);

        $lockArg = $container->getDefinition('soviann_deploy_tasks.runner')->getArgument('$lockFactory');
        self::assertNull($lockArg, 'LockFactory must NOT be wired when lock.enabled=false.');
    }

    public function testLockFactoryIsNotWiredWhenLockEnabledButFactoryAbsent(): void
    {
        // Mutant 126: elseif negation — when lock=true but no lock.factory, must NOT wire.
        $container = $this->baseContainer();
        $container->setParameter('soviann_deploy_tasks.lock.enabled', true);
        // No 'lock.factory' registered.

        $runnerDef = $container->getDefinition('soviann_deploy_tasks.runner');
        $runnerDef->setArgument('$lockFactory', null);

        $pass = new RegisterTasksCompilerPass();
        $pass->process($container);

        $lockArg = $container->getDefinition('soviann_deploy_tasks.runner')->getArgument('$lockFactory');
        self::assertNull($lockArg, 'LockFactory must remain null when lock.factory service is absent.');
    }

    // -------------------------------------------------------------------------
    // maybeAliasTransactionalCustomStorage (mutant 104: && → ||)
    // -------------------------------------------------------------------------

    public function testNonTransactionalCustomStorageIsNotAliasedAsTransactional(): void
    {
        // Mutant 104: `null !== $class && is_a(...)` changed to `||`. With a non-transactional
        // class, the alias must NOT be created.
        $container = $this->baseContainer();
        $container->setParameter('soviann_deploy_tasks.storage.custom_service_id', 'my.storage');

        $storageDef = new Definition(\Soviann\DeployTasksBundle\Storage\InMemory\InMemoryStorage::class);
        $container->setDefinition('my.storage', $storageDef);

        // Also register soviann_deploy_tasks.storage alias (needed by validateCustomTransactionalStorage skip).
        $container->setAlias('soviann_deploy_tasks.storage', 'my.storage');

        $pass = new RegisterTasksCompilerPass();
        $pass->process($container);

        self::assertFalse(
            $container->hasAlias(\Soviann\DeployTasksBundle\Storage\TransactionalStorageInterface::class),
            'A non-transactional custom storage must NOT be aliased as TransactionalStorageInterface.',
        );
    }

    public function testTransactionalCustomStorageIsAliasedAsTransactionalInterface(): void
    {
        // Positive check: when the custom class implements TransactionalStorageInterface, the alias IS created.
        $container = $this->baseContainer();
        $container->setParameter('soviann_deploy_tasks.storage.custom_service_id', 'my.transactional_storage');

        $storageDef = new Definition(TransactionalInMemoryStorageFixture::class);
        $container->setDefinition('my.transactional_storage', $storageDef);
        $container->setAlias('soviann_deploy_tasks.storage', 'my.transactional_storage');

        // runner $transactional must be false so validateCustomTransactionalStorage doesn't throw.
        $container->getDefinition('soviann_deploy_tasks.runner')->setArgument('$transactional', false);

        $pass = new RegisterTasksCompilerPass();
        $pass->process($container);

        self::assertTrue(
            $container->hasAlias(\Soviann\DeployTasksBundle\Storage\TransactionalStorageInterface::class),
            'A transactional custom storage must be aliased as TransactionalStorageInterface.',
        );
    }

    // -------------------------------------------------------------------------
    // validateTaggedTasks — class-existence check (mutant 105: || → &&)
    // -------------------------------------------------------------------------

    public function testServiceWithNullClassIsSkippedGracefully(): void
    {
        // Mutant 105: `null === $class || !class_exists($class)` changed to `&&`.
        // A Definition with class=null must be skipped (continue), not cause a crash.
        $container = $this->baseContainer();

        $def = new Definition(); // class is null
        $def->addTag('soviann_deploy_tasks.task');
        $container->setDefinition('service.no_class', $def);

        $pass = new RegisterTasksCompilerPass();
        $pass->process($container); // must not throw

        self::assertTrue($container->hasDefinition('service.no_class'));
    }

    // -------------------------------------------------------------------------
    // validateTaggedTasks — attribute ID vs. generator (mutants 106, 107)
    // -------------------------------------------------------------------------

    public function testAttributeIdTakesPriorityOverGenerator(): void
    {
        // Mutant 106: `'' !== $attributeId` → `'' === $attributeId`. With an attribute ID set,
        // the attribute path must be taken (not the generator path).
        // AttributeOnlyTask has id='attribute_only'. If the condition were reversed,
        // the generator would be called and a different ID produced, causing no duplicate
        // detection in the seenIds map and potentially a different test outcome.
        $container = $this->baseContainer();

        $def1 = new Definition(AttributeOnlyTask::class);
        $def1->addTag('soviann_deploy_tasks.task');
        $container->setDefinition('service.first', $def1);

        // Second service with same class → same attribute_only ID → duplicate.
        $def2 = new Definition(AttributeOnlyTask::class);
        $def2->addTag('soviann_deploy_tasks.task');
        $container->setDefinition('service.second', $def2);

        $pass = new RegisterTasksCompilerPass();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/Duplicate deploy task ID "attribute_only"/');

        $pass->process($container);
    }

    // -------------------------------------------------------------------------
    // validateTaggedTasks — provider tasks are skipped at compile time
    // -------------------------------------------------------------------------

    public function testProviderTasksWithSameShortClassNameDoNotFalselyCollide(): void
    {
        // Both ClashTask classes implement TaskIdProviderInterface and return
        // distinct runtime IDs — but generateStatic() derives "clash" from both
        // short class names. The pass must not validate that phantom ID.
        $container = $this->baseContainer();

        $def1 = new Definition(ClashTaskA::class);
        $def1->addTag('soviann_deploy_tasks.task');
        $container->setDefinition('service.clash_a', $def1);

        $def2 = new Definition(ClashTaskB::class);
        $def2->addTag('soviann_deploy_tasks.task');
        $container->setDefinition('service.clash_b', $def2);

        (new RegisterTasksCompilerPass())->process($container); // must not throw

        $this->addToAssertionCount(1);
    }

    /**
     * Builds a container with the services and parameters the pass needs to run:
     * id resolver/generator (for ID resolution) and the runner plus its optional
     * dependency flags (for wireOptionalDependencies).
     */
    private function baseContainer(): ContainerBuilder
    {
        $container = new ContainerBuilder();

        $container->setDefinition('soviann_deploy_tasks.id_resolver', new Definition(TaskIdResolver::class));
        $container->setDefinition('soviann_deploy_tasks.id_generator', new Definition(DefaultTaskIdGenerator::class));
        $container->setDefinition(
            'soviann_deploy_tasks.runner',
            new Definition('Soviann\DeployTasksBundle\Runner\TaskRunner'),
        );
        $container->getDefinition('soviann_deploy_tasks.runner')->setArgument('$dispatcher', null);
        $container->getDefinition('soviann_deploy_tasks.runner')->setArgument('$lockFactory', null);
        $container->getDefinition('soviann_deploy_tasks.runner')->setArgument('$transactional', false);
        $container->setParameter('soviann_deploy_tasks.events.enabled', false);
        $container->setParameter('soviann_deploy_tasks.lock.enabled', false);

        return $container;
    }

    /**
     * Registers the database storage configuration so the pass treats storage as
     * database-backed and reads the column lengths from it.
     */
    private function withDbalColumnLengths(
        ContainerBuilder $container,
        int $idColumnLength,
        int $groupColumnLength,
    ): void {
        $definition = new Definition(DbalStorageConfiguration::class);
        $definition->setArgument('$idColumnLength', $idColumnLength);
        $definition->setArgument('$groupColumnLength', $groupColumnLength);

        $container->setDefinition('soviann_deploy_tasks.storage.configuration', $definition);
    }
}
