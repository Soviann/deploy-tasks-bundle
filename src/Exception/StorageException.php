<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Exception;

/**
 * Thrown when a storage read or write operation fails for a deploy task.
 */
final class StorageException extends \RuntimeException
{
    /**
     * Creates an exception for when a sidecar lock file cannot be opened or acquired.
     */
    public static function lockUnavailable(string $lockPath): self
    {
        return new self(\sprintf('Failed to acquire lock file "%s".', $lockPath));
    }

    /**
     * Creates an exception for when chmod() fails after writing a storage file.
     */
    public static function chmodFailed(string $path): self
    {
        return new self(\sprintf('Failed to set permissions on storage file "%s".', $path));
    }

    /**
     * Creates an exception for a stored row whose status value does not match any TaskStatus case.
     */
    public static function corruptedRow(string $taskId, ?string $group, string $rawStatus, \Throwable $previous): self
    {
        return new self(
            \sprintf(
                'Corrupted storage row for task "%s"%s: status value "%s" is not a valid TaskStatus.',
                $taskId,
                null === $group ? '' : \sprintf(' (group "%s")', $group),
                $rawStatus,
            ),
            0,
            $previous,
        );
    }
}
