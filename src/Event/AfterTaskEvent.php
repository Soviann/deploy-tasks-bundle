<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Event;

use Soviann\DeployTasksBundle\DeployTaskInterface;
use Soviann\DeployTasksBundle\TaskResult;

/**
 * Dispatched after a deploy task has completed without exception (result may be SUCCESS or SKIPPED).
 */
final readonly class AfterTaskEvent
{
    public function __construct(
        /** The resolved task ID. */
        public string $taskId,
        /** The task that was executed. */
        public DeployTaskInterface $task,
        /** The task result. */
        public TaskResult $result,
        /** Execution duration in seconds. */
        public float $duration,
    ) {
    }
}
