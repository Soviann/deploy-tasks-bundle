<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle;

use Soviann\DeployTasksBundle\Storage\TaskStatus;

/**
 * Return values for {@see DeployTaskInterface::run()}.
 *
 * Only outcomes a task author can legitimately report are cases here. Lock
 * contention belongs to the runner and is represented as a null return from
 * {@see Runner\TaskRunner::runOne()}, not as a case.
 */
enum TaskResult
{
    case SUCCESS;
    case FAILURE;
    case SKIPPED;

    /**
     * The storage status a task outcome with this result is persisted under.
     *
     * Single owner of the TaskResult → TaskStatus mapping.
     */
    public function toStatus(): TaskStatus
    {
        return match ($this) {
            self::SUCCESS => TaskStatus::Ran,
            self::SKIPPED => TaskStatus::Skipped,
            self::FAILURE => TaskStatus::Failed,
        };
    }
}
