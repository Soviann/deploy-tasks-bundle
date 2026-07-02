<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\DependencyInjection\Configuration;

use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;

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
                    ->info('Lock TTL in seconds. The runner refreshes the lock between tasks so deploys longer than this value remain safe.')
                ->end()
            ->end()
        ;

        return $node;
    }
}
