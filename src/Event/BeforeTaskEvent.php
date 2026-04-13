<?php

declare(strict_types=1);

namespace Soviann\DeployTasks\Event;

use Soviann\DeployTasks\Contract\DeployTaskInterface;

/**
 * Dispatched before a deploy task is executed.
 */
final class BeforeTaskEvent
{
    public function __construct(
        /** The resolved task ID. */
        public readonly string $taskId,
        /** The task about to be executed. */
        public readonly DeployTaskInterface $task,
    ) {
    }
}
