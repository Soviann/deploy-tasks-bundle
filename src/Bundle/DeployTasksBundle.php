<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle;

use Doctrine\DBAL\Connection;
use Soviann\DeployTasks\Contract\DeployTaskInterface;
use Soviann\DeployTasks\Contract\TaskIdGeneratorInterface;
use Soviann\DeployTasks\Contract\TaskIdResolverInterface;
use Soviann\DeployTasks\Contract\TaskOrderResolverInterface;
use Soviann\DeployTasks\Contract\TaskStorageInterface;
use Soviann\DeployTasks\Contract\TransactionalStorageInterface;
use Soviann\DeployTasks\DefaultTaskIdGenerator;
use Soviann\DeployTasks\DefaultTaskIdResolver;
use Soviann\DeployTasks\DefaultTaskOrderResolver;
use Soviann\DeployTasks\Storage\DbalStorage;
use Soviann\DeployTasks\Storage\DbalStorageConfiguration;
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
                ->scalarNode('id_generator')
                    ->defaultNull()
                    ->info('Service ID of a custom TaskIdGeneratorInterface, or null for the default generator.')
                ->end()
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
                ->booleanNode('transactional')
                    ->defaultTrue()
                    ->info('Wrap each task execution in a database transaction (requires DbalStorage). Overridable per-task via #[AsDeployTask(transactional: false)].')
                ->end()
                ->booleanNode('all_or_nothing')
                    ->defaultFalse()
                    ->info('Wrap the entire run in a single transaction — any failure rolls back all tasks.')
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
                                ->booleanNode('auto_create_table')
                                    ->defaultTrue()
                                    ->info('Automatically create the storage table on first use (like Doctrine Migrations).')
                                ->end()
                                ->scalarNode('id_column')
                                    ->defaultValue('id')
                                ->end()
                                ->integerNode('id_column_length')
                                    ->defaultValue(255)
                                    ->min(1)
                                ->end()
                                ->scalarNode('status_column')
                                    ->defaultValue('status')
                                ->end()
                                ->scalarNode('executed_at_column')
                                    ->defaultValue('executed_at')
                                ->end()
                                ->scalarNode('error_column')
                                    ->defaultValue('error')
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
                ->arrayNode('generate')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('directory')
                            ->defaultValue('src/DeployTasks/Task/')
                            ->info('Default directory for deploytasks:generate output.')
                        ->end()
                        ->scalarNode('template')
                            ->defaultNull()
                            ->info('Path to a custom PHP template file for deploytasks:generate.')
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

        // ID generator
        $this->registerIdGenerator($config, $services);

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
                $config['transactional'],
                $config['all_or_nothing'],
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

        /** @var array{directory: string, template: string|null} $generateConfig */
        $generateConfig = $config['generate'];

        $services->set('deploy_tasks.command.generate', DeployTasksGenerateCommand::class)
            ->args([
                service('deploy_tasks.id_generator'),
                $generateConfig['directory'],
                $generateConfig['template'],
            ])
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
        /** @var array{type: string, filesystem: array{path: string}, database: array{connection: string, table: string, auto_create_table: bool, id_column: string, id_column_length: int, status_column: string, executed_at_column: string, error_column: string}} $storageConfig */
        $storageConfig = $config['storage'];

        if ('database' === $storageConfig['type']) {
            if (!\class_exists(Connection::class)) {
                throw new \LogicException('Storage type "database" requires doctrine/dbal. Run "composer require doctrine/dbal".');
            }

            $connectionServiceId = \sprintf('doctrine.dbal.%s_connection', $storageConfig['database']['connection']);
            $dbConfig = $storageConfig['database'];

            $services->set('deploy_tasks.storage.configuration', DbalStorageConfiguration::class)
                ->args([
                    $dbConfig['auto_create_table'],  // autoCreateTable
                    $dbConfig['error_column'],        // errorColumn
                    $dbConfig['executed_at_column'],  // executedAtColumn
                    $dbConfig['id_column'],           // idColumn
                    $dbConfig['id_column_length'],    // idColumnLength
                    $dbConfig['status_column'],       // statusColumn
                    $dbConfig['table'],               // tableName
                ])
            ;

            $services->set('deploy_tasks.storage', DbalStorage::class)
                ->args([
                    service($connectionServiceId),
                    service('deploy_tasks.storage.configuration'),
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
    private function registerIdGenerator(array $config, ServicesConfigurator $services): void
    {
        /** @var string|null $generatorServiceId */
        $generatorServiceId = $config['id_generator'];

        $services->set('deploy_tasks.default_id_generator', DefaultTaskIdGenerator::class);

        if (null !== $generatorServiceId) {
            $services->alias('deploy_tasks.id_generator', $generatorServiceId);
        } else {
            $services->alias('deploy_tasks.id_generator', 'deploy_tasks.default_id_generator');
        }

        $services->alias(TaskIdGeneratorInterface::class, 'deploy_tasks.id_generator')->public();
    }

    /**
     * @param array<string, mixed> $config
     */
    private function registerIdResolver(array $config, ServicesConfigurator $services): void
    {
        /** @var string|null $resolverServiceId */
        $resolverServiceId = $config['id_resolver'];

        $services->set('deploy_tasks.default_id_resolver', DefaultTaskIdResolver::class)
            ->args([service('deploy_tasks.id_generator')])
        ;

        if (null !== $resolverServiceId) {
            $services->alias('deploy_tasks.id_resolver', $resolverServiceId);
        } else {
            $services->alias('deploy_tasks.id_resolver', 'deploy_tasks.default_id_resolver');
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
