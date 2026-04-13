<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle;

use Doctrine\DBAL\Connection;
use Soviann\DeployTasks\Contract\DeployTaskInterface;
use Soviann\DeployTasks\Contract\TaskIdResolverInterface;
use Soviann\DeployTasks\Contract\TaskOrderResolverInterface;
use Soviann\DeployTasks\Contract\TaskStorageInterface;
use Soviann\DeployTasks\Contract\TransactionalStorageInterface;
use Soviann\DeployTasks\DefaultTaskIdResolver;
use Soviann\DeployTasks\DefaultTaskOrderResolver;
use Soviann\DeployTasks\Storage\DbalStorage;
use Soviann\DeployTasks\Storage\FilesystemStorage;
use Soviann\DeployTasks\TaskRegistry;
use Soviann\DeployTasks\TaskRunner;
use Soviann\DeployTasksBundle\Command\DeployTasksGenerateCommand;
use Soviann\DeployTasksBundle\Command\DeployTasksResetCommand;
use Soviann\DeployTasksBundle\Command\DeployTasksRunCommand;
use Soviann\DeployTasksBundle\Command\DeployTasksSkipCommand;
use Soviann\DeployTasksBundle\Command\DeployTasksStatusCommand;
use Soviann\DeployTasksBundle\DependencyInjection\RegisterTasksCompilerPass;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Loader\Configurator\ServicesConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

use function Symfony\Component\DependencyInjection\Loader\Configurator\param;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;
use function Symfony\Component\DependencyInjection\Loader\Configurator\tagged_iterator;

final class DeployTasksBundle extends AbstractBundle
{
    public function getPath(): string
    {
        return __DIR__;
    }

    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->scalarNode('id_resolver')
                    ->defaultNull()
                    ->info('Service ID of a custom TaskIdResolverInterface, or null for the default resolver.')
                ->end()
                ->scalarNode('order_resolver')
                    ->defaultNull()
                    ->info('Service ID of a custom TaskOrderResolverInterface, or null for the default resolver.')
                ->end()
                ->integerNode('default_timeout')
                    ->defaultValue(300)
                    ->min(0)
                    ->info('Default task execution timeout in seconds.')
                ->end()
                ->arrayNode('storage')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->enumNode('type')
                            ->values(['filesystem', 'database'])
                            ->defaultValue('filesystem')
                        ->end()
                        ->arrayNode('filesystem')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->scalarNode('path')
                                    ->defaultValue('%kernel.project_dir%/var/deploy-tasks')
                                ->end()
                            ->end()
                        ->end()
                        ->arrayNode('database')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->scalarNode('connection')
                                    ->defaultValue('default')
                                ->end()
                                ->scalarNode('table')
                                    ->defaultValue('deploy_task_executions')
                                ->end()
                                ->booleanNode('transaction_wrap')
                                    ->defaultFalse()
                                ->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('events')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')
                            ->defaultTrue()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('lock')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')
                            ->defaultTrue()
                        ->end()
                    ->end()
                ->end()
            ->end()
        ;
    }

    /**
     * @param array<string, mixed> $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $services = $container->services();

        // Autoconfigure: tag classes implementing DeployTaskInterface
        $services->instanceof(DeployTaskInterface::class)
            ->tag('deploy_tasks.task')
        ;

        // TaskRegistry
        $services->set('deploy_tasks.registry', TaskRegistry::class)
            ->args([
                tagged_iterator('deploy_tasks.task'),
                service('deploy_tasks.id_resolver'),
            ])
        ;
        $services->alias(TaskRegistry::class, 'deploy_tasks.registry')->public();

        // ID resolver
        $this->registerIdResolver($config, $services);

        // Storage
        $this->registerStorage($config, $services, $builder);

        // Order resolver
        $this->registerOrderResolver($config, $services);

        // Config flags for compiler pass
        /** @var array{enabled: bool} $eventsConfig */
        $eventsConfig = $config['events'];
        /** @var array{enabled: bool} $lockConfig */
        $lockConfig = $config['lock'];
        $builder->setParameter('deploy_tasks.events.enabled', $eventsConfig['enabled']);
        $builder->setParameter('deploy_tasks.lock.enabled', $lockConfig['enabled']);

        // TaskRunner
        $services->set('deploy_tasks.runner', TaskRunner::class)
            ->args([
                service('deploy_tasks.registry'),
                service('deploy_tasks.storage'),
                service('deploy_tasks.order_resolver'),
                service('deploy_tasks.id_resolver'),
                null, // dispatcher — set by compiler pass
                null, // lock factory — set by compiler pass
                $config['default_timeout'],
                param('kernel.environment'),
            ])
        ;
        $services->alias(TaskRunner::class, 'deploy_tasks.runner')->public();

        // Commands
        $services->set('deploy_tasks.command.run', DeployTasksRunCommand::class)
            ->args([service('deploy_tasks.runner')])
            ->tag('console.command')
        ;

        $services->set('deploy_tasks.command.status', DeployTasksStatusCommand::class)
            ->args([
                service('deploy_tasks.registry'),
                service('deploy_tasks.storage'),
            ])
            ->tag('console.command')
        ;

        $services->set('deploy_tasks.command.skip', DeployTasksSkipCommand::class)
            ->args([
                service('deploy_tasks.registry'),
                service('deploy_tasks.storage'),
            ])
            ->tag('console.command')
        ;

        $services->set('deploy_tasks.command.reset', DeployTasksResetCommand::class)
            ->args([
                service('deploy_tasks.registry'),
                service('deploy_tasks.storage'),
            ])
            ->tag('console.command')
        ;

        $services->set('deploy_tasks.command.generate', DeployTasksGenerateCommand::class)
            ->tag('console.command')
        ;
    }

    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        $container->registerForAutoconfiguration(DeployTaskInterface::class)
            ->addTag('deploy_tasks.task')
        ;

        $container->addCompilerPass(new RegisterTasksCompilerPass());
    }

    /**
     * @param array<string, mixed> $config
     */
    private function registerStorage(array $config, ServicesConfigurator $services, ContainerBuilder $builder): void
    {
        /** @var array{type: string, filesystem: array{path: string}, database: array{connection: string, table: string, transaction_wrap: bool}} $storageConfig */
        $storageConfig = $config['storage'];

        if ('database' === $storageConfig['type']) {
            if (!\class_exists(Connection::class)) {
                throw new \LogicException('Storage type "database" requires doctrine/dbal. Run "composer require doctrine/dbal".');
            }

            $connectionServiceId = \sprintf('doctrine.dbal.%s_connection', $storageConfig['database']['connection']);

            $services->set('deploy_tasks.storage', DbalStorage::class)
                ->args([
                    service($connectionServiceId),
                    $storageConfig['database']['table'],
                ])
            ;

            $services->alias(TaskStorageInterface::class, 'deploy_tasks.storage')->public();
            $services->alias(TransactionalStorageInterface::class, 'deploy_tasks.storage')->public();
        } else {
            $services->set('deploy_tasks.storage', FilesystemStorage::class)
                ->args([$storageConfig['filesystem']['path']])
            ;

            $services->alias(TaskStorageInterface::class, 'deploy_tasks.storage')->public();
        }
    }

    /**
     * @param array<string, mixed> $config
     */
    private function registerIdResolver(array $config, ServicesConfigurator $services): void
    {
        /** @var string|null $resolverServiceId */
        $resolverServiceId = $config['id_resolver'];

        if (null !== $resolverServiceId) {
            $services->alias('deploy_tasks.id_resolver', $resolverServiceId);
        } else {
            $services->set('deploy_tasks.id_resolver', DefaultTaskIdResolver::class);
        }

        $services->alias(TaskIdResolverInterface::class, 'deploy_tasks.id_resolver')->public();
    }

    /**
     * @param array<string, mixed> $config
     */
    private function registerOrderResolver(array $config, ServicesConfigurator $services): void
    {
        /** @var string|null $resolverServiceId */
        $resolverServiceId = $config['order_resolver'];

        if (null !== $resolverServiceId) {
            $services->alias('deploy_tasks.order_resolver', $resolverServiceId);
        } else {
            $services->set('deploy_tasks.order_resolver', DefaultTaskOrderResolver::class)
                ->args([service('deploy_tasks.id_resolver')])
            ;
        }

        $services->alias(TaskOrderResolverInterface::class, 'deploy_tasks.order_resolver')->public();
    }
}
