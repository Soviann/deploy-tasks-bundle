<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\DependencyInjection\Configuration;

use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;

final class LockConfigNode
{
    public function buildRoot(): ArrayNodeDefinition
    {
        $node = new ArrayNodeDefinition('lock');
        $node
            ->beforeNormalization()
                ->ifTrue(static fn (mixed $value): bool => \is_bool($value))
                ->then(static fn (bool $value): array => ['enabled' => $value])
            ->end()
            ->addDefaultsIfNotSet()
            ->children()
                ->booleanNode('enabled')
                    ->defaultTrue()
                ->end()
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
