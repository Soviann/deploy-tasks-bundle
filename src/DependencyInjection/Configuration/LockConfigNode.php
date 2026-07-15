<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\DependencyInjection\Configuration;

use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;

/**
 * Builds the `lock` section of the bundle's config tree (run-lock toggle + TTL).
 *
 * @internal
 */
final class LockConfigNode
{
    public function buildRoot(): ArrayNodeDefinition
    {
        $node = new ArrayNodeDefinition('lock');
        // canBeDisabled() provides the bool shortcut (`lock: false`) and the
        // enabled-by-default `enabled` child.
        $node
            ->canBeDisabled()
            ->children()
                ->integerNode('ttl')
                    ->defaultValue(3600)
                    ->min(60)
                    ->info('Lock TTL in seconds. The lease is refreshed between tasks, not during them: the TTL must outlast the longest single task, not the whole deploy. A task that outruns it loses the lease and the run stops before the next task.')
                ->end()
            ->end()
        ;

        return $node;
    }
}
