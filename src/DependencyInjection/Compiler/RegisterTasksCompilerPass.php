<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\DependencyInjection\Compiler;

use Soviann\DeployTasksBundle\Attribute\AsDeployTask;
use Soviann\DeployTasksBundle\DeployTaskInterface;
use Soviann\DeployTasksBundle\Exception\IncompatibleStorageException;
use Soviann\DeployTasksBundle\Identifier\TaskIdGeneratorInterface;
use Soviann\DeployTasksBundle\Identifier\TaskIdProviderInterface;
use Soviann\DeployTasksBundle\Runner\TransactionMode;
use Soviann\DeployTasksBundle\Storage\Filesystem\FilesystemStorage;
use Soviann\DeployTasksBundle\Storage\TaskStorageInterface;
use Soviann\DeployTasksBundle\Storage\TransactionalStorageInterface;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Validates tagged deploy tasks at compile time and wires optional dependencies.
 *
 * @internal
 */
final class RegisterTasksCompilerPass implements CompilerPassInterface
{
    /**
     * @throws IncompatibleStorageException When transaction_mode requires transactions the storage cannot provide
     * @throws IncompatibleStorageException When the custom storage service does not implement TaskStorageInterface
     * @throws IncompatibleStorageException When a task declares transactional: true on a non-transactional storage
     * @throws IncompatibleStorageException When a task's transactional flag conflicts with the configured mode
     * @throws \LogicException              When two tagged tasks resolve to the same ID, or to IDs differing only by letter case
     * @throws \LogicException              When a task ID exceeds the configured id_column_length
     * @throws \LogicException              When a task group exceeds the configured group_column_length
     * @throws \ReflectionException         When the #[AsDeployTask] attribute lookup fails on a tagged class
     */
    public function process(ContainerBuilder $container): void
    {
        $transactionMode = $this->consumeTransactionMode($container);

        $this->validateStorageSupportsTransactionMode($container, $transactionMode);
        $this->validateTaggedTasks($container, $transactionMode);
        $this->wireOptionalDependencies($container);
        $this->maybeAliasTransactionalCustomStorage($container);
    }

    /**
     * Reads and removes the internal transaction-mode parameter set by the extension.
     *
     * Null when the extension did not run (hand-built containers) — mode-based
     * validation is then skipped.
     */
    private function consumeTransactionMode(ContainerBuilder $container): ?TransactionMode
    {
        if (!$container->hasParameter('soviann_deploy_tasks.runner.transaction_mode')) {
            return null;
        }

        /** @var string $mode */
        $mode = $container->getParameter('soviann_deploy_tasks.runner.transaction_mode');
        $container->getParameterBag()->remove('soviann_deploy_tasks.runner.transaction_mode');

        return TransactionMode::from($mode);
    }

    /**
     * When the mode is `per_task` or `all_or_nothing`, the configured storage MUST
     * implement TransactionalStorageInterface — otherwise there is nothing to wrap
     * task executions in, and a partial run could not be rolled back.
     *
     * Deferred to the compiler pass because custom storage services are not visible
     * during extension loading. A storage whose class is unresolvable here (synthetic
     * services, child definitions — resolved only in the later optimization phase) is
     * skipped rather than rejected: guessing would refuse setups whose real instance
     * does implement the interface. TaskRunner's constructor guard catches those on
     * the real instance instead, before any task runs.
     *
     * @throws IncompatibleStorageException
     */
    private function validateStorageSupportsTransactionMode(
        ContainerBuilder $container,
        ?TransactionMode $mode,
    ): void {
        if (null === $mode || TransactionMode::None === $mode) {
            return;
        }

        $class = $container->findDefinition('soviann_deploy_tasks.storage')->getClass();

        if (null === $class) {
            return;
        }

        if (!\is_a($class, TransactionalStorageInterface::class, true)) {
            throw IncompatibleStorageException::modeRequiresTransactional($mode, $class);
        }
    }

    /**
     * When storage.type is "custom" and the user-provided service implements
     * TransactionalStorageInterface, exposes it under that interface too.
     *
     * Also rejects custom storage services that do not implement
     * TaskStorageInterface at all — without this check the container builds
     * fine and TaskRunner explodes at runtime with a TypeError.
     *
     * Deferred to the compiler pass because the user's service definition is not
     * visible during extension loading (which runs in an isolated temp container).
     *
     * @throws IncompatibleStorageException When the custom storage service does not implement TaskStorageInterface
     */
    private function maybeAliasTransactionalCustomStorage(ContainerBuilder $container): void
    {
        if (!$container->hasParameter('soviann_deploy_tasks.storage.custom_service_id')) {
            return;
        }

        /** @var string $customServiceId */
        $customServiceId = $container->getParameter('soviann_deploy_tasks.storage.custom_service_id');
        $container->getParameterBag()->remove('soviann_deploy_tasks.storage.custom_service_id');

        $class = $container->findDefinition($customServiceId)->getClass();

        if (null !== $class && !\is_a($class, TaskStorageInterface::class, true)) {
            throw new IncompatibleStorageException(\sprintf('Custom storage service "%s" (class "%s") must implement %s.', $customServiceId, $class, TaskStorageInterface::class));
        }

        if (null !== $class && \is_a($class, TransactionalStorageInterface::class, true)) {
            $container->setAlias(TransactionalStorageInterface::class, 'soviann_deploy_tasks.storage');
        }
    }

    /**
     * Validates at compile time that no two tagged tasks resolve to the same ID —
     * exactly, or differing only by letter case: case-insensitive storage backends
     * (MySQL *_ci collations, APFS/NTFS file names) treat such ids as one key, so
     * two distinct tasks would silently share a single execution record.
     *
     * Group names differing only by case on ONE task are rejected by the
     * #[AsDeployTask] constructor the moment the attribute is read (same-id slots
     * collapse on those backends). Across DIFFERENT tasks, case-differing group
     * names stay legal: every storage key is an (id, group) pair and ids are
     * case-insensitively unique, so those records can never share a backend key.
     *
     * Tasks implementing TaskIdProviderInterface are skipped from the ID checks
     * only (duplicate detection and id_column_length): their real ID only exists
     * at runtime (getTaskId() is an instance method), so any compile-time ID check
     * would run against a phantom ID. TaskRegistry covers them at boot. Group-length
     * validation still runs for them — groups are declared on the attribute and are
     * fully known at compile time.
     *
     * When a custom generator is configured, its generateStatic() is called for
     * each task without an explicit attribute ID. Returning null opts that task
     * out of compile-time duplicate detection. When the generator's class itself
     * cannot be resolved at compile time (factory-defined service, class not yet
     * loadable), every generator-derived task is skipped the same way — the
     * default generator's IDs must never stand in for the real generator's, or
     * legal setups the real generator disambiguates would be rejected.
     *
     * When the active storage is database-backed, also enforces that each task ID
     * and group fits the configured DBAL column length — the attribute itself is
     * storage-agnostic, so the limit can only be checked once storage is known.
     *
     * Also rejects tasks whose #[AsDeployTask(transactional:)] flag cannot be
     * honored, so an explicit per-task declaration can never be silently ignored:
     * `transactional: true` when the active storage does not implement
     * TransactionalStorageInterface, `transactional: true` under
     * `transaction_mode: none` (the mode disables wrapping), and
     * `transactional: false` under `transaction_mode: all_or_nothing` (the run-wide
     * transaction cannot exempt one task). The override only applies in `per_task`
     * mode.
     *
     * @throws IncompatibleStorageException When a task demands a transaction the storage cannot provide
     * @throws IncompatibleStorageException When a task's transactional flag conflicts with the configured mode
     * @throws \LogicException              When two tagged tasks resolve to the same ID, or to IDs differing only by letter case
     * @throws \LogicException              When a task ID exceeds the configured id_column_length
     * @throws \LogicException              When a task group exceeds the configured group_column_length
     * @throws \LogicException              When a filesystem-backed task's record file name exceeds 255 bytes
     * @throws \ReflectionException         When the #[AsDeployTask] attribute lookup fails
     */
    private function validateTaggedTasks(ContainerBuilder $container, ?TransactionMode $mode): void
    {
        $generatorClass = $this->resolveGeneratorClass($container);
        $taggedServices = $container->findTaggedServiceIds('soviann_deploy_tasks.task');
        [$idColumnLength, $groupColumnLength] = $this->resolveStorageColumnLengths($container);
        $storageClass = $container->hasDefinition('soviann_deploy_tasks.storage')
            ? $container->findDefinition('soviann_deploy_tasks.storage')->getClass()
            : null;

        // Keyed by lowercased ID: MySQL *_ci collations and APFS/NTFS file names
        // treat ids differing only by case as the same storage key, so such ids
        // must be rejected like exact duplicates (backend-agnostic guard).
        /** @var array<string, array{string, string}> $seenIds lowercased task ID → [resolved task ID, service ID] */
        $seenIds = [];

        foreach ($taggedServices as $serviceId => $tags) {
            $definition = $container->getDefinition($serviceId);
            /** @var class-string|null $class */
            $class = $definition->getClass();

            if (null === $class || !\class_exists($class)) {
                continue;
            }

            if (!\is_subclass_of($class, DeployTaskInterface::class, true)) {
                continue;
            }

            if (null !== $groupColumnLength) {
                $this->validateGroupLengths($class, $serviceId, $groupColumnLength);
            }

            // Before the TaskIdProviderInterface early-continue: the transactional flag
            // is attribute-declared, so it is compile-time known for provider tasks too.
            // Storage capability first — for a non-transactional backend the actionable
            // fix is the storage (or dropping the flag), not the mode.
            $attributeTransactional = AsDeployTask::of($class)?->transactional;

            if (true === $attributeTransactional
                && null !== $storageClass
                && !\is_a($storageClass, TransactionalStorageInterface::class, true)
            ) {
                throw IncompatibleStorageException::taskRequiresTransactional($class, $storageClass);
            }

            if (true === $attributeTransactional && TransactionMode::None === $mode) {
                throw IncompatibleStorageException::taskOptInConflictsWithModeNone($class);
            }

            if (false === $attributeTransactional && TransactionMode::AllOrNothing === $mode) {
                throw IncompatibleStorageException::taskOptOutConflictsWithAllOrNothing($class);
            }

            if (\is_a($class, TaskIdProviderInterface::class, true)) {
                // The runtime ID comes from getTaskId(); validating generateStatic(FQCN)
                // would check a phantom ID and reject legal setups. TaskRegistry re-checks
                // duplicates at boot; id_column_length cannot be checked for provider
                // tasks before runtime.
                continue;
            }

            $attributeId = AsDeployTask::idOf($class);

            if ('' !== $attributeId) {
                $taskId = $attributeId;
            } else {
                $taskId = null === $generatorClass ? null : $generatorClass::generateStatic($class);

                if (null === $taskId) {
                    continue; // can't know ID at compile time — skip
                }
            }

            if (null !== $idColumnLength && \strlen($taskId) > $idColumnLength) {
                throw new \LogicException(\sprintf('Deploy task ID "%s" (service "%s") is %d characters, exceeding the configured id_column_length of %d. Increase soviann_deploy_tasks.storage.database.id_column_length or shorten the task ID.', $taskId, $serviceId, \strlen($taskId), $idColumnLength));
            }

            if (null !== $storageClass && \is_a($storageClass, FilesystemStorage::class, true)) {
                $this->validateRecordFileNameLengths($class, $serviceId, $taskId);
            }

            $lowercased = \strtolower($taskId);

            if (isset($seenIds[$lowercased])) {
                [$existingId, $existingServiceId] = $seenIds[$lowercased];

                if ($existingId === $taskId) {
                    throw new \LogicException(\sprintf('Duplicate deploy task ID "%s" found in services "%s" and "%s".', $taskId, $existingServiceId, $serviceId));
                }

                throw new \LogicException(\sprintf('Deploy task IDs "%s" (service "%s") and "%s" (service "%s") differ only by letter case. Case-insensitive storage backends (MySQL *_ci collations, APFS/NTFS file names) treat them as the same key, so one of the tasks would silently never run. Rename one ID so they differ beyond case.', $existingId, $existingServiceId, $taskId, $serviceId));
            }

            $seenIds[$lowercased] = [$taskId, $serviceId];
        }
    }

    /**
     * Throws when any group declared by the task exceeds the configured column length.
     *
     * @param class-string $class
     *
     * @throws \LogicException      When a group name exceeds $groupColumnLength
     * @throws \ReflectionException When the #[AsDeployTask] attribute lookup fails
     */
    private function validateGroupLengths(string $class, string $serviceId, int $groupColumnLength): void
    {
        foreach (AsDeployTask::groupsOf($class) ?? [] as $group) {
            if (\strlen($group) > $groupColumnLength) {
                throw new \LogicException(\sprintf('Deploy task group "%s" (service "%s") is %d characters, exceeding the configured group_column_length of %d. Increase soviann_deploy_tasks.storage.database.group_column_length or shorten the group name.', $group, $serviceId, \strlen($group), $groupColumnLength));
            }
        }
    }

    /**
     * Filesystem storage turns "<id>[@<group>].json" into an OS file name, capped
     * at 255 bytes on ext4/APFS/NTFS. Checked at compile time for attribute/
     * generator ids (provider tasks are covered by FilesystemStorage's runtime
     * guard, which fires before execution).
     *
     * @param class-string $class
     *
     * @throws \LogicException      When any slot's record file name exceeds 255 bytes
     * @throws \ReflectionException
     */
    private function validateRecordFileNameLengths(string $class, string $serviceId, string $taskId): void
    {
        foreach (AsDeployTask::groupsOf($class) ?? [null] as $group) {
            $fileName = null === $group ? $taskId.'.json' : $taskId.'@'.$group.'.json';

            if (\strlen($fileName) > 255) {
                throw new \LogicException(\sprintf('Deploy task "%s" (service "%s") produces the record file name "%s" (%d bytes), exceeding the 255-byte filesystem limit. Shorten the task ID or group name.', $taskId, $serviceId, $fileName, \strlen($fileName)));
            }
        }
    }

    /**
     * Returns the configured DBAL column lengths for task IDs and groups, or
     * [null, null] when the active storage is not database-backed — filesystem
     * and custom storage impose no fixed column limit.
     *
     * @return array{0: int|null, 1: int|null} [idColumnLength, groupColumnLength]
     */
    private function resolveStorageColumnLengths(ContainerBuilder $container): array
    {
        if (!$container->hasDefinition('soviann_deploy_tasks.storage.configuration')) {
            return [null, null];
        }

        $definition = $container->getDefinition('soviann_deploy_tasks.storage.configuration');

        /** @var int $idColumnLength */
        $idColumnLength = $definition->getArgument('$idColumnLength');
        /** @var int $groupColumnLength */
        $groupColumnLength = $definition->getArgument('$groupColumnLength');

        return [$idColumnLength, $groupColumnLength];
    }

    /**
     * Returns the FQCN of the configured task ID generator, or null when the
     * generator's class cannot be resolved at compile time (factory-defined
     * service, class not yet loadable). Callers must then skip generator-derived
     * ID validation — substituting the default generator would validate phantom
     * IDs the real generator may never produce.
     *
     * @return class-string<TaskIdGeneratorInterface>|null
     */
    private function resolveGeneratorClass(ContainerBuilder $container): ?string
    {
        $definition = $container->findDefinition('soviann_deploy_tasks.id_generator');
        $class = $definition->getClass();

        if (null === $class || !\class_exists($class)) {
            return null;
        }

        if (!\is_a($class, TaskIdGeneratorInterface::class, true)) {
            throw new \LogicException(\sprintf('The configured task ID generator "%s" (service "soviann_deploy_tasks.id_generator", config key "soviann_deploy_tasks.id_generator") must implement %s.', $class, TaskIdGeneratorInterface::class));
        }

        return $class;
    }

    private function wireOptionalDependencies(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition('soviann_deploy_tasks.runner')) {
            return;
        }

        $runnerDefinition = $container->getDefinition('soviann_deploy_tasks.runner');

        // Event dispatcher
        /** @var bool $eventsEnabled */
        $eventsEnabled = $container->getParameter('soviann_deploy_tasks.events.enabled');

        if ($eventsEnabled && $container->has('event_dispatcher')) {
            $runnerDefinition->setArgument('$dispatcher', new Reference('event_dispatcher'));
        } elseif ($eventsEnabled) {
            $container->log(
                $this,
                'Events enabled but symfony/event-dispatcher not available — event dispatching disabled.',
            );
        }

        // Lock factory
        /** @var bool $lockEnabled */
        $lockEnabled = $container->getParameter('soviann_deploy_tasks.lock.enabled');

        if ($lockEnabled && $container->has('lock.factory')) {
            $runnerDefinition->setArgument('$lockFactory', new Reference('lock.factory'));
        } elseif ($lockEnabled) {
            $container->log(
                $this,
                'Lock enabled but symfony/lock not available — concurrent execution protection disabled.',
            );

            if ($container->hasDefinition('soviann_deploy_tasks.command.run')) {
                $container->getDefinition('soviann_deploy_tasks.command.run')
                    ->setArgument('$lockUnavailable', true);
            }
        }

        // Clean up internal parameters
        $container->getParameterBag()->remove('soviann_deploy_tasks.events.enabled');
        $container->getParameterBag()->remove('soviann_deploy_tasks.lock.enabled');
    }
}
