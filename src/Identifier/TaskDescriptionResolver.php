<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Identifier;

use Soviann\DeployTasksBundle\Attribute\AsDeployTask;
use Soviann\DeployTasksBundle\DeployTaskInterface;

/**
 * Resolves the human-readable description for a deploy task.
 *
 * Resolution order mirrors {@see TaskIdResolver}:
 *  1. DeployTaskInterface::getDescription() (if it returns non-empty)
 *  2. #[AsDeployTask] attribute `description` (if present and non-empty)
 *  3. Empty string.
 *
 * @internal
 */
final class TaskDescriptionResolver
{
    /**
     * @throws \ReflectionException When the #[AsDeployTask] attribute lookup fails
     */
    public function resolve(DeployTaskInterface $task): string
    {
        $fromMethod = $task->getDescription();

        if ('' !== $fromMethod) {
            return $fromMethod;
        }

        $attribute = AsDeployTask::of($task);

        if (null !== $attribute && null !== $attribute->description && '' !== $attribute->description) {
            return $attribute->description;
        }

        return '';
    }
}
