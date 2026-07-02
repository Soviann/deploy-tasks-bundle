<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\DependencyInjection\Compiler;

use Soviann\DeployTasksBundle\Attribute\AsDeployTask;
use Soviann\DeployTasksBundle\DeployTaskInterface;
use Soviann\DeployTasksBundle\Exception\IncompatibleStorageException;
use Soviann\DeployTasksBundle\Identifier\DefaultTaskIdGenerator;
use Soviann\DeployTasksBundle\Identifier\TaskIdGeneratorInterface;
use Soviann\DeployTasksBundle\Identifier\TaskIdProviderInterface;
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
     * @throws IncompatibleStorageException When all_or_nothing is set with a non-transactional storage
     * @throws IncompatibleStorageException When the custom storage service does not implement TaskStorageInterface
     * @throws \LogicException              When two tagged tasks resolve to the same ID
     * @throws \LogicException              When a task ID exceeds the configured id_column_length
     * @throws \LogicException              When a task group exceeds the configured group_column_length
     * @throws \ReflectionException         When the #[AsDeployTask] attribute lookup fails on a tagged class
     */
    public function process(ContainerBuilder $container): void
    {
        $this->validateTaggedTasks($container);
        $this->wireOptionalDependencies($container);
        $this->maybeAliasTransactionalCustomStorage($container);
        $this->validateAllOrNothingStorage($container);
    }

    /**
     * When `all_or_nothing` is enabled, the configured storage MUST implement
     * TransactionalStorageInterface — otherwise a partial run cannot be rolled back.
     *
     * Deferred to the compiler pass because custom storage services are not visible
     * during extension loading.
     *
     * @throws IncompatibleStorageException
     */
    private function validateAllOrNothingStorage(ContainerBuilder $container): void
    {
        if (!$container->hasParameter('soviann_deploy_tasks.runner.all_or_nothing')) {
            return;
        }

        /** @var bool $allOrNothing */
        $allOrNothing = $container->getParameter('soviann_deploy_tasks.runner.all_or_nothing');
        $container->getParameterBag()->remove('soviann_deploy_tasks.runner.all_or_nothing');

        if (!$allOrNothing) {
            return;
        }

        $class = $container->findDefinition('soviann_deploy_tasks.storage')->getClass();

        if (null === $class) {
            return;
        }

        if (!\is_a($class, TransactionalStorageInterface::class, true)) {
            throw IncompatibleStorageException::allOrNothingRequiresTransactional($class);
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

        $this->validateCustomTransactionalStorage($container, $customServiceId, $class);
    }

    /**
     * When custom storage is configured with transactional: true, the service class
     * must implement TransactionalStorageInterface — otherwise there is nothing to
     * wrap per-task executions in.
     *
     * Reads the transactional flag from the runner's constructor argument by name
     * ($transactional), which is set to `$activeStorage['transactional']` by the
     * extension — true only when `storage.type=custom` and
     * `storage.custom.transactional=true`.
     *
     * @throws IncompatibleStorageException
     */
    private function validateCustomTransactionalStorage(
        ContainerBuilder $container,
        string $customServiceId,
        ?string $customStorageClass,
    ): void {
        if (!$container->hasDefinition('soviann_deploy_tasks.runner')) {
            return;
        }

        $runnerDefinition = $container->findDefinition('soviann_deploy_tasks.runner');
        $transactional = $runnerDefinition->getArgument('$transactional');

        if (true !== $transactional) {
            return;
        }

        if (null === $customStorageClass || !\is_a($customStorageClass, TransactionalStorageInterface::class, true)) {
            throw new IncompatibleStorageException(\sprintf('Custom storage "%s" is configured with transactional: true but does not implement %s.', $customStorageClass ?? $customServiceId, TransactionalStorageInterface::class));
        }
    }

    /**
     * Validates at compile time that no two tagged tasks resolve to the same ID.
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
     * out of compile-time duplicate detection.
     *
     * When the active storage is database-backed, also enforces that each task ID
     * and group fits the configured DBAL column length — the attribute itself is
     * storage-agnostic, so the limit can only be checked once storage is known.
     *
     * @throws \LogicException      When two tagged tasks resolve to the same ID
     * @throws \LogicException      When a task ID exceeds the configured id_column_length
     * @throws \LogicException      When a task group exceeds the configured group_column_length
     * @throws \ReflectionException When the #[AsDeployTask] attribute lookup fails
     */
    private function validateTaggedTasks(ContainerBuilder $container): void
    {
        $generatorClass = $this->resolveGeneratorClass($container);
        $taggedServices = $container->findTaggedServiceIds('soviann_deploy_tasks.task');
        [$idColumnLength, $groupColumnLength] = $this->resolveStorageColumnLengths($container);

        /** @var array<string, string> $seenIds resolved task ID → service ID */
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
                $taskId = $generatorClass::generateStatic($class);

                if (null === $taskId) {
                    continue; // can't know ID at compile time — skip
                }
            }

            if (null !== $idColumnLength && \strlen($taskId) > $idColumnLength) {
                throw new \LogicException(\sprintf('Deploy task ID "%s" (service "%s") is %d characters, exceeding the configured id_column_length of %d. Increase soviann_deploy_tasks.storage.database.id_column_length or shorten the task ID.', $taskId, $serviceId, \strlen($taskId), $idColumnLength));
            }

            if (isset($seenIds[$taskId])) {
                throw new \LogicException(\sprintf('Duplicate deploy task ID "%s" found in services "%s" and "%s".', $taskId, $seenIds[$taskId], $serviceId));
            }

            $seenIds[$taskId] = $serviceId;
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
     * Returns the FQCN of the configured task ID generator.
     *
     * @return class-string<TaskIdGeneratorInterface>
     */
    private function resolveGeneratorClass(ContainerBuilder $container): string
    {
        $definition = $container->findDefinition('soviann_deploy_tasks.id_generator');
        $class = $definition->getClass();

        if (null === $class || !\class_exists($class)) {
            return DefaultTaskIdGenerator::class;
        }

        \assert(\is_a($class, TaskIdGeneratorInterface::class, true));

        return $class;
    }

    private function wireOptionalDependencies(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition('soviann_deploy_tasks.runner')) {
            return;
        }

        $runnerDefinition = $container->getDefinition('soviann_deploy_tasks.runner');

        // Event dispatcher (argument index 5)
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

        // Lock factory (argument index 6)
        /** @var bool $lockEnabled */
        $lockEnabled = $container->getParameter('soviann_deploy_tasks.lock.enabled');

        if ($lockEnabled && $container->has('lock.factory')) {
            $runnerDefinition->setArgument('$lockFactory', new Reference('lock.factory'));
        } elseif ($lockEnabled) {
            $container->log(
                $this,
                'Lock enabled but symfony/lock not available — concurrent execution protection disabled.',
            );
        }

        // Clean up internal parameters
        $container->getParameterBag()->remove('soviann_deploy_tasks.events.enabled');
        $container->getParameterBag()->remove('soviann_deploy_tasks.lock.enabled');
    }
}
