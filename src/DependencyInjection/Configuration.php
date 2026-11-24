<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\DependencyInjection;

use Soviann\DeployTasksBundle\DependencyInjection\Configuration\EventsConfigNode;
use Soviann\DeployTasksBundle\DependencyInjection\Configuration\LockConfigNode;
use Soviann\DeployTasksBundle\DependencyInjection\Configuration\StorageConfigNode;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

/**
 * The bundle's `soviann_deploy_tasks` configuration tree.
 *
 * A classic ConfigurationInterface (rather than AbstractBundle::configure()) so the
 * same tree builds on every supported Symfony line, 5.4 LTS included.
 *
 * @internal
 */
final class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('soviann_deploy_tasks');

        $treeBuilder->getRootNode()
            ->children()
                ->scalarNode('sorter')
                    ->defaultNull()
                    ->info('Service ID of a custom TaskSorterInterface, or null for the default sorter.')
                ->end()
                ->scalarNode('logger')
                    ->defaultNull()
                    ->info('Service ID of a custom PSR-3 LoggerInterface, or null to auto-detect the application logger (with monolog channel "soviann_deploy_tasks" when monolog-bundle is installed).')
                ->end()
                ->integerNode('slow_task_threshold')
                    ->defaultValue(300)
                    ->min(0)
                    ->info('Duration in seconds above which a completed task is logged as slow — a warning only, nothing is killed. 0 disables the check entirely (no warning emitted, regardless of duration).')
                ->end()
                ->append((new StorageConfigNode())->buildRoot())
                ->append((new EventsConfigNode())->buildRoot())
                ->append((new LockConfigNode())->buildRoot())
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
                        ->scalarNode('root_namespace')
                            ->defaultValue('App')
                            ->cannotBeEmpty()
                            ->info('Root namespace for deploytasks:generate output when --dir starts with "src/" (mirrors symfony/maker-bundle\'s root_namespace). Set to your composer.json PSR-4 root if it is not "App".')
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('host')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('directory')
                            ->cannotBeEmpty()
                            ->defaultValue('%kernel.project_dir%/deploy/host-tasks')
                            ->info('Directory scanned for host-scope *.sh tasks (deploytasks:host:generate output, ops commands, status). Must match the runner\'s DEPLOY_TASKS_HOST_DIR.')
                        ->end()
                        ->scalarNode('log_path')
                            ->cannotBeEmpty()
                            ->defaultValue('%kernel.project_dir%/.deploy-tasks-host.log')
                            ->info('Host runner\'s append-only completion log. Must match DEPLOY_TASKS_HOST_STORAGE.')
                        ->end()
                        ->scalarNode('lock_path')
                            ->cannotBeEmpty()
                            ->defaultValue('%kernel.project_dir%/.deploy-tasks-host.lock')
                            ->info('Host runner\'s flock file. Must match DEPLOY_TASKS_HOST_LOCK.')
                        ->end()
                    ->end()
                ->end()
            ->end()
        ;

        return $treeBuilder;
    }
}
