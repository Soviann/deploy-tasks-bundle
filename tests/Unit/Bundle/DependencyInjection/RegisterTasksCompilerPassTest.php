<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Tests\Unit\Bundle\DependencyInjection;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Soviann\DeployTasksBundle\DependencyInjection\Compiler\RegisterTasksCompilerPass;
use Soviann\DeployTasksBundle\Identifier\TaskIdResolver;
use Soviann\DeployTasksBundle\Storage\Dbal\DbalStorageConfiguration;
use Soviann\DeployTasksBundle\Storage\Filesystem\FilesystemStorage;
use Soviann\DeployTasksBundle\Tests\Fixtures\AttributeOnlyTask;
use Soviann\DeployTasksBundle\Tests\Fixtures\CaseCollidingGroupsTask;
use Soviann\DeployTasksBundle\Tests\Fixtures\NoAttributeSeedCategoriesTask;
use Soviann\DeployTasksBundle\Tests\Fixtures\OverlongIdTask;
use Soviann\DeployTasksBundle\Tests\Fixtures\PredeployTask;
use Soviann\DeployTasksBundle\Tests\Fixtures\ProviderClash\A\ClashTask as ClashTaskA;
use Soviann\DeployTasksBundle\Tests\Fixtures\ProviderClash\B\ClashTask as ClashTaskB;
use Soviann\DeployTasksBundle\Tests\Fixtures\TransactionalInMemoryStorageFixture;
use Soviann\DeployTasksBundle\Tests\Fixtures\UppercasedAttributeOnlyTask;
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

    public function testTaskIdsDifferingOnlyByCaseThrow(): void
    {
        // "attribute_only" vs "Attribute_Only": MySQL *_ci collations and
        // APFS/NTFS file names treat them as the same storage key, so the two
        // tasks would silently share one execution record. The message must name
        // both ids and both services so the host can locate them.
        $container = $this->baseContainer();

        $def1 = new Definition(AttributeOnlyTask::class);
        $def1->addTag('soviann_deploy_tasks.task');
        $container->setDefinition('service.lowercase', $def1);

        $def2 = new Definition(UppercasedAttributeOnlyTask::class);
        $def2->addTag('soviann_deploy_tasks.task');
        $container->setDefinition('service.uppercase', $def2);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/"attribute_only" \(service "service\.lowercase"\).*"Attribute_Only" \(service "service\.uppercase"\).*letter case/s');

        (new RegisterTasksCompilerPass())->process($container);
    }

    public function testGroupsDifferingOnlyByCaseOnOneTaskFailTheBuild(): void
    {
        // One task declaring groups "Predeploy" and "predeploy" produces two
        // storage slots — (id, Predeploy) and (id, predeploy) — that collapse
        // onto one record on a case-insensitive backend. The attribute rejects
        // the declaration the moment the pass reads it, failing the build.
        $container = $this->baseContainer();

        $def = new Definition(CaseCollidingGroupsTask::class);
        $def->addTag('soviann_deploy_tasks.task');
        $container->setDefinition('service.case_groups', $def);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/"Predeploy" and "predeploy".*letter case/');

        (new RegisterTasksCompilerPass())->process($container);
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
    // validateTaggedTasks — IDs derived from class names are validated like
    // attribute IDs (DefaultTaskIdGenerator::generateStatic() is hardcoded)
    // -------------------------------------------------------------------------

    public function testDerivedTaskIdDuplicatesAreDetectedAtCompileTime(): void
    {
        // Neither service declares an attribute ID: both IDs are derived from the
        // short class name ("no_attribute_seed_categories"), so the collision must
        // fail the build — no generator service is involved anymore.
        $container = $this->baseContainer();

        $def1 = new Definition(NoAttributeSeedCategoriesTask::class);
        $def1->addTag('soviann_deploy_tasks.task');
        $container->setDefinition('service.first', $def1);

        $def2 = new Definition(NoAttributeSeedCategoriesTask::class);
        $def2->addTag('soviann_deploy_tasks.task');
        $container->setDefinition('service.second', $def2);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/Duplicate deploy task ID "no_attribute_seed_categories"/');

        (new RegisterTasksCompilerPass())->process($container);
    }

    public function testDerivedTaskIdExceedingConfiguredColumnLengthThrows(): void
    {
        // The id_column_length check must cover derived IDs too, not only
        // attribute-declared ones.
        $container = $this->baseContainer();
        $this->withDbalColumnLengths($container, idColumnLength: 3, groupColumnLength: 128);

        $def = new Definition(NoAttributeSeedCategoriesTask::class);
        $def->addTag('soviann_deploy_tasks.task');
        $container->setDefinition('service.generated_id', $def);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/"no_attribute_seed_categories".*exceeding the configured id_column_length of 3/');

        (new RegisterTasksCompilerPass())->process($container);
    }

    // -------------------------------------------------------------------------
    // wireOptionalDependencies — parameter cleanup (mutants 102, 103, 127, 128)
    // -------------------------------------------------------------------------

    public function testTransactionModeParameterIsRemovedAfterCompilation(): void
    {
        // The parameter must be consumed (removed) by the pass so it does not
        // pollute the compiled container.
        $container = $this->baseContainer();
        $container->setParameter('soviann_deploy_tasks.runner.transaction_mode', 'none');

        // Minimal storage definition so the mode validation can read it.
        $storageDef = new Definition('Soviann\DeployTasksBundle\Storage\Filesystem\FilesystemStorage');
        $container->setDefinition('soviann_deploy_tasks.storage', $storageDef);

        $pass = new RegisterTasksCompilerPass();
        $pass->process($container);

        self::assertFalse($container->hasParameter('soviann_deploy_tasks.runner.transaction_mode'));
    }

    public function testAllOrNothingModeWithClasslessStorageDefinitionBuilds(): void
    {
        // Storage class unresolvable at compile time (synthetic service, child
        // definition) → the transactional-capability check is skipped rather than
        // rejecting the build. TaskRunner's constructor guard covers the real
        // instance, so nothing runs unwrapped.
        $container = $this->baseContainer();
        $container->setParameter('soviann_deploy_tasks.runner.transaction_mode', 'all_or_nothing');
        $container->setDefinition('soviann_deploy_tasks.storage', new Definition()); // class is null

        (new RegisterTasksCompilerPass())->process($container); // must not throw

        self::assertFalse($container->hasParameter('soviann_deploy_tasks.runner.transaction_mode'));
    }

    public function testPerTaskModeWithClasslessStorageDefinitionBuilds(): void
    {
        // Same skip for per_task: an unresolvable storage class cannot be checked at
        // compile time, and rejecting it outright would refuse setups whose real
        // instance does implement TransactionalStorageInterface.
        $container = $this->baseContainer();
        $container->setParameter('soviann_deploy_tasks.runner.transaction_mode', 'per_task');
        $container->setDefinition('soviann_deploy_tasks.storage', new Definition()); // class is null

        (new RegisterTasksCompilerPass())->process($container); // must not throw

        self::assertFalse($container->hasParameter('soviann_deploy_tasks.runner.transaction_mode'));
    }

    // -------------------------------------------------------------------------
    // transaction_mode vs storage capability
    // -------------------------------------------------------------------------

    public function testPerTaskModeOnNonTransactionalStorageThrows(): void
    {
        $container = $this->baseContainer();
        $container->setParameter('soviann_deploy_tasks.runner.transaction_mode', 'per_task');
        $container->setDefinition('soviann_deploy_tasks.storage', new Definition(FilesystemStorage::class));

        $this->expectException(\Soviann\DeployTasksBundle\Exception\IncompatibleStorageException::class);
        $this->expectExceptionMessageMatches('/transaction_mode: per_task.*FilesystemStorage/s');

        (new RegisterTasksCompilerPass())->process($container);
    }

    public function testAllOrNothingModeOnNonTransactionalStorageThrows(): void
    {
        $container = $this->baseContainer();
        $container->setParameter('soviann_deploy_tasks.runner.transaction_mode', 'all_or_nothing');
        $container->setDefinition('soviann_deploy_tasks.storage', new Definition(FilesystemStorage::class));

        $this->expectException(\Soviann\DeployTasksBundle\Exception\IncompatibleStorageException::class);
        $this->expectExceptionMessageMatches('/transaction_mode: all_or_nothing.*FilesystemStorage/s');

        (new RegisterTasksCompilerPass())->process($container);
    }

    public function testNoneModeOnNonTransactionalStorageBuilds(): void
    {
        $container = $this->baseContainer();
        $container->setParameter('soviann_deploy_tasks.runner.transaction_mode', 'none');
        $container->setDefinition('soviann_deploy_tasks.storage', new Definition(FilesystemStorage::class));

        (new RegisterTasksCompilerPass())->process($container); // must not throw

        $this->addToAssertionCount(1);
    }

    // -------------------------------------------------------------------------
    // #[AsDeployTask(transactional: ...)] vs transaction_mode conflicts
    // -------------------------------------------------------------------------

    public function testTransactionalFalseTaskUnderAllOrNothingModeThrows(): void
    {
        // all_or_nothing wraps the entire run in one transaction — a per-task
        // opt-out cannot be honored and must fail the build instead of being
        // silently ignored.
        $container = $this->baseContainer();
        $container->setParameter('soviann_deploy_tasks.runner.transaction_mode', 'all_or_nothing');
        $container->setDefinition(
            'soviann_deploy_tasks.storage',
            new Definition(TransactionalInMemoryStorageFixture::class),
        );

        $def = new Definition(\Soviann\DeployTasksBundle\Tests\Fixtures\NonTransactionalTask::class);
        $def->addTag('soviann_deploy_tasks.task');
        $container->setDefinition('service.opt_out', $def);

        $this->expectException(\Soviann\DeployTasksBundle\Exception\IncompatibleStorageException::class);
        $this->expectExceptionMessageMatches('/NonTransactionalTask.*transactional: false.*all_or_nothing/s');

        (new RegisterTasksCompilerPass())->process($container);
    }

    public function testTransactionalTrueTaskUnderNoneModeThrows(): void
    {
        // transaction_mode: none disables wrapping — an explicit per-task demand
        // for a transaction must fail the build instead of being silently ignored.
        $container = $this->baseContainer();
        $container->setParameter('soviann_deploy_tasks.runner.transaction_mode', 'none');
        $container->setDefinition(
            'soviann_deploy_tasks.storage',
            new Definition(TransactionalInMemoryStorageFixture::class),
        );

        $def = new Definition(\Soviann\DeployTasksBundle\Tests\Fixtures\TransactionalTask::class);
        $def->addTag('soviann_deploy_tasks.task');
        $container->setDefinition('service.opt_in', $def);

        $this->expectException(\Soviann\DeployTasksBundle\Exception\IncompatibleStorageException::class);
        $this->expectExceptionMessageMatches('/TransactionalTask.*transactional: true.*none/s');

        (new RegisterTasksCompilerPass())->process($container);
    }

    public function testTransactionalFalseTaskUnderPerTaskModeBuilds(): void
    {
        // per_task is the one mode where the per-task override applies — the
        // opt-out is honored, not rejected.
        $container = $this->baseContainer();
        $container->setParameter('soviann_deploy_tasks.runner.transaction_mode', 'per_task');
        $container->setDefinition(
            'soviann_deploy_tasks.storage',
            new Definition(TransactionalInMemoryStorageFixture::class),
        );

        $def = new Definition(\Soviann\DeployTasksBundle\Tests\Fixtures\NonTransactionalTask::class);
        $def->addTag('soviann_deploy_tasks.task');
        $container->setDefinition('service.opt_out', $def);

        (new RegisterTasksCompilerPass())->process($container); // must not throw

        $this->addToAssertionCount(1);
    }

    public function testTransactionalTrueTaskUnderAllOrNothingModeBuilds(): void
    {
        // The run-wide transaction satisfies a per-task transaction demand —
        // only the opt-out conflicts with all_or_nothing.
        $container = $this->baseContainer();
        $container->setParameter('soviann_deploy_tasks.runner.transaction_mode', 'all_or_nothing');
        $container->setDefinition(
            'soviann_deploy_tasks.storage',
            new Definition(TransactionalInMemoryStorageFixture::class),
        );

        $def = new Definition(\Soviann\DeployTasksBundle\Tests\Fixtures\TransactionalTask::class);
        $def->addTag('soviann_deploy_tasks.task');
        $container->setDefinition('service.opt_in', $def);

        (new RegisterTasksCompilerPass())->process($container); // must not throw

        $this->addToAssertionCount(1);
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

    public function testTaggedServiceNotImplementingTaskInterfaceIsSkipped(): void
    {
        // A mis-tagged service whose class exists but is not a DeployTaskInterface
        // must be skipped silently — runtime autoconfiguration owns that contract.
        $container = $this->baseContainer();

        $def = new Definition(\stdClass::class);
        $def->addTag('soviann_deploy_tasks.task');
        $container->setDefinition('service.not_a_task', $def);

        (new RegisterTasksCompilerPass())->process($container); // must not throw

        self::assertTrue($container->hasDefinition('service.not_a_task'));
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

    // -------------------------------------------------------------------------
    // validateTaggedTasks — per-task transactional flag vs storage capability
    // -------------------------------------------------------------------------

    public function testTransactionalTaskOnNonTransactionalStorageThrows(): void
    {
        $container = $this->baseContainer();
        $container->setDefinition(
            'soviann_deploy_tasks.storage',
            new Definition(FilesystemStorage::class),
        );

        $def = new Definition(\Soviann\DeployTasksBundle\Tests\Fixtures\TransactionalTask::class);
        $def->addTag('soviann_deploy_tasks.task');
        $container->setDefinition('service.transactional', $def);

        $this->expectException(\Soviann\DeployTasksBundle\Exception\IncompatibleStorageException::class);
        $this->expectExceptionMessageMatches('/TransactionalTask.*transactional: true.*FilesystemStorage/s');

        (new RegisterTasksCompilerPass())->process($container);
    }

    public function testTransactionalTaskOnTransactionalStorageBuilds(): void
    {
        $container = $this->baseContainer();
        $container->setDefinition(
            'soviann_deploy_tasks.storage',
            new Definition(TransactionalInMemoryStorageFixture::class),
        );

        $def = new Definition(\Soviann\DeployTasksBundle\Tests\Fixtures\TransactionalTask::class);
        $def->addTag('soviann_deploy_tasks.task');
        $container->setDefinition('service.transactional', $def);

        (new RegisterTasksCompilerPass())->process($container);

        $this->addToAssertionCount(1);
    }

    public function testTransactionalTaskWithClasslessStorageDefinitionBuilds(): void
    {
        // Storage class unresolvable at compile time (factory-built) → the check is
        // skipped, consistent with the transaction-mode validation's null-class handling.
        $container = $this->baseContainer();
        $container->setDefinition('soviann_deploy_tasks.storage', new Definition());

        $def = new Definition(\Soviann\DeployTasksBundle\Tests\Fixtures\TransactionalTask::class);
        $def->addTag('soviann_deploy_tasks.task');
        $container->setDefinition('service.transactional', $def);

        (new RegisterTasksCompilerPass())->process($container);

        $this->addToAssertionCount(1);
    }

    public function testNonTransactionalTaskOnNonTransactionalStorageBuilds(): void
    {
        // Only the explicit per-task demand is validated — absent/false/null passes.
        $container = $this->baseContainer();
        $container->setDefinition(
            'soviann_deploy_tasks.storage',
            new Definition(FilesystemStorage::class),
        );

        $def = new Definition(AttributeOnlyTask::class);
        $def->addTag('soviann_deploy_tasks.task');
        $container->setDefinition('service.plain', $def);

        (new RegisterTasksCompilerPass())->process($container);

        $this->addToAssertionCount(1);
    }

    // -------------------------------------------------------------------------
    // validateTaggedTasks — filesystem storage record-filename length
    // -------------------------------------------------------------------------

    public function testFilesystemStorageRejectsOverlongRecordFileNamesAtCompileTime(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition('soviann_deploy_tasks.storage', new Definition(FilesystemStorage::class));
        $task = new Definition(OverlongIdTask::class);
        $task->addTag('soviann_deploy_tasks.task');
        $container->setDefinition('app.overlong_task', $task);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/255-byte filesystem limit/');

        (new RegisterTasksCompilerPass())->process($container);
    }

    /**
     * Builds a container with the services and parameters the pass needs to run:
     * the id resolver and the runner plus its optional dependency flags (for
     * wireOptionalDependencies).
     */
    private function baseContainer(): ContainerBuilder
    {
        $container = new ContainerBuilder();

        $container->setDefinition('soviann_deploy_tasks.id_resolver', new Definition(TaskIdResolver::class));
        $container->setDefinition(
            'soviann_deploy_tasks.runner',
            new Definition('Soviann\DeployTasksBundle\Runner\TaskRunner'),
        );
        $container->getDefinition('soviann_deploy_tasks.runner')->setArgument('$dispatcher', null);
        $container->getDefinition('soviann_deploy_tasks.runner')->setArgument('$lockFactory', null);
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
