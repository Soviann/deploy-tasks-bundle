<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Exception;

use Soviann\DeployTasksBundle\TaskResult;

/**
 * Raised internally when a task's run() returns TaskResult::FAILURE (or the
 * runner-reserved TaskResult::LOCKED) instead of throwing.
 *
 * Converting the returned value into an exception routes returned failures
 * through the same path as thrown ones: per-task transaction rollback,
 * TaskFailedEvent, a Failed storage record, and all_or_nothing abort.
 *
 * @internal
 */
final class TaskReturnedFailureException extends \RuntimeException implements DeployTasksExceptionInterface
{
    public static function create(string $taskId, TaskResult $result): self
    {
        return new self(\sprintf('Task "%s" returned TaskResult::%s.', $taskId, $result->name));
    }
}
