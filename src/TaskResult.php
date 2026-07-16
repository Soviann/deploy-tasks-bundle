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
     * The storage status equivalent of this result.
     *
     * Single owner of the TaskResult → TaskStatus mapping. SUCCESS and FAILURE
     * outcomes are persisted under it; a returned SKIPPED is never persisted
     * (the slot stays pending and retries next run), so its mapping only feeds
     * the runner's `→ skipped` completion line.
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
