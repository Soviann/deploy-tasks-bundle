<?php

declare(strict_types=1);

namespace Soviann\DeployTasks\Exception;

/**
 * Thrown when a storage read or write operation fails for a deploy task.
 */
final class StorageException extends \RuntimeException
{
    /**
     * Creates an exception for a failed read operation on the given task ID.
     */
    public static function readError(string $taskId, \Throwable $previous): self
    {
        return new self(
            \sprintf('Failed to read execution record for task "%s".', $taskId),
            0,
            $previous,
        );
    }

    /**
     * Creates an exception for a failed write operation on the given task ID.
     */
    public static function writeError(string $taskId, \Throwable $previous): self
    {
        return new self(
            \sprintf('Failed to write execution record for task "%s".', $taskId),
            0,
            $previous,
        );
    }
}
