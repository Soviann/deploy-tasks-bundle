<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\DependencyInjection;

use Soviann\DeployTasks\Contract\Attribute\AsDeployTask;
use Soviann\DeployTasks\Contract\DeployTaskInterface;
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

    private function validateTaggedTasks(ContainerBuilder $container): void
    {
        $taggedServices = $container->findTaggedServiceIds('deploy_tasks.task');

        /** @var array<string, string> $seenIds task ID → service ID */
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

            $reflection = new \ReflectionClass($class);
            $attributes = $reflection->getAttributes(AsDeployTask::class);

            if ([] === $attributes) {
                continue;
            }

            /** @var AsDeployTask $attribute */
            $attribute = $attributes[0]->newInstance();
            $taskId = $attribute->id;

            if (isset($seenIds[$taskId])) {
                throw new \LogicException(\sprintf('Duplicate deploy task ID "%s" found in services "%s" and "%s".', $taskId, $seenIds[$taskId], $serviceId));
            }

            $seenIds[$taskId] = $serviceId;
        }
    }

    private function wireOptionalDependencies(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition('deploy_tasks.runner')) {
            return;
        }

        $runnerDefinition = $container->getDefinition('deploy_tasks.runner');

        // Event dispatcher (argument index 3)
        /** @var bool $eventsEnabled */
        $eventsEnabled = $container->getParameter('deploy_tasks.events.enabled');

        if ($eventsEnabled && $container->has('event_dispatcher')) {
            $runnerDefinition->setArgument(3, new Reference('event_dispatcher'));
        } elseif ($eventsEnabled) {
            $container->log($this, 'Events enabled but symfony/event-dispatcher not available — event dispatching disabled.');
        }

        // Lock factory (argument index 4)
        /** @var bool $lockEnabled */
        $lockEnabled = $container->getParameter('deploy_tasks.lock.enabled');

        if ($lockEnabled && $container->has('lock.factory')) {
            $runnerDefinition->setArgument(4, new Reference('lock.factory'));
        } elseif ($lockEnabled) {
            $container->log($this, 'Lock enabled but symfony/lock not available — concurrent execution protection disabled.');
        }

        // Clean up internal parameters
        $container->getParameterBag()->remove('deploy_tasks.events.enabled');
        $container->getParameterBag()->remove('deploy_tasks.lock.enabled');
    }
}
