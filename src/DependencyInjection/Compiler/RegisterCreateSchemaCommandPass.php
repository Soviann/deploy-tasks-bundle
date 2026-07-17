<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\DependencyInjection\Compiler;

use Soviann\DeployTasksBundle\Command\DeployTasksCreateSchemaCommand;
use Soviann\DeployTasksBundle\Storage\SchemaManageableInterface;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Registers `deploytasks:create-schema` for any configured storage that
 * implements SchemaManageableInterface — capability-based, so a custom backend
 * opts in by implementing the interface, exactly like the built-in database
 * storage does.
 *
 * Deferred to a compiler pass because a custom storage service's class is only
 * visible once the host's own definitions are loaded. A storage whose class is
 * unresolvable here — synthetic services, child definitions (resolved only in
 * the later optimization phase), or a `%parameter%` placeholder — is skipped
 * conservatively: no registration, no crash, no false positive.
 *
 * @internal
 */
final class RegisterCreateSchemaCommandPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        $connectionName = $this->consumeConnectionName($container);

        if (!$container->has('soviann_deploy_tasks.storage')) {
            return;
        }

        $class = $container->findDefinition('soviann_deploy_tasks.storage')->getClass();

        if (null === $class || !\class_exists($class) || !\is_a($class, SchemaManageableInterface::class, true)) {
            return;
        }

        $definition = new Definition(DeployTasksCreateSchemaCommand::class);
        $definition->setArgument('$storage', new Reference('soviann_deploy_tasks.storage'));

        // Built-in database storage: wire the DBAL-specific details so the
        // success message can name the table, columns, and connection.
        if (null !== $connectionName && $container->hasDefinition('soviann_deploy_tasks.storage.configuration')) {
            $definition->setArgument('$configuration', new Reference('soviann_deploy_tasks.storage.configuration'));
            $definition->setArgument('$connectionName', $connectionName);
        }

        $definition->addTag('console.command');
        $container->setDefinition('soviann_deploy_tasks.command.create_schema', $definition);
    }

    /**
     * Reads and removes the internal connection-name parameter set by the
     * extension for the built-in database storage. Null for every other backend.
     */
    private function consumeConnectionName(ContainerBuilder $container): ?string
    {
        if (!$container->hasParameter('soviann_deploy_tasks.storage.dbal_connection_name')) {
            return null;
        }

        /** @var string $connectionName */
        $connectionName = $container->getParameter('soviann_deploy_tasks.storage.dbal_connection_name');
        $container->getParameterBag()->remove('soviann_deploy_tasks.storage.dbal_connection_name');

        return $connectionName;
    }
}
