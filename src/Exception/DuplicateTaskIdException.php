<?php

declare(strict_types=1);

namespace Soviann\DeployTasks\Exception;

/**
 * Thrown when a deploy task ID is registered more than once.
 */
final class DuplicateTaskIdException extends \LogicException
{
    /**
     * Creates an exception for the given duplicate task ID.
     */
    public static function create(string $taskId): self
    {
        return new self(\sprintf('Deploy task ID "%s" is already registered.', $taskId));
    }
}
