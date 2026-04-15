<?php

declare(strict_types=1);

namespace Soviann\DeployTasks\Event;

use Soviann\DeployTasks\Contract\DeployTaskInterface;
use Soviann\DeployTasks\Contract\TaskResult;

/**
 * Dispatched after a deploy task has completed without exception (result may be SUCCESS or SKIPPED).
 */
final class AfterTaskEvent
{
    public function __construct(
        /** The resolved task ID. */
        public readonly string $taskId,
        /** The task that was executed. */
        public readonly DeployTaskInterface $task,
        /** The task result. */
        public readonly TaskResult $result,
        /** Execution duration in seconds. */
        public readonly float $duration,
    ) {
    }
}
