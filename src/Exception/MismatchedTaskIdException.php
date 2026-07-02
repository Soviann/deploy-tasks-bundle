<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Exception;

/**
 * Thrown when a task declares two different IDs — one via getTaskId()
 * (TaskIdProviderInterface) and another via #[AsDeployTask(id: ...)].
 *
 * Conflicting declarations are a configuration bug: whichever value "wins" would
 * silently rewrite the task's stored execution history if the other declaration is
 * later removed, so the conflict fails fast instead.
 */
final class MismatchedTaskIdException extends \LogicException implements DeployTasksExceptionInterface
{
    public static function create(string $taskClass, string $providerId, string $attributeId): self
    {
        return new self(\sprintf(
            'Task "%s" declares mismatched IDs: getTaskId() returns "%s" but #[AsDeployTask] declares id "%s". Remove one declaration or make them identical.',
            $taskClass,
            $providerId,
            $attributeId,
        ));
    }
}
