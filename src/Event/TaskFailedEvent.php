<?php

declare(strict_types=1);

namespace Soviann\DeployTasks\Event;

use Soviann\DeployTasks\Contract\DeployTaskInterface;

/**
 * Dispatched when a deploy task throws an exception during execution.
 */
final class TaskFailedEvent
{
    public function __construct(
        /** The resolved task ID. */
        public readonly string $taskId,
        /** The task that failed. */
        public readonly DeployTaskInterface $task,
        /** The exception thrown by the task. */
        public readonly \Throwable $exception,
        /** Execution duration in seconds before failure. */
        public readonly float $duration,
    ) {
    }
}
