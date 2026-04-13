<?php

declare(strict_types=1);

namespace Soviann\DeployTasks;

use Soviann\DeployTasks\Contract\Attribute\AsDeployTask;
use Soviann\DeployTasks\Contract\DeployTaskInterface;
use Soviann\DeployTasks\Contract\TaskIdProviderInterface;
use Soviann\DeployTasks\Contract\TaskIdResolverInterface;

/**
 * Resolves the canonical ID for a deploy task.
 *
 * Resolution order:
 *  1. TaskIdProviderInterface::getTaskId() (if the task implements it and returns non-empty)
 *  2. #[AsDeployTask] attribute `id` (if present and non-empty)
 *  3. Auto-deduced from FQCN (short class name → snake_case)
 *
 * If both getTaskId() and attribute `id` return non-empty different values,
 * a E_USER_WARNING is triggered and the interface value takes precedence.
 */
final class DefaultTaskIdResolver implements TaskIdResolverInterface
{
    public function resolve(DeployTaskInterface $task): string
    {
        $attributeId = $this->readAttributeId($task);
        $providerId = $task instanceof TaskIdProviderInterface ? $task->getTaskId() : '';

        // Warn on mismatch when both are non-empty
        if ('' !== $attributeId && '' !== $providerId && $attributeId !== $providerId) {
            @\trigger_error(\sprintf(
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

        // 3. Auto-deduce from FQCN
        return $this->deduceFromClassName($task::class);
    }

    /**
     * Resolves a task ID from class metadata only (no instance needed).
     *
     * Compile-time safe subset: checks #[AsDeployTask] attribute via reflection,
     * then falls back to FQCN auto-deduction. Cannot check TaskIdProviderInterface
     * since that requires an instantiated task.
     *
     * @param class-string $className
     */
    public function resolveFromClass(string $className): string
    {
        $attributeId = $this->readAttributeId($className);

        if ('' !== $attributeId) {
            return $attributeId;
        }

        return $this->deduceFromClassName($className);
    }

    /**
     * Reads the #[AsDeployTask] attribute id from a class, or '' if absent/empty.
     *
     * @param class-string|DeployTaskInterface $classOrTask
     */
    private function readAttributeId(string|DeployTaskInterface $classOrTask): string
    {
        if ($classOrTask instanceof DeployTaskInterface) {
            $attribute = AsDeployTask::of($classOrTask);
        } else {
            $reflection = new \ReflectionClass($classOrTask);
            $attributes = $reflection->getAttributes(AsDeployTask::class);
            $attribute = [] !== $attributes ? $attributes[0]->newInstance() : null;
        }

        if (null === $attribute) {
            return '';
        }

        return $attribute->id;
    }

    /**
     * Converts a FQCN to a snake_case identifier.
     *
     * Example: App\DeployTasks\Task\SeedCategories → seed_categories
     */
    private function deduceFromClassName(string $className): string
    {
        $shortName = \substr($className, \strrpos($className, '\\') + 1);

        // Remove common suffixes
        $shortName = \preg_replace('/(?:Task|DeployTask)$/', '', $shortName);

        // If nothing left after stripping, fall back to original short name
        if ('' === $shortName || null === $shortName) {
            $shortName = \substr($className, \strrpos($className, '\\') + 1);
        }

        // CamelCase → snake_case
        /** @var string $snakeCase */
        $snakeCase = \preg_replace('/[A-Z]/', '_$0', \lcfirst($shortName));

        return \strtolower($snakeCase);
    }
}
