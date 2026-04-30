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
}
