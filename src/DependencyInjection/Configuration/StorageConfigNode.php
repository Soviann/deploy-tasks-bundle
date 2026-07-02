<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\DependencyInjection\Configuration;

use Soviann\DeployTasksBundle\Storage\Dbal\DbalStorageConfiguration;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\ScalarNodeDefinition;

/**
 * Builds the `storage` section of the bundle's config tree (filesystem / database / custom).
 *
 * @internal
 */
final class StorageConfigNode
{
    public function buildRoot(): ArrayNodeDefinition
    {
        $node = new ArrayNodeDefinition('storage');
        $node
            ->beforeNormalization()
                ->ifString()
                ->then(static fn (string $value): array => ['type' => $value])
            ->end()
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
                    ->end()
                ->end()
                ->arrayNode('database')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('connection')
                            ->defaultValue('default')
                        ->end()
                        ->append(self::sqlIdentifierNode('table', 'deploy_task_executions'))
                        ->booleanNode('auto_create_table')
                            ->defaultTrue()
                            ->info('Automatically create the storage table on first use (like Doctrine Migrations).')
                        ->end()
                        ->append(self::sqlIdentifierNode('id_column', 'id'))
                        ->integerNode('id_column_length')
                            ->defaultValue(255)
                            ->min(1)
                        ->end()
                        ->append(self::sqlIdentifierNode('status_column', 'status'))
                        ->append(self::sqlIdentifierNode('executed_at_column', 'executed_at'))
                        ->append(self::sqlIdentifierNode('error_column', 'error'))
                        ->append(self::sqlIdentifierNode(
                            'group_column',
                            'task_group',
                            'Column name for the task group slot. Override to reuse an existing table with a different column name.',
                        ))
                        ->integerNode('group_column_length')
                            ->defaultValue(128)
                            ->min(1)
                            ->info('VARCHAR length for the group column. Override to match an existing column definition.')
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
        ;

        return $node;
    }

    /**
     * Builds a scalar node validated against the SQL-identifier allowlist shared
     * with DbalStorageConfiguration's construct-time check.
     */
    private static function sqlIdentifierNode(string $name, string $default, ?string $info = null): ScalarNodeDefinition
    {
        $node = new ScalarNodeDefinition($name);
        $node->defaultValue($default);

        if (null !== $info) {
            $node->info($info);
        }

        $node
            ->validate()
                ->ifTrue(
                    static fn (string $v): bool => 1 !== \preg_match(DbalStorageConfiguration::SQL_IDENTIFIER_PATTERN, $v),
                )
                ->thenInvalid('Identifier %s is not a valid SQL identifier.')
            ->end()
        ;

        return $node;
    }
}
