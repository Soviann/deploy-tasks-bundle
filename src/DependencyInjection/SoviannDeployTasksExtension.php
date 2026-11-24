<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\DependencyInjection;

use Soviann\DeployTasksBundle\Command\DeployTasksGenerateCommand;
use Soviann\DeployTasksBundle\Command\DeployTasksGenerateHostCommand;
use Soviann\DeployTasksBundle\Command\DeployTasksHostConfigCommand;
use Soviann\DeployTasksBundle\Command\DeployTasksInstallHostCommand;
use Soviann\DeployTasksBundle\Command\DeployTasksPruneCommand;
use Soviann\DeployTasksBundle\Command\DeployTasksResetCommand;
use Soviann\DeployTasksBundle\Command\DeployTasksResetHostCommand;
use Soviann\DeployTasksBundle\Command\DeployTasksRollupCommand;
use Soviann\DeployTasksBundle\Command\DeployTasksRollupHostCommand;
use Soviann\DeployTasksBundle\Command\DeployTasksRunCommand;
use Soviann\DeployTasksBundle\Command\DeployTasksShowCommand;
use Soviann\DeployTasksBundle\Command\DeployTasksSkipCommand;
use Soviann\DeployTasksBundle\Command\DeployTasksSkipHostCommand;
use Soviann\DeployTasksBundle\Command\DeployTasksStatusCommand;
use Soviann\DeployTasksBundle\DeployTaskInterface;
use Soviann\DeployTasksBundle\Identifier\DefaultTaskIdGenerator;
use Soviann\DeployTasksBundle\Identifier\TaskDescriptionResolver;
use Soviann\DeployTasksBundle\Identifier\TaskIdGeneratorInterface;
use Soviann\DeployTasksBundle\Identifier\TaskIdResolver;
use Soviann\DeployTasksBundle\Runner\TaskRegistry;
use Soviann\DeployTasksBundle\Runner\TaskRunner;
use Soviann\DeployTasksBundle\Runner\TransactionMode;
use Soviann\DeployTasksBundle\Sorting\DefaultTaskSorter;
use Soviann\DeployTasksBundle\Sorting\TaskSorterInterface;
use Soviann\DeployTasksBundle\Storage\Dbal\DbalStorage;
use Soviann\DeployTasksBundle\Storage\Dbal\DbalStorageConfiguration;
use Soviann\DeployTasksBundle\Storage\Filesystem\FilesystemStorage;
use Soviann\DeployTasksBundle\Storage\TaskStorageInterface;
use Soviann\DeployTasksBundle\Storage\TransactionalStorageInterface;
use Symfony\Component\DependencyInjection\Argument\TaggedIteratorArgument;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Registers the bundle's services from the processed `soviann_deploy_tasks` config.
 *
 * Wires everything through the plain ContainerBuilder API (no AbstractBundle /
 * ContainerConfigurator) so the same code runs on Symfony 5.4 LTS through 8.x.
 * Every definition and alias is private, matching the former AbstractBundle wiring.
 *
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
 *         duration_column: string,
 *         group_column: string,
 *         group_column_length: int,
 *         transaction_mode: string,
 *     },
 *     custom: array{service: string|null, transaction_mode: string},
 * }
 * @phpstan-type GenerateConfig array{
 *     directory: string,
 *     template: string|null,
 *     root_namespace: string,
 * }
 * @phpstan-type HostConfig array{
 *     directory: string,
 *     log_path: string,
 *     lock_path: string,
 * }
 *
 * @internal
 */
final class SoviannDeployTasksExtension extends Extension
{
    /**
     * @param array<array-key, mixed> $configs
     */
    public function load(array $configs, ContainerBuilder $container): void
    {
        /** @var array<string, mixed> $config */
        $config = $this->processConfiguration(new Configuration(), $configs);

        $container->registerForAutoconfiguration(DeployTaskInterface::class)
            ->addTag('soviann_deploy_tasks.task')
        ;

        // TaskRegistry
        $container->register('soviann_deploy_tasks.registry', TaskRegistry::class)
            ->setArguments([
                new TaggedIteratorArgument('soviann_deploy_tasks.task'),
                new Reference('soviann_deploy_tasks.id_resolver'),
            ])
        ;
        $container->setAlias(TaskRegistry::class, 'soviann_deploy_tasks.registry');

        // ID generator (internal — not configurable)
        $container->register('soviann_deploy_tasks.id_generator', DefaultTaskIdGenerator::class);
        $container->setAlias(TaskIdGeneratorInterface::class, 'soviann_deploy_tasks.id_generator');

        // ID resolver (internal — not configurable)
        $container->register('soviann_deploy_tasks.id_resolver', TaskIdResolver::class)
            ->setArguments([new Reference('soviann_deploy_tasks.id_generator')])
        ;

        // Description resolver (internal — not configurable)
        $container->register('soviann_deploy_tasks.description_resolver', TaskDescriptionResolver::class);

        // Storage
        $this->registerStorage($config, $container);

        // Sorter
        $this->registerSorter($config, $container);

        // Logger
        $this->registerLogger($config, $container);

        // Config flags for compiler pass
        /** @var array{enabled: bool} $eventsConfig */
        $eventsConfig = $config['events'];
        /** @var array{enabled: bool, ttl: int} $lockConfig */
        $lockConfig = $config['lock'];
        $container->setParameter('soviann_deploy_tasks.events.enabled', $eventsConfig['enabled']);
        $container->setParameter('soviann_deploy_tasks.lock.enabled', $lockConfig['enabled']);

        // TaskRunner — transaction_mode comes from the active storage sub-config.
        // Filesystem storage is inherently non-transactional (no config key for it).
        /**
         * @var array{
         *     type: string,
         *     filesystem: array{path: string},
         *     database: array{transaction_mode: string},
         *     custom: array{service: string|null, transaction_mode: string},
         * } $storageConfig
         */
        $storageConfig = $config['storage'];

        if ('filesystem' === $storageConfig['type']) {
            $transactionMode = TransactionMode::None;
        } else {
            /** @var array{transaction_mode: string} $activeStorage */
            $activeStorage = $storageConfig[$storageConfig['type']];
            $transactionMode = TransactionMode::from($activeStorage['transaction_mode']);
        }

        $container->setParameter('soviann_deploy_tasks.runner.transaction_mode', $transactionMode->value);

        $runnerDefinition = $container->register('soviann_deploy_tasks.runner', TaskRunner::class)
            ->setArguments([
                '$registry' => new Reference('soviann_deploy_tasks.registry'),
                '$storage' => new Reference('soviann_deploy_tasks.storage'),
                '$sorter' => new Reference('soviann_deploy_tasks.sorter'),
                '$idResolver' => new Reference('soviann_deploy_tasks.id_resolver'),
                '$descriptionResolver' => new Reference('soviann_deploy_tasks.description_resolver'),
                '$dispatcher' => null, // set by compiler pass
                '$lockFactory' => null, // set by compiler pass
                '$slowTaskThreshold' => $config['slow_task_threshold'],
                '$environment' => '%kernel.environment%',
                '$transactionMode' => $transactionMode,
                '$logger' => self::loggerReference($config),
                '$lockTtl' => $lockConfig['ttl'],
                '$lockDisabledByConfig' => !$lockConfig['enabled'],
            ])
        ;

        self::tagLoggerChannel($config, $runnerDefinition);
        $container->setAlias(TaskRunner::class, 'soviann_deploy_tasks.runner');

        $this->registerCommands($config, $container);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function registerCommands(array $config, ContainerBuilder $container): void
    {
        $container->register('soviann_deploy_tasks.command.run', DeployTasksRunCommand::class)
            ->setArguments([
                '$registry' => new Reference('soviann_deploy_tasks.registry'),
                '$runner' => new Reference('soviann_deploy_tasks.runner'),
                '$environment' => '%kernel.environment%',
            ])
            ->addTag('console.command')
        ;

        /** @var GenerateConfig $generateConfig */
        $generateConfig = $config['generate'];
        /** @var HostConfig $hostConfig */
        $hostConfig = $config['host'];
        $hostConfig = \array_map(self::anchorToProjectDir(...), $hostConfig);

        $container->register('soviann_deploy_tasks.command.status', DeployTasksStatusCommand::class)
            ->setArguments([
                '$registry' => new Reference('soviann_deploy_tasks.registry'),
                '$storage' => new Reference('soviann_deploy_tasks.storage'),
                '$descriptionResolver' => new Reference('soviann_deploy_tasks.description_resolver'),
                '$hostTasksDir' => $hostConfig['directory'],
                '$hostLogPath' => $hostConfig['log_path'],
                '$hostLockPath' => $hostConfig['lock_path'],
                '$projectDir' => '%kernel.project_dir%',
            ])
            ->addTag('console.command')
        ;

        // Registry + storage commands share the same two positional collaborators.
        foreach ([
            'skip' => DeployTasksSkipCommand::class,
            'prune' => DeployTasksPruneCommand::class,
            'reset' => DeployTasksResetCommand::class,
            'rollup' => DeployTasksRollupCommand::class,
        ] as $name => $class) {
            $container->register('soviann_deploy_tasks.command.'.$name, $class)
                ->setArguments([
                    new Reference('soviann_deploy_tasks.registry'),
                    new Reference('soviann_deploy_tasks.storage'),
                ])
                ->addTag('console.command')
            ;
        }

        $container->register('soviann_deploy_tasks.command.show', DeployTasksShowCommand::class)
            ->setArguments([
                new Reference('soviann_deploy_tasks.registry'),
                new Reference('soviann_deploy_tasks.storage'),
                new Reference('soviann_deploy_tasks.description_resolver'),
            ])
            ->addTag('console.command')
        ;

        $container->register('soviann_deploy_tasks.command.generate', DeployTasksGenerateCommand::class)
            ->setArguments([
                '$idGenerator' => new Reference('soviann_deploy_tasks.id_generator'),
                '$defaultDirectory' => $generateConfig['directory'],
                '$rootNamespace' => $generateConfig['root_namespace'],
                '$templatePath' => $generateConfig['template'],
                '$projectDir' => '%kernel.project_dir%',
            ])
            ->addTag('console.command')
        ;

        $container->register('soviann_deploy_tasks.command.host.install', DeployTasksInstallHostCommand::class)
            ->setArguments([
                '$hostDirectory' => $hostConfig['directory'],
                '$projectDir' => '%kernel.project_dir%',
            ])
            ->addTag('console.command')
        ;

        $container->register('soviann_deploy_tasks.command.host.generate', DeployTasksGenerateHostCommand::class)
            ->setArguments([
                '$hostDirectory' => $hostConfig['directory'],
                '$projectDir' => '%kernel.project_dir%',
            ])
            ->addTag('console.command')
        ;

        // Host ops-plane parity commands — same $hostTasksDir/$hostLogPath wiring as the
        // status bridge above; they manipulate the completion log only, never the runner,
        // and take the runner's own flock ($hostLockPath) around every mutation.
        foreach ([
            'skip' => DeployTasksSkipHostCommand::class,
            'reset' => DeployTasksResetHostCommand::class,
            'rollup' => DeployTasksRollupHostCommand::class,
        ] as $name => $class) {
            $container->register('soviann_deploy_tasks.command.host.'.$name, $class)
                ->setArguments([
                    '$hostTasksDir' => $hostConfig['directory'],
                    '$hostLogPath' => $hostConfig['log_path'],
                    '$hostLockPath' => $hostConfig['lock_path'],
                ])
                ->addTag('console.command')
            ;
        }

        $container->register('soviann_deploy_tasks.command.host.config', DeployTasksHostConfigCommand::class)
            ->setArguments([
                '$hostTasksDir' => $hostConfig['directory'],
                '$hostLogPath' => $hostConfig['log_path'],
                '$hostLockPath' => $hostConfig['lock_path'],
                '$projectDir' => '%kernel.project_dir%',
            ])
            ->addTag('console.command')
        ;
    }

    /**
     * @param array<string, mixed> $config
     *
     * @throws \InvalidArgumentException When storage.type is "custom" but no service id is configured
     * @throws \LogicException           When storage.type is "database" without doctrine/dbal installed
     * @throws \LogicException           When storage.type is unsupported
     */
    private function registerStorage(array $config, ContainerBuilder $container): void
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
                    throw new \LogicException('The "soviann_deploy_tasks.storage.database" type requires "doctrine/dbal". Run "composer require doctrine/dbal".');
                    // @codeCoverageIgnoreEnd
                }

                $connectionServiceId = \sprintf(
                    'doctrine.dbal.%s_connection',
                    $storageConfig['database']['connection'],
                );
                $dbConfig = $storageConfig['database'];

                $container->register('soviann_deploy_tasks.storage.configuration', DbalStorageConfiguration::class)
                    ->setArguments([
                        '$autoCreateTable' => $dbConfig['auto_create_table'],
                        '$durationColumn' => $dbConfig['duration_column'],
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

                $storageDefinition = $container->register('soviann_deploy_tasks.storage', DbalStorage::class)
                    ->setArguments([
                        '$connection' => new Reference($connectionServiceId),
                        '$configuration' => new Reference('soviann_deploy_tasks.storage.configuration'),
                        '$logger' => self::loggerReference($config),
                    ])
                ;
                self::tagLoggerChannel($config, $storageDefinition);

                $container->setAlias(TransactionalStorageInterface::class, 'soviann_deploy_tasks.storage');

                // Consumed by RegisterCreateSchemaCommandPass so the create-schema
                // success message can name the connection. The command itself is
                // registered by the pass, capability-based on the storage class.
                $container->setParameter(
                    'soviann_deploy_tasks.storage.dbal_connection_name',
                    $dbConfig['connection'],
                );

                break;
            case 'filesystem':
                $container->register('soviann_deploy_tasks.storage', FilesystemStorage::class)
                    ->setArguments([
                        self::anchorToProjectDir($storageConfig['filesystem']['path']),
                        '%kernel.project_dir%',
                    ])
                ;

                break;
            case 'custom':
                $customServiceId = $storageConfig['custom']['service'];

                if (null === $customServiceId) {
                    throw new \InvalidArgumentException('"soviann_deploy_tasks.storage.custom.service" must be set when "soviann_deploy_tasks.storage.type" is "custom".');
                }

                $container->setAlias('soviann_deploy_tasks.storage', $customServiceId);
                $container->setParameter('soviann_deploy_tasks.storage.custom_service_id', $customServiceId);

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

        $container->setAlias(TaskStorageInterface::class, 'soviann_deploy_tasks.storage');
    }

    /**
     * @param array<string, mixed> $config
     */
    private function registerSorter(array $config, ContainerBuilder $container): void
    {
        /** @var string|null $sorterServiceId */
        $sorterServiceId = $config['sorter'];

        if (null !== $sorterServiceId) {
            $container->setAlias('soviann_deploy_tasks.sorter', $sorterServiceId);
        } else {
            $container->register('soviann_deploy_tasks.sorter', DefaultTaskSorter::class)
                ->setArguments([new Reference('soviann_deploy_tasks.id_resolver')])
            ;
        }

        $container->setAlias(TaskSorterInterface::class, 'soviann_deploy_tasks.sorter');
    }

    /**
     * @param array<string, mixed> $config
     */
    private function registerLogger(array $config, ContainerBuilder $container): void
    {
        /** @var string|null $userLoggerId */
        $userLoggerId = $config['logger'];

        if (null !== $userLoggerId) {
            $container->setAlias('soviann_deploy_tasks.logger', $userLoggerId);
        }
        // When null, the logger arguments (runner, database storage) are a NULL_ON_INVALID_REFERENCE
        // to the `logger` service: resolves to the app logger when present (monolog's LoggerChannelPass
        // then rewrites the literal 'logger' reference to the channel-scoped logger via the services'
        // monolog.logger tag), and both fall back to a NullLogger when the app has no logger service.
    }

    /**
     * The logger argument shared by the services that log (runner, database storage).
     *
     * @param array<string, mixed> $config
     */
    private static function loggerReference(array $config): Reference
    {
        /** @var string|null $userLoggerId */
        $userLoggerId = $config['logger'];

        // Bundle owns the logger — resolves to the app logger when present (monolog's
        // LoggerChannelPass rewrites it to the channel-scoped logger via tagLoggerChannel()).
        // A user-supplied logger service is routed as-is.
        return null === $userLoggerId
            ? new Reference('logger', ContainerInterface::NULL_ON_INVALID_REFERENCE)
            : new Reference($userLoggerId);
    }

    /**
     * Puts a logging service on the bundle's Monolog channel, unless the user supplied
     * a logger — the channel rewrite must not override that explicit choice.
     *
     * @param array<string, mixed> $config
     */
    private static function tagLoggerChannel(array $config, Definition $definition): void
    {
        if (null === $config['logger']) {
            $definition->addTag('monolog.logger', ['channel' => 'soviann_deploy_tasks']);
        }
    }

    /**
     * Anchors a config-supplied path to the project dir when it is relative.
     *
     * Consumers (FilesystemStorage, the host-scope commands) use these values
     * raw — is_dir(), fopen(), file paths — which would otherwise resolve
     * against the process CWD, making deploy state depend on the directory the
     * console command happens to be launched from.
     */
    private static function anchorToProjectDir(string $path): string
    {
        if (\str_starts_with($path, '/') || \str_starts_with($path, '%')) {
            return $path;
        }

        return '%kernel.project_dir%/'.$path;
    }
}
