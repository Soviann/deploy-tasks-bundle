<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle;

use Soviann\DeployTasksBundle\Command\DeployTasksCreateSchemaCommand;
use Soviann\DeployTasksBundle\Command\DeployTasksGenerateCommand;
use Soviann\DeployTasksBundle\Command\DeployTasksGenerateHostCommand;
use Soviann\DeployTasksBundle\Command\DeployTasksResetCommand;
use Soviann\DeployTasksBundle\Command\DeployTasksResetHostCommand;
use Soviann\DeployTasksBundle\Command\DeployTasksRollupCommand;
use Soviann\DeployTasksBundle\Command\DeployTasksRollupHostCommand;
use Soviann\DeployTasksBundle\Command\DeployTasksRunCommand;
use Soviann\DeployTasksBundle\Command\DeployTasksShowCommand;
use Soviann\DeployTasksBundle\Command\DeployTasksSkipCommand;
use Soviann\DeployTasksBundle\Command\DeployTasksSkipHostCommand;
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

/**
 * @phpstan-type StorageConfig array{
 *     type: string,
 *     filesystem: array{path: string},
 *     database: array{
 *         connection: string,
 *         table: string,
 *         auto_create_table: bool,
 *         id_column: string,
 *         id_column_length: int,
 *         status_column: string,
 *         executed_at_column: string,
 *         error_column: string,
 *         group_column: string,
 *         group_column_length: int,
 *         transactional: bool,
 *         all_or_nothing: bool,
 *     },
 *     custom: array{service: string|null, transactional: bool, all_or_nothing: bool},
 * }
 * @phpstan-type GenerateConfig array{
 *     directory: string,
 *     template: string|null,
 *     root_namespace: string,
 *     host_directory: string,
 * }
 */
final class SoviannDeployTasksBundle extends AbstractBundle
{
    public function getPath(): string
    {
        // Package root, so config/, templates/, translations/ resolve correctly if ever added.
        return \dirname(__DIR__);
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
                    ->info('Service ID of a custom PSR-3 LoggerInterface, or null to auto-detect the application logger (with monolog channel "soviann_deploy_tasks" when monolog-bundle is installed).')
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
                        ->scalarNode('root_namespace')
                            ->defaultValue('App')
                            ->cannotBeEmpty()
                            ->info('Root namespace for deploytasks:generate:container output when --dir starts with "src/" (mirrors symfony/maker-bundle\'s root_namespace). Set to your composer.json PSR-4 root if it is not "App".')
                        ->end()
                        ->scalarNode('host_directory')
                            ->defaultValue('%kernel.project_dir%/deploy/host-tasks')
                            ->cannotBeEmpty()
                            ->info('Where deploytasks:generate:host writes new host-scope task stubs.')
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

        $builder->registerForAutoconfiguration(DeployTaskInterface::class)
            ->addTag('soviann_deploy_tasks.task')
        ;

        // TaskRegistry
        $services->set('soviann_deploy_tasks.registry', TaskRegistry::class)
            ->args([
                tagged_iterator('soviann_deploy_tasks.task'),
                service('soviann_deploy_tasks.id_resolver'),
            ])
        ;
        $services->alias(TaskRegistry::class, 'soviann_deploy_tasks.registry');

        // ID generator
        $this->registerIdGenerator($config, $services);

        // ID resolver (internal — not configurable)
        $services->set('soviann_deploy_tasks.id_resolver', TaskIdResolver::class)
            ->args([service('soviann_deploy_tasks.id_generator')])
        ;

        // Description resolver (internal — not configurable)
        $services->set('soviann_deploy_tasks.description_resolver', TaskDescriptionResolver::class);

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
        $builder->setParameter('soviann_deploy_tasks.events.enabled', $eventsConfig['enabled']);
        $builder->setParameter('soviann_deploy_tasks.lock.enabled', $lockConfig['enabled']);

        // TaskRunner — transactional/all_or_nothing come from the active storage sub-config.
        // Filesystem storage is inherently non-transactional (no config keys for it).
        /**
         * @var array{
         *     type: string,
         *     filesystem: array{path: string},
         *     database: array{transactional: bool, all_or_nothing: bool},
         *     custom: array{service: string|null, transactional: bool, all_or_nothing: bool},
         * } $storageConfig
         */
        $storageConfig = $config['storage'];

        if ('filesystem' === $storageConfig['type']) {
            $transactional = false;
            $allOrNothing = false;
        } else {
            /** @var array{transactional: bool, all_or_nothing: bool} $activeStorage */
            $activeStorage = $storageConfig[$storageConfig['type']];
            $transactional = $activeStorage['transactional'];
            $allOrNothing = $activeStorage['all_or_nothing'];
        }

        $builder->setParameter('soviann_deploy_tasks.runner.all_or_nothing', $allOrNothing);

        $services->set('soviann_deploy_tasks.runner', TaskRunner::class)
            ->args([
                '$registry' => service('soviann_deploy_tasks.registry'),
                '$storage' => service('soviann_deploy_tasks.storage'),
                '$sorter' => service('soviann_deploy_tasks.sorter'),
                '$idResolver' => service('soviann_deploy_tasks.id_resolver'),
                '$descriptionResolver' => service('soviann_deploy_tasks.description_resolver'),
                '$dispatcher' => null, // set by compiler pass
                '$lockFactory' => null, // set by compiler pass
                '$defaultTimeout' => $config['default_timeout'],
                '$environment' => param('kernel.environment'),
                '$transactional' => $transactional,
                '$allOrNothing' => $allOrNothing,
                '$logger' => null, // set below
                '$lockTtl' => $lockConfig['ttl'],
            ])
        ;

        $runnerDefinition = $builder->getDefinition('soviann_deploy_tasks.runner');

        if (null === $config['logger']) {
            // Bundle owns the logger — tag it for Monolog channel routing.
            $runnerDefinition->addTag('monolog.logger', ['channel' => 'soviann_deploy_tasks']);
            $runnerDefinition->setArgument(
                '$logger',
                new Reference('logger', ContainerInterface::NULL_ON_INVALID_REFERENCE),
            );
        } else {
            /** @var string $userLoggerId */
            $userLoggerId = $config['logger'];
            // User supplied a logger service — route as-is, no channel tag.
            $runnerDefinition->setArgument('$logger', new Reference($userLoggerId));
        }
        $services->alias(TaskRunner::class, 'soviann_deploy_tasks.runner');

        // Commands
        $services->set('soviann_deploy_tasks.command.run', DeployTasksRunCommand::class)
            ->args([service('soviann_deploy_tasks.registry'), service('soviann_deploy_tasks.runner')])
            ->tag('console.command')
        ;

        /** @var GenerateConfig $generateConfig */
        $generateConfig = $config['generate'];

        $services->set('soviann_deploy_tasks.command.status', DeployTasksStatusCommand::class)
            ->args([
                '$registry' => service('soviann_deploy_tasks.registry'),
                '$storage' => service('soviann_deploy_tasks.storage'),
                '$descriptionResolver' => service('soviann_deploy_tasks.description_resolver'),
                '$hostTasksDir' => $generateConfig['host_directory'],
                '$hostLogPath' => '%kernel.project_dir%/.deploy-tasks-host.log',
            ])
            ->tag('console.command')
        ;

        $services->set('soviann_deploy_tasks.command.skip', DeployTasksSkipCommand::class)
            ->args([
                service('soviann_deploy_tasks.registry'),
                service('soviann_deploy_tasks.storage'),
            ])
            ->tag('console.command')
        ;

        $services->set('soviann_deploy_tasks.command.reset', DeployTasksResetCommand::class)
            ->args([
                service('soviann_deploy_tasks.registry'),
                service('soviann_deploy_tasks.storage'),
            ])
            ->tag('console.command')
        ;

        $services->set('soviann_deploy_tasks.command.rollup', DeployTasksRollupCommand::class)
            ->args([
                service('soviann_deploy_tasks.registry'),
                service('soviann_deploy_tasks.storage'),
            ])
            ->tag('console.command')
        ;

        $services->set('soviann_deploy_tasks.command.show', DeployTasksShowCommand::class)
            ->args([
                service('soviann_deploy_tasks.registry'),
                service('soviann_deploy_tasks.storage'),
                service('soviann_deploy_tasks.description_resolver'),
            ])
            ->tag('console.command')
        ;

        $services->set('soviann_deploy_tasks.command.generate', DeployTasksGenerateCommand::class)
            ->args([
                '$idGenerator' => service('soviann_deploy_tasks.id_generator'),
                '$defaultDirectory' => $generateConfig['directory'],
                '$rootNamespace' => $generateConfig['root_namespace'],
                '$templatePath' => $generateConfig['template'],
                '$projectDir' => param('kernel.project_dir'),
            ])
            ->tag('console.command')
        ;

        $services->set('soviann_deploy_tasks.command.generate.host', DeployTasksGenerateHostCommand::class)
            ->args([
                '$hostDirectory' => $generateConfig['host_directory'],
                '$projectDir' => param('kernel.project_dir'),
            ])
            ->tag('console.command')
        ;

        // Host ops-plane parity commands — same $hostTasksDir/$hostLogPath wiring as the
        // status bridge above; they manipulate the completion log only, never the runner.
        $services->set('soviann_deploy_tasks.command.skip.host', DeployTasksSkipHostCommand::class)
            ->args([
                '$hostTasksDir' => $generateConfig['host_directory'],
                '$hostLogPath' => '%kernel.project_dir%/.deploy-tasks-host.log',
            ])
            ->tag('console.command')
        ;

        $services->set('soviann_deploy_tasks.command.reset.host', DeployTasksResetHostCommand::class)
            ->args([
                '$hostTasksDir' => $generateConfig['host_directory'],
                '$hostLogPath' => '%kernel.project_dir%/.deploy-tasks-host.log',
            ])
            ->tag('console.command')
        ;

        $services->set('soviann_deploy_tasks.command.rollup.host', DeployTasksRollupHostCommand::class)
            ->args([
                '$hostTasksDir' => $generateConfig['host_directory'],
                '$hostLogPath' => '%kernel.project_dir%/.deploy-tasks-host.log',
            ])
            ->tag('console.command')
        ;
    }

    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        $container->addCompilerPass(new RegisterTasksCompilerPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION);
    }

    /**
     * @param array<string, mixed> $config
     *
     * @throws \InvalidArgumentException When storage.type is "custom" but no service id is configured
     * @throws \LogicException           When storage.type is "database" without doctrine/dbal installed
     * @throws \LogicException           When storage.type is unsupported
     */
    private function registerStorage(array $config, ServicesConfigurator $services, ContainerBuilder $builder): void
    {
        /** @var StorageConfig $storageConfig */
        $storageConfig = $config['storage'];

        switch ($storageConfig['type']) {
            case 'database':
                if (!\class_exists(\Doctrine\DBAL\Connection::class)) {
                    // Unreachable in the test suite: doctrine/dbal is a dev dependency, so the
                    // class always exists here. Guards real apps that enable database storage
                    // without installing DBAL.
                    // @codeCoverageIgnoreStart
                    throw new \LogicException('The "soviann_deploy_tasks.storage.database" type requires "doctrine/dbal". Run "composer require doctrine/dbal:^4.3".');
                    // @codeCoverageIgnoreEnd
                }

                $connectionServiceId = \sprintf(
                    'doctrine.dbal.%s_connection',
                    $storageConfig['database']['connection'],
                );
                $dbConfig = $storageConfig['database'];

                $services->set('soviann_deploy_tasks.storage.configuration', DbalStorageConfiguration::class)
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

                $services->set('soviann_deploy_tasks.storage', DbalStorage::class)
                    ->args([
                        service($connectionServiceId),
                        service('soviann_deploy_tasks.storage.configuration'),
                    ])
                ;

                $services->alias(TransactionalStorageInterface::class, 'soviann_deploy_tasks.storage');

                $services->set('soviann_deploy_tasks.command.create_schema', DeployTasksCreateSchemaCommand::class)
                    ->args([
                        service('soviann_deploy_tasks.storage'),
                        service('soviann_deploy_tasks.storage.configuration'),
                        $storageConfig['database']['connection'],
                    ])
                    ->tag('console.command')
                ;

                break;
            case 'filesystem':
                $services->set('soviann_deploy_tasks.storage', FilesystemStorage::class)
                    ->args([$storageConfig['filesystem']['path']])
                ;

                break;
            case 'custom':
                $customServiceId = $storageConfig['custom']['service'];

                if (null === $customServiceId) {
                    throw new \InvalidArgumentException('"soviann_deploy_tasks.storage.custom.service" must be set when "soviann_deploy_tasks.storage.type" is "custom".');
                }

                $services->alias('soviann_deploy_tasks.storage', $customServiceId);
                $builder->setParameter('soviann_deploy_tasks.storage.custom_service_id', $customServiceId);

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

        $services->alias(TaskStorageInterface::class, 'soviann_deploy_tasks.storage');
    }

    /**
     * @param array<string, mixed> $config
     */
    private function registerIdGenerator(array $config, ServicesConfigurator $services): void
    {
        /** @var string|null $generatorServiceId */
        $generatorServiceId = $config['id_generator'];

        $services->set('soviann_deploy_tasks.default_id_generator', DefaultTaskIdGenerator::class);

        if (null !== $generatorServiceId) {
            $services->alias('soviann_deploy_tasks.id_generator', $generatorServiceId);
        } else {
            $services->alias('soviann_deploy_tasks.id_generator', 'soviann_deploy_tasks.default_id_generator');
        }

        $services->alias(TaskIdGeneratorInterface::class, 'soviann_deploy_tasks.id_generator');
    }

    /**
     * @param array<string, mixed> $config
     */
    private function registerSorter(array $config, ServicesConfigurator $services): void
    {
        /** @var string|null $sorterServiceId */
        $sorterServiceId = $config['sorter'];

        if (null !== $sorterServiceId) {
            $services->alias('soviann_deploy_tasks.sorter', $sorterServiceId);
        } else {
            $services->set('soviann_deploy_tasks.sorter', DefaultTaskSorter::class)
                ->args([service('soviann_deploy_tasks.id_resolver')])
            ;
        }

        $services->alias(TaskSorterInterface::class, 'soviann_deploy_tasks.sorter');
    }

    /**
     * @param array<string, mixed> $config
     */
    private function registerLogger(array $config, ServicesConfigurator $services): void
    {
        /** @var string|null $userLoggerId */
        $userLoggerId = $config['logger'];

        if (null !== $userLoggerId) {
            $services->alias('soviann_deploy_tasks.logger', $userLoggerId);
        }
        // When null, the runner argument is a NULL_ON_INVALID_REFERENCE to the `logger` service:
        // resolves to the app logger when present (monolog's LoggerChannelPass then rewrites the
        // literal 'logger' reference to the channel-scoped logger via the runner's monolog.logger tag),
        // and TaskRunner falls back to a NullLogger when the app has no logger service.
    }
}
