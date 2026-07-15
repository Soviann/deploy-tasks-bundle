<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Exception;

/**
 * Thrown when a deploy task ID is registered more than once.
 */
final class DuplicateTaskIdException extends \LogicException implements DeployTasksExceptionInterface
{
    /**
     * Creates an exception naming both classes that collide on the same task ID.
     */
    public static function create(string $id, string $existingFqcn, string $newFqcn): self
    {
        return new self(\sprintf(
            'Task id "%s" is registered by both "%s" and "%s". Add #[AsDeployTask(id: ...)] on one of them to disambiguate.',
            $id,
            $existingFqcn,
            $newFqcn,
        ));
    }

    /**
     * Creates an exception naming both ids (and their classes) that collide only by letter case.
     */
    public static function createCaseInsensitive(string $existingId, string $existingFqcn, string $newId, string $newFqcn): self
    {
        return new self(\sprintf(
            'Task ids "%s" (from "%s") and "%s" (from "%s") differ only by letter case. Case-insensitive storage backends (MySQL *_ci collations, APFS/NTFS file names) treat them as the same key, so one of the tasks would silently never run. Rename one id so they differ beyond case.',
            $existingId,
            $existingFqcn,
            $newId,
            $newFqcn,
        ));
    }
}
