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
     * Allowlist for group names — mirrors the task-ID allowlist and keeps every
     * accepted value safe for use in filesystem paths, DB primary keys, and URLs.
     */
    public const GROUP_NAME_PATTERN = '/^[a-zA-Z0-9._-]+$/';

    /** @var array<class-string, ?self> */
    private static array $cache = [];

    /**
     * @param string               $id            Unique task identifier (empty = use TaskIdProviderInterface or FQCN auto-deduction)
     * @param int                  $priority      Execution priority (higher runs first, default 0)
     * @param string|string[]|null $env           Restrict to specific environment(s), null for all
     * @param int|null             $timeout       Max execution time in seconds, null for default
     * @param bool|null            $transactional Wrap execution in a database transaction. Null = use global config default.
     * @param string|null          $description   Human-readable description (overrides DeployTaskInterface::getDescription())
     * @param string|string[]|null $groups        Groups the task belongs to; null = default group (runs only when deploytasks:run is called without --group). Names must match AsDeployTask::GROUP_NAME_PATTERN.
     *
     * @throws \InvalidArgumentException When groups is an empty array
     * @throws \InvalidArgumentException When groups contains a non-string entry
     * @throws \InvalidArgumentException When a group name does not match GROUP_NAME_PATTERN
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
        if ([] === $groups) {
            throw new \InvalidArgumentException('groups cannot be an empty array — omit (or pass null) to mean the default group.');
        }

        if (\is_array($groups)) {
            foreach ($groups as $entry) {
                /* @phpstan-ignore function.alreadyNarrowedType */
                if (!\is_string($entry)) {
                    throw new \InvalidArgumentException(\sprintf('groups entries must be strings, got %s.', \get_debug_type($entry)));
                }
            }
        }

        $groupList = match (true) {
            null === $groups => [],
            \is_array($groups) => \array_values($groups),
            default => [$groups],
        };

        foreach ($groupList as $group) {
            if (1 !== \preg_match(self::GROUP_NAME_PATTERN, $group)) {
                throw new \InvalidArgumentException(\sprintf('Invalid group name "%s" in #[AsDeployTask]: must match %s.', $group, self::GROUP_NAME_PATTERN));
            }
        }
    }

    /**
     * Reads the attribute from the given task or class, or null if not present.
     *
     * Results are memoised per class-string for the duration of the process;
     * subsequent calls with the same class return the identical instance (or null).
     *
     * @param class-string|DeployTaskInterface $classOrTask
     *
     * @throws \ReflectionException
     */
    public static function of(string|DeployTaskInterface $classOrTask): ?self
    {
        $className = \is_string($classOrTask) ? $classOrTask : $classOrTask::class;

        if (\array_key_exists($className, self::$cache)) {
            return self::$cache[$className];
        }

        $attributes = (new \ReflectionClass($className))->getAttributes(self::class);

        if ([] === $attributes) {
            return self::$cache[$className] = null;
        }

        /** @var self $instance — newInstance() is typed object in IDE stubs; this is always a self */
        $instance = $attributes[0]->newInstance();

        return self::$cache[$className] = $instance;
    }

    /**
     * Returns the task's declared groups as a list of names, or null if the task
     * belongs to the default slot only (attribute missing or `groups: null`).
     *
     * @param class-string|DeployTaskInterface $classOrTask
     *
     * @return list<string>|null
     *
     * @throws \ReflectionException
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
