<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\DependencyInjection\Configuration;

use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;

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
