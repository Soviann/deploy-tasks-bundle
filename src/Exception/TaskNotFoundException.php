<?php

declare(strict_types=1);

namespace Soviann\DeployTasks\Exception;

/**
 * Thrown when a deploy task cannot be found by its ID.
 */
final class TaskNotFoundException extends \InvalidArgumentException
{
    /**
     * Creates an exception for the given task ID.
     */
    public static function create(string $taskId): self
    {
        return new self(\sprintf('Deploy task "%s" not found.', $taskId));
    }
}
