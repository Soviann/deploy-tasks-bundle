<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\DependencyInjection\Configuration;

use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;

/**
 * Builds the `events` section of the bundle's config tree (lifecycle-event toggle).
 *
 * @internal
 */
final class EventsConfigNode
{
    public function buildRoot(): ArrayNodeDefinition
    {
        $node = new ArrayNodeDefinition('events');
        // canBeDisabled() provides the bool shortcut (`events: false`) and the
        // enabled-by-default `enabled` child.
        $node->canBeDisabled();

        return $node;
    }
}
