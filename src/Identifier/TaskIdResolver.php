<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Identifier;

use Soviann\DeployTasksBundle\Attribute\AsDeployTask;
use Soviann\DeployTasksBundle\DeployTaskInterface;

/**
 * Resolves the canonical ID for a deploy task.
 *
 * Resolution order:
 *  1. TaskIdProviderInterface::getTaskId() (if the task implements it and returns non-empty)
 *  2. #[AsDeployTask] attribute `id` (if present and non-empty)
 *  3. Auto-deduced from FQCN via TaskIdGeneratorInterface
 *
 * If both getTaskId() and attribute `id` return non-empty different values,
 * a E_USER_WARNING is triggered and the interface value takes precedence.
 *
 * @internal
 */
final class TaskIdResolver
{
    public function __construct(
        private readonly TaskIdGeneratorInterface $generator = new DefaultTaskIdGenerator(),
    ) {
    }

    /**
     * @throws \ReflectionException When the #[AsDeployTask] attribute lookup fails
     */
    public function resolve(DeployTaskInterface $task): string
    {
        $attributeId = AsDeployTask::idOf($task);
        $providerId = $task instanceof TaskIdProviderInterface ? $task->getTaskId() : '';

        // Warn on mismatch when both are non-empty
        if ('' !== $attributeId && '' !== $providerId && $attributeId !== $providerId) {
            \trigger_error(\sprintf(
                'Task "%s" has mismatched IDs: getTaskId() "%s" differs from attribute id "%s". The interface value takes precedence.',
                $task::class,
                $providerId,
                $attributeId,
            ), \E_USER_WARNING);
        }

        // 1. TaskIdProviderInterface wins if non-empty
        if ('' !== $providerId) {
            return $providerId;
        }

        // 2. Attribute fallback if non-empty
        if ('' !== $attributeId) {
            return $attributeId;
        }

        // 3. Auto-deduce from FQCN via generator
        return $this->generator->generate($task::class);
    }
}
