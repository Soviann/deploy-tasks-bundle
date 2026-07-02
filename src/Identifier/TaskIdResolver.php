<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Identifier;

use Soviann\DeployTasksBundle\Attribute\AsDeployTask;
use Soviann\DeployTasksBundle\DeployTaskInterface;
use Soviann\DeployTasksBundle\Exception\MismatchedTaskIdException;

/**
 * Resolves the canonical ID for a deploy task.
 *
 * Resolution order:
 *  1. TaskIdProviderInterface::getTaskId() (if the task implements it and returns non-empty)
 *  2. #[AsDeployTask] attribute `id` (if present and non-empty)
 *  3. Auto-deduced from FQCN via TaskIdGeneratorInterface
 *
 * Supported for use by custom {@see \Soviann\DeployTasksBundle\Sorting\TaskSorterInterface}
 * implementations that need task IDs.
 */
final readonly class TaskIdResolver
{
    public function __construct(
        private TaskIdGeneratorInterface $generator = new DefaultTaskIdGenerator(),
    ) {
    }

    /**
     * @throws MismatchedTaskIdException When getTaskId() and the attribute declare different non-empty IDs
     * @throws \ReflectionException      When the #[AsDeployTask] attribute lookup fails
     */
    public function resolve(DeployTaskInterface $task): string
    {
        $attributeId = AsDeployTask::idOf($task);
        $providerId = $task instanceof TaskIdProviderInterface ? $task->getTaskId() : '';

        // Two different non-empty declarations are a config bug — fail fast instead of
        // letting one silently win (and silently rewrite stored history if the other
        // declaration is removed later).
        if ('' !== $attributeId && '' !== $providerId && $attributeId !== $providerId) {
            throw MismatchedTaskIdException::create($task::class, $providerId, $attributeId);
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
