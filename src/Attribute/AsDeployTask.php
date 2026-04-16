<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Attribute;

use Soviann\DeployTasksBundle\DeployTaskInterface;

/**
 * Registers a class as a deploy task with metadata for discovery and execution.
 *
 * ```php
 * #[AsDeployTask(id: 'task_20260412143000_seed_categories', priority: 10)]
 * final class SeedCategoriesTask implements DeployTaskInterface { ... }
 * ```
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class AsDeployTask
{
    /**
     * @param string               $id            Unique task identifier (empty = use TaskIdProviderInterface or FQCN auto-deduction)
     * @param int                  $priority      Execution priority (higher runs first, default 0)
     * @param string|string[]|null $env           Restrict to specific environment(s), null for all
     * @param int|null             $timeout       Max execution time in seconds, null for default
     * @param bool|null            $transactional Wrap execution in a database transaction. Null = use global config default.
     * @param string|null          $description   Human-readable description (overrides DeployTaskInterface::getDescription())
     * @param string|string[]|null $groups        Groups the task belongs to; null = default group (runs only when deploytasks:run is called without --group)
     */
    public function __construct(
        public readonly string $id = '',
        public readonly int $priority = 0,
        public readonly string|array|null $env = null,
        public readonly ?int $timeout = null,
        public readonly ?bool $transactional = null,
        public readonly ?string $description = null,
        public readonly string|array|null $groups = null,
    ) {
    }

    /**
     * Reads the attribute from the given task or class, or null if not present.
     *
     * @param class-string|DeployTaskInterface $classOrTask
     */
    public static function of(string|DeployTaskInterface $classOrTask): ?self
    {
        $reflection = new \ReflectionClass($classOrTask);
        $attributes = $reflection->getAttributes(self::class);

        if ([] === $attributes) {
            return null;
        }

        /** @var self $attribute */
        $attribute = $attributes[0]->newInstance();

        return $attribute;
    }

    /**
     * Returns the task's declared groups as a list of names, or null if the task
     * belongs to the default slot only (attribute missing or `groups: null`).
     *
     * @param class-string|DeployTaskInterface $classOrTask
     *
     * @return list<string>|null
     */
    public static function groupsOf(string|DeployTaskInterface $classOrTask): ?array
    {
        $attribute = self::of($classOrTask);

        if (null === $attribute || null === $attribute->groups) {
            return null;
        }

        return \is_array($attribute->groups) ? \array_values($attribute->groups) : [$attribute->groups];
    }
}
