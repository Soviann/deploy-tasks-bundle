<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Event;

use Soviann\DeployTasksBundle\DeployTaskInterface;

/**
 * Dispatched when a deploy task throws an exception during execution.
 */
final readonly class TaskFailedEvent
{
    public function __construct(
        /** The resolved task ID. */
        public string $taskId,
        /** The task that failed. */
        public DeployTaskInterface $task,
        /** The exception thrown by the task. */
        public \Throwable $exception,
        /** Execution duration in seconds before failure. */
        public float $duration,
    ) {
    }
}
