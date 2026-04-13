<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\DependencyInjection;

use Soviann\DeployTasks\Contract\DeployTaskInterface;
use Soviann\DeployTasks\DefaultTaskIdResolver;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Validates tagged deploy tasks at compile time and wires optional dependencies.
 */
final class RegisterTasksCompilerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        $this->validateTaggedTasks($container);
        $this->wireOptionalDependencies($container);
    }

    /**
     * Validates at compile time that no two tagged tasks resolve to the same ID.
     *
     * Only runs when the default resolver is configured — custom resolvers may
     * use runtime logic that cannot be replicated at compile time.
     */
    private function validateTaggedTasks(ContainerBuilder $container): void
    {
        if (!$this->isDefaultResolverConfigured($container)) {
            return;
        }

        $resolver = new DefaultTaskIdResolver();
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

            if (!\is_subclass_of($class, DeployTaskInterface::class) && !\in_array(DeployTaskInterface::class, \class_implements($class) ?: [], true)) {
                continue;
            }

            $taskId = $resolver->resolveFromClass($class);

            if (isset($seenIds[$taskId])) {
                throw new \LogicException(\sprintf('Duplicate deploy task ID "%s" found in services "%s" and "%s".', $taskId, $seenIds[$taskId], $serviceId));
            }

            $seenIds[$taskId] = $serviceId;
        }
    }

    /**
     * Checks whether the configured ID resolver is the default one.
     */
    private function isDefaultResolverConfigured(ContainerBuilder $container): bool
    {
        if (!$container->hasDefinition('deploy_tasks.id_resolver')) {
            return $container->hasAlias('deploy_tasks.id_resolver')
                && DefaultTaskIdResolver::class === $container->findDefinition('deploy_tasks.id_resolver')->getClass();
        }

        return DefaultTaskIdResolver::class === $container->getDefinition('deploy_tasks.id_resolver')->getClass();
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
