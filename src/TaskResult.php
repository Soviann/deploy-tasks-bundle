<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle;

use Soviann\DeployTasksBundle\Storage\TaskStatus;

/**
 * Return codes for {@see DeployTaskInterface::run()}.
 *
 * Maps to standard CLI exit codes (0 = success, non-zero = error).
 *
 * Tasks return SUCCESS, FAILURE, or SKIPPED. LOCKED is runner-reserved.
 *
 * @see https://tldp.org/LDP/abs/html/exitcodes.html
 */
enum TaskResult: int
{
    case SUCCESS = 0;
    case FAILURE = 1;
    case SKIPPED = 2;

    /**
     * Runner-reserved: returned by TaskRunner::runOne() when the run lock is held by
     * another process. Tasks must never return it — a task returning LOCKED is treated
     * exactly like a returned FAILURE (recorded as failed, TaskFailedEvent, retry).
     */
    case LOCKED = 3;

    /**
     * The storage status a task outcome with this result is persisted under.
     *
     * Single owner of the TaskResult → TaskStatus mapping: LOCKED is runner-reserved,
     * so a task returning it is recorded as a failure.
     */
    public function toStatus(): TaskStatus
    {
        return match ($this) {
            self::SUCCESS => TaskStatus::Ran,
            self::SKIPPED => TaskStatus::Skipped,
            self::FAILURE, self::LOCKED => TaskStatus::Failed,
        };
    }
}
