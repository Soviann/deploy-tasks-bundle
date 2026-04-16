<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Event;

use Soviann\DeployTasksBundle\DeployTaskInterface;

/**
 * Dispatched before a deploy task is executed.
 */
final readonly class BeforeTaskEvent
{
    public function __construct(
        /** The resolved task ID. */
        public string $taskId,
        /** The task about to be executed. */
        public DeployTaskInterface $task,
    ) {
    }
}
