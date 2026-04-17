<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\DependencyInjection\Compiler;

use Soviann\DeployTasksBundle\Attribute\AsDeployTask;
use Soviann\DeployTasksBundle\DeployTaskInterface;
use Soviann\DeployTasksBundle\Exception\IncompatibleStorageException;
use Soviann\DeployTasksBundle\Identifier\DefaultTaskIdGenerator;
use Soviann\DeployTasksBundle\Identifier\TaskIdGeneratorInterface;
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
     */
    private function validateAllOrNothingStorage(ContainerBuilder $container): void
    {
        if (!$container->hasParameter('deploy_tasks.runner.all_or_nothing')) {
            return;
        }

        /** @var bool $allOrNothing */
        $allOrNothing = $container->getParameter('deploy_tasks.runner.all_or_nothing');
        $container->getParameterBag()->remove('deploy_tasks.runner.all_or_nothing');

        if (!$allOrNothing) {
            return;
        }

        $class = $container->findDefinition('deploy_tasks.storage')->getClass();

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
     * Deferred to the compiler pass because the user's service definition is not
     * visible during extension loading (which runs in an isolated temp container).
     */
    private function maybeAliasTransactionalCustomStorage(ContainerBuilder $container): void
    {
        if (!$container->hasParameter('deploy_tasks.storage.custom_service_id')) {
            return;
        }

        /** @var string $customServiceId */
        $customServiceId = $container->getParameter('deploy_tasks.storage.custom_service_id');
        $container->getParameterBag()->remove('deploy_tasks.storage.custom_service_id');

        $class = $container->findDefinition($customServiceId)->getClass();

        if (null !== $class && \is_a($class, TransactionalStorageInterface::class, true)) {
            $container->setAlias(TransactionalStorageInterface::class, 'deploy_tasks.storage')->setPublic(true);
        }
    }

    /**
     * Validates at compile time that no two tagged tasks resolve to the same ID.
     *
     * When a custom generator is configured, its generateStatic() is called for
     * each task without an explicit attribute ID. Returning null opts that task
     * out of compile-time duplicate detection.
     */
    private function validateTaggedTasks(ContainerBuilder $container): void
    {
        $generatorClass = $this->resolveGeneratorClass($container);
        $taggedServices = $container->findTaggedServiceIds('deploy_tasks.task');

        /** @var array<string, string> $seenIds resolved task ID → service ID */
        $seenIds = [];

        foreach ($taggedServices as $serviceId => $tags) {
            $definition = $container->getDefinition($serviceId);
            /** @var class-string|null $class */
            $class = $definition->getClass();

            if (null === $class || !\class_exists($class)) {
                continue;
            }

            if (!\is_subclass_of($class, DeployTaskInterface::class)) {
                $implements = \class_implements($class);

                if (false === $implements || !\in_array(DeployTaskInterface::class, $implements, true)) {
                    continue;
                }
            }

            $attributeId = $this->readAttributeId($class);

            if ('' !== $attributeId) {
                $taskId = $attributeId;
            } else {
                $taskId = $generatorClass::generateStatic($class);

                if (null === $taskId) {
                    continue; // can't know ID at compile time — skip
                }
            }

            if (isset($seenIds[$taskId])) {
                throw new \LogicException(\sprintf('Duplicate deploy task ID "%s" found in services "%s" and "%s".', $taskId, $seenIds[$taskId], $serviceId));
            }

            $seenIds[$taskId] = $serviceId;
        }
    }

    /**
     * Returns the FQCN of the configured task ID generator.
     *
     * @return class-string<TaskIdGeneratorInterface>
     */
    private function resolveGeneratorClass(ContainerBuilder $container): string
    {
        $definition = $container->findDefinition('deploy_tasks.id_generator');
        $class = $definition->getClass();

        if (null === $class || !\class_exists($class)) {
            return DefaultTaskIdGenerator::class;
        }

        \assert(\is_a($class, TaskIdGeneratorInterface::class, true));

        return $class;
    }

    /**
     * Reads the #[AsDeployTask] attribute id from a class, or '' if absent/empty.
     *
     * @param class-string $className
     */
    private function readAttributeId(string $className): string
    {
        $attribute = AsDeployTask::of($className);

        return null !== $attribute ? $attribute->id : '';
    }

    private function wireOptionalDependencies(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition('deploy_tasks.runner')) {
            return;
        }

        $runnerDefinition = $container->getDefinition('deploy_tasks.runner');

        // Event dispatcher (argument index 4)
        /** @var bool $eventsEnabled */
        $eventsEnabled = $container->getParameter('deploy_tasks.events.enabled');

        if ($eventsEnabled && $container->has('event_dispatcher')) {
            $runnerDefinition->setArgument(4, new Reference('event_dispatcher'));
        } elseif ($eventsEnabled) {
            $container->log($this, 'Events enabled but symfony/event-dispatcher not available — event dispatching disabled.');
        }

        // Lock factory (argument index 5)
        /** @var bool $lockEnabled */
        $lockEnabled = $container->getParameter('deploy_tasks.lock.enabled');

        if ($lockEnabled && $container->has('lock.factory')) {
            $runnerDefinition->setArgument(5, new Reference('lock.factory'));
        } elseif ($lockEnabled) {
            $container->log($this, 'Lock enabled but symfony/lock not available — concurrent execution protection disabled.');
        }

        // Clean up internal parameters
        $container->getParameterBag()->remove('deploy_tasks.events.enabled');
        $container->getParameterBag()->remove('deploy_tasks.lock.enabled');
    }
}
