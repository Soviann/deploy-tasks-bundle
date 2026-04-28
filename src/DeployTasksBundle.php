<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle;

use Doctrine\DBAL\Connection;
use Soviann\DeployTasksBundle\Command\DeployTasksCreateSchemaCommand;
use Soviann\DeployTasksBundle\Command\DeployTasksGenerateCommand;
use Soviann\DeployTasksBundle\Command\DeployTasksGenerateHostCommand;
use Soviann\DeployTasksBundle\Command\DeployTasksResetCommand;
use Soviann\DeployTasksBundle\Command\DeployTasksRollupCommand;
use Soviann\DeployTasksBundle\Command\DeployTasksRunCommand;
use Soviann\DeployTasksBundle\Command\DeployTasksShowCommand;
use Soviann\DeployTasksBundle\Command\DeployTasksSkipCommand;
use Soviann\DeployTasksBundle\Command\DeployTasksStatusCommand;
use Soviann\DeployTasksBundle\DependencyInjection\Compiler\RegisterTasksCompilerPass;
use Soviann\DeployTasksBundle\DependencyInjection\Configuration\EventsConfigNode;
use Soviann\DeployTasksBundle\DependencyInjection\Configuration\LockConfigNode;
use Soviann\DeployTasksBundle\DependencyInjection\Configuration\StorageConfigNode;
use Soviann\DeployTasksBundle\Identifier\DefaultTaskIdGenerator;
use Soviann\DeployTasksBundle\Identifier\TaskDescriptionResolver;
use Soviann\DeployTasksBundle\Identifier\TaskIdGeneratorInterface;
use Soviann\DeployTasksBundle\Identifier\TaskIdResolver;
use Soviann\DeployTasksBundle\Runner\TaskRegistry;
use Soviann\DeployTasksBundle\Runner\TaskRunner;
use Soviann\DeployTasksBundle\Sorting\DefaultTaskSorter;
use Soviann\DeployTasksBundle\Sorting\TaskSorterInterface;
use Soviann\DeployTasksBundle\Storage\Dbal\DbalStorage;
use Soviann\DeployTasksBundle\Storage\Dbal\DbalStorageConfiguration;
use Soviann\DeployTasksBundle\Storage\Filesystem\FilesystemStorage;
use Soviann\DeployTasksBundle\Storage\TaskStorageInterface;
use Soviann\DeployTasksBundle\Storage\TransactionalStorageInterface;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Loader\Configurator\ServicesConfigurator;
use Symfony\Component\DependencyInjection\Reference;
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
                ->scalarNode('sorter')
                    ->defaultNull()
                    ->info('Service ID of a custom TaskSorterInterface, or null for the default sorter.')
                ->end()
                ->scalarNode('logger')
                    ->defaultNull()
                    ->info('Service ID of a custom PSR-3 LoggerInterface, or null to auto-detect the application logger (with monolog channel "deploy_tasks" when monolog-bundle is installed).')
                ->end()
                ->integerNode('default_timeout')
                    ->defaultValue(300)
                    ->min(0)
                    ->info('Default task execution timeout in seconds. 0 disables the timeout check entirely (no warning emitted, regardless of duration).')
                ->end()
                ->append((new StorageConfigNode())->buildRoot())
                ->append((new EventsConfigNode())->buildRoot())
                ->append((new LockConfigNode())->buildRoot())
                ->arrayNode('generate')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('directory')
                            ->defaultValue('src/DeployTasks/Task/')
                            ->info('Default directory for deploytasks:generate:container output.')
                        ->end()
                        ->scalarNode('template')
                            ->defaultNull()
                            ->info('Path to a custom PHP template file for deploytasks:generate:container.')
                        ->end()
                    ->end()
                ->end()
            ->end()
        ;
    }

    /**
     * @param array<array-key, mixed> $config
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

        // Description resolver (internal — not configurable)
        $services->set('deploy_tasks.description_resolver', TaskDescriptionResolver::class);

        // Storage
        $this->registerStorage($config, $services, $builder);

        // Sorter
        $this->registerSorter($config, $services);

        // Logger
        $this->registerLogger($config, $services);

        // Config flags for compiler pass
        /** @var array{enabled: bool} $eventsConfig */
        $eventsConfig = $config['events'];
        /** @var array{enabled: bool, ttl: int} $lockConfig */
        $lockConfig = $config['lock'];
        $builder->setParameter('deploy_tasks.events.enabled', $eventsConfig['enabled']);
        $builder->setParameter('deploy_tasks.lock.enabled', $lockConfig['enabled']);

        // TaskRunner — transactional/all_or_nothing come from the active storage sub-config
        /** @var array{type: string, filesystem: array{path: string, transactional: bool, all_or_nothing: bool}, database: array{transactional: bool, all_or_nothing: bool}, custom: array{service: string|null, transactional: bool, all_or_nothing: bool}} $storageConfig */
        $storageConfig = $config['storage'];
        /** @var array{transactional: bool, all_or_nothing: bool} $activeStorage */
        $activeStorage = $storageConfig[$storageConfig['type']];

        $builder->setParameter('deploy_tasks.runner.all_or_nothing', $activeStorage['all_or_nothing']);

        $loggerArg = null !== $config['logger']
            ? service('deploy_tasks.logger')                               // user override — alias created in registerLogger()
            : new Reference('logger', ContainerInterface::NULL_ON_INVALID_REFERENCE); // app logger when present (resolved as null otherwise — TaskRunner falls back to NullLogger)

        $services->set('deploy_tasks.runner', TaskRunner::class)
            ->args([
                service('deploy_tasks.registry'),
                service('deploy_tasks.storage'),
                service('deploy_tasks.sorter'),
                service('deploy_tasks.id_resolver'),
                service('deploy_tasks.description_resolver'),
                null, // dispatcher — set by compiler pass
                null, // lock factory — set by compiler pass
                $config['default_timeout'],
                param('kernel.environment'),
                $activeStorage['transactional'],
                $activeStorage['all_or_nothing'],
                $loggerArg,
                $lockConfig['ttl'],
            ])
            ->tag('monolog.logger', ['channel' => 'deploy_tasks'])
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
                service('deploy_tasks.description_resolver'),
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

        // Manual name/description via tag attributes (no #[AsCommand]) — Doctrine-bundle style.
        $services->set('deploy_tasks.command.show', DeployTasksShowCommand::class)
            ->args([
                service('deploy_tasks.registry'),
                service('deploy_tasks.storage'),
                service('deploy_tasks.description_resolver'),
            ])
            ->tag('console.command', [
                'command' => 'deploytasks:show',
                'description' => 'Show metadata and stored execution records for a single deploy task.',
            ])
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

        $builder->setParameter('env(DEPLOY_TASKS_HOST_DIR)', 'deploy/host-tasks');

        $services->set('deploy_tasks.command.generate.host', DeployTasksGenerateHostCommand::class)
            ->args([
                '%env(DEPLOY_TASKS_HOST_DIR)%',
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

        $container->addCompilerPass(new RegisterTasksCompilerPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION);
    }

    /**
     * @param array<string, mixed> $config
     *
     * @throws \InvalidArgumentException When storage.type is "custom" but no service id is configured
     * @throws \LogicException           When storage.type is "database" without doctrine/dbal installed, or unsupported
     */
    private function registerStorage(array $config, ServicesConfigurator $services, ContainerBuilder $builder): void
    {
        /** @var array{type: string, filesystem: array{path: string, transactional: bool, all_or_nothing: bool}, database: array{connection: string, table: string, auto_create_table: bool, id_column: string, id_column_length: int, status_column: string, executed_at_column: string, error_column: string, group_column: string, group_column_length: int, transactional: bool, all_or_nothing: bool}, custom: array{service: string|null, transactional: bool, all_or_nothing: bool}} $storageConfig */
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
                        '$groupColumn' => $dbConfig['group_column'],
                        '$groupColumnLength' => $dbConfig['group_column_length'],
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
                    ->args([
                        service('deploy_tasks.storage'),
                        service('deploy_tasks.storage.configuration'),
                        $storageConfig['database']['connection'],
                    ])
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
            default:
                // Unreachable via the public configuration: enumNode('type')->values([...])
                // rejects unknown values during Configuration processing. Defensive throw
                // guards against downstream compiler passes mutating the storage type after
                // the enum guard.
                // @codeCoverageIgnoreStart
                throw new \LogicException(\sprintf('Unsupported storage type "%s".', $storageConfig['type']));
                // @codeCoverageIgnoreEnd
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
    private function registerSorter(array $config, ServicesConfigurator $services): void
    {
        /** @var string|null $sorterServiceId */
        $sorterServiceId = $config['sorter'];

        if (null !== $sorterServiceId) {
            $services->alias('deploy_tasks.sorter', $sorterServiceId);
        } else {
            $services->set('deploy_tasks.sorter', DefaultTaskSorter::class)
                ->args([service('deploy_tasks.id_resolver')])
            ;
        }

        $services->alias(TaskSorterInterface::class, 'deploy_tasks.sorter')->public();
    }

    /**
     * @param array<string, mixed> $config
     */
    private function registerLogger(array $config, ServicesConfigurator $services): void
    {
        /** @var string|null $userLoggerId */
        $userLoggerId = $config['logger'];

        if (null !== $userLoggerId) {
            $services->alias('deploy_tasks.logger', $userLoggerId);
        }
        // When null, the runner argument is a NULL_ON_INVALID_REFERENCE to the `logger` service:
        // resolves to the app logger when present (monolog's LoggerChannelPass then rewrites the
        // literal 'logger' reference to the channel-scoped logger via the runner's monolog.logger tag),
        // and TaskRunner falls back to a NullLogger when the app has no logger service.
    }
}
