<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Exception;

/**
 * Thrown when a task ID is targeted (via --id) without --group, but the task declares groups.
 */
final class TaskGroupRequiredException extends \RuntimeException implements DeployTasksExceptionInterface
{
    /**
     * @param list<string> $declaredGroups
     */
    public static function create(string $taskId, array $declaredGroups): self
    {
        return new self(\sprintf(
            'Task "%s" belongs to groups [%s]; specify --group=… to target a slot.',
            $taskId,
            \implode(', ', $declaredGroups),
        ));
    }
}
