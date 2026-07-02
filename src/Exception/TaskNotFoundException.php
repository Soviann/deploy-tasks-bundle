<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Exception;

/**
 * Thrown when a deploy task cannot be found by its ID.
 *
 * RuntimeException like the other CLI-targeting exceptions (group/env mismatch):
 * they all mean "the operator targeted a task the current setup cannot serve".
 */
final class TaskNotFoundException extends \RuntimeException implements DeployTasksExceptionInterface
{
    /**
     * Creates an exception for the given task ID.
     */
    public static function create(string $taskId): self
    {
        return new self(\sprintf('Deploy task "%s" not found.', $taskId));
    }
}
