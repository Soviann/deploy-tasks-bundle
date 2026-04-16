<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle;

use Doctrine\DBAL\Connection;
use Soviann\DeployTasks\Contract\DeployTaskInterface;
use Soviann\DeployTasks\Contract\TaskIdGeneratorInterface;
use Soviann\DeployTasks\Contract\TaskOrderResolverInterface;
use Soviann\DeployTasks\Contract\TaskStorageInterface;
use Soviann\DeployTasks\Contract\TransactionalStorageInterface;
use Soviann\DeployTasks\DefaultTaskIdGenerator;
use Soviann\DeployTasks\DefaultTaskOrderResolver;
use Soviann\DeployTasks\Storage\DbalStorage;
use Soviann\DeployTasks\Storage\DbalStorageConfiguration;
use Soviann\DeployTasks\Storage\FilesystemStorage;
use Soviann\DeployTasks\TaskIdResolver;
use Soviann\DeployTasks\TaskRegistry;
use Soviann\DeployTasks\TaskRunner;
use Soviann\DeployTasksBundle\Command\DeployTasksCreateSchemaCommand;
use Soviann\DeployTasksBundle\Command\DeployTasksGenerateCommand;
use Soviann\DeployTasksBundle\Command\DeployTasksResetCommand;
use Soviann\DeployTasksBundle\Command\DeployTasksRollupCommand;
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
                            ->values(['filesystem', 'database', 'custom'])
                            ->defaultValue('filesystem')
                        ->end()
                        ->arrayNode('filesystem')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->scalarNode('path')
                                    ->defaultValue('%kernel.project_dir%/var/deploy-tasks')
                                ->end()
                                ->booleanNode('transactional')
                                    ->defaultFalse()
                                    ->info('Wrap each task execution in a transaction. Filesystem storage does not support transactions; this is ignored.')
                                ->end()
                                ->booleanNode('all_or_nothing')
                                    ->defaultFalse()
                                    ->info('Wrap the entire run in a single transaction. Filesystem storage does not support transactions; this is ignored.')
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
                                ->booleanNode('transactional')
                                    ->defaultTrue()
                                    ->info('Wrap each task execution in a database transaction. Overridable per-task via #[AsDeployTask(transactional: false)].')
                                ->end()
                                ->booleanNode('all_or_nothing')
                                    ->defaultTrue()
                                    ->info('Wrap the entire run in a single transaction — any failure rolls back all tasks.')
                                ->end()
                            ->end()
                        ->end()
                        ->arrayNode('custom')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->scalarNode('service')
                                    ->defaultNull()
                                    ->info('Service ID of a custom TaskStorageInterface implementation.')
                                ->end()
                                ->booleanNode('transactional')
                                    ->defaultFalse()
                                    ->info('Wrap each task execution in a transaction (requires the custom storage to implement TransactionalStorageInterface).')
                                ->end()
                                ->booleanNode('all_or_nothing')
                                    ->defaultFalse()
                                    ->info('Wrap the entire run in a single transaction (requires the custom storage to implement TransactionalStorageInterface).')
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

        // ID resolver (internal — not configurable)
        $services->set('deploy_tasks.id_resolver', TaskIdResolver::class)
            ->args([service('deploy_tasks.id_generator')])
        ;

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

        // TaskRunner — transactional/all_or_nothing come from the active storage sub-config
        /** @var array{type: string, filesystem: array{path: string, transactional: bool, all_or_nothing: bool}, database: array{transactional: bool, all_or_nothing: bool}, custom: array{service: string|null, transactional: bool, all_or_nothing: bool}} $storageConfig */
        $storageConfig = $config['storage'];
        /** @var array{transactional: bool, all_or_nothing: bool} $activeStorage */
        $activeStorage = $storageConfig[$storageConfig['type']];

        $builder->setParameter('deploy_tasks.runner.all_or_nothing', $activeStorage['all_or_nothing']);

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
                $activeStorage['transactional'],
                $activeStorage['all_or_nothing'],
            ])
        ;
        $services->alias(TaskRunner::class, 'deploy_tasks.runner')->public();

        // Commands
        $services->set('deploy_tasks.command.run', DeployTasksRunCommand::class)
            ->args([service('deploy_tasks.registry'), service('deploy_tasks.runner')])
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

        $services->set('deploy_tasks.command.rollup', DeployTasksRollupCommand::class)
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
                '%kernel.project_dir%',
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
        /** @var array{type: string, filesystem: array{path: string, transactional: bool, all_or_nothing: bool}, database: array{connection: string, table: string, auto_create_table: bool, id_column: string, id_column_length: int, status_column: string, executed_at_column: string, error_column: string, transactional: bool, all_or_nothing: bool}, custom: array{service: string|null, transactional: bool, all_or_nothing: bool}} $storageConfig */
        $storageConfig = $config['storage'];

        switch ($storageConfig['type']) {
            case 'database':
                if (!\class_exists(Connection::class)) {
                    throw new \LogicException('Storage type "database" requires doctrine/dbal. Run "composer require doctrine/dbal".');
                }

                $connectionServiceId = \sprintf('doctrine.dbal.%s_connection', $storageConfig['database']['connection']);
                $dbConfig = $storageConfig['database'];

                $services->set('deploy_tasks.storage.configuration', DbalStorageConfiguration::class)
                    ->args([
                        '$autoCreateTable' => $dbConfig['auto_create_table'],
                        '$errorColumn' => $dbConfig['error_column'],
                        '$executedAtColumn' => $dbConfig['executed_at_column'],
                        '$idColumn' => $dbConfig['id_column'],
                        '$idColumnLength' => $dbConfig['id_column_length'],
                        '$statusColumn' => $dbConfig['status_column'],
                        '$tableName' => $dbConfig['table'],
                    ])
                ;

                $services->set('deploy_tasks.storage', DbalStorage::class)
                    ->args([
                        service($connectionServiceId),
                        service('deploy_tasks.storage.configuration'),
                    ])
                ;

                $services->alias(TransactionalStorageInterface::class, 'deploy_tasks.storage')->public();

                $services->set('deploy_tasks.command.create_schema', DeployTasksCreateSchemaCommand::class)
                    ->args([service('deploy_tasks.storage')])
                    ->tag('console.command')
                ;

                break;
            case 'filesystem':
                $services->set('deploy_tasks.storage', FilesystemStorage::class)
                    ->args([$storageConfig['filesystem']['path']])
                ;

                break;
            case 'custom':
                $customServiceId = $storageConfig['custom']['service'];

                if (null === $customServiceId) {
                    throw new \InvalidArgumentException('"deploy_tasks.storage.custom.service" must be set when "deploy_tasks.storage.type" is "custom".');
                }

                $services->alias('deploy_tasks.storage', $customServiceId);
                $builder->setParameter('deploy_tasks.storage.custom_service_id', $customServiceId);

                break;
        }

        $services->alias(TaskStorageInterface::class, 'deploy_tasks.storage')->public();
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
