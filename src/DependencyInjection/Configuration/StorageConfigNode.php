<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\DependencyInjection\Configuration;

use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;

final class StorageConfigNode
{
    public function buildRoot(): ArrayNodeDefinition
    {
        $node = new ArrayNodeDefinition('storage');
        $node
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
        ;

        return $node;
    }
}
