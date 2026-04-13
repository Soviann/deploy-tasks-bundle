<?php

declare(strict_types=1);

namespace Soviann\DeployTasks\Event;

use Soviann\DeployTasks\Contract\DeployTaskInterface;

/**
 * Dispatched after a deploy task has completed successfully.
 */
final class AfterTaskEvent
{
    public function __construct(
        /** The resolved task ID. */
        public readonly string $taskId,
        /** The task that was executed. */
        public readonly DeployTaskInterface $task,
        /** The task result code (TaskResult::* constant). */
        public readonly int $result,
        /** Execution duration in seconds. */
        public readonly float $duration,
    ) {
    }
}
