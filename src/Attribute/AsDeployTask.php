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
     * Regex atom matching one valid task-ID/group-name character. Single source for
     * TASK_ID_PATTERN, GROUP_NAME_PATTERN, and derived patterns (e.g. storage record
     * filenames) so the accepted charset cannot drift between them.
     */
    public const IDENTIFIER_CHAR = '[a-zA-Z0-9._-]';

    /**
     * Allowlist for group names — mirrors the task-ID allowlist and keeps every
     * accepted value safe for use in filesystem paths, DB primary keys, and URLs.
     *
     * Anchored with \z instead of $: PCRE's $ matches before a trailing "\n", which
     * would let "abc\n" through and break the host-log path's exact-line semantics
     * (HostLogManipulationTrait::isValidHostTaskId() uses the same \z anchor).
     */
    public const GROUP_NAME_PATTERN = '/^'.self::IDENTIFIER_CHAR.'+\z/';

    /**
     * Allowlist for task IDs — identical to GROUP_NAME_PATTERN; every accepted value
     * is safe as a filesystem name, DB primary-key value, and terminal output.
     */
    public const TASK_ID_PATTERN = '/^'.self::IDENTIFIER_CHAR.'+\z/';

    /** @var array<class-string, ?self> */
    private static array $cache = [];

    /**
     * @param string               $id            Unique task identifier (empty = use TaskIdProviderInterface or
     *                                            FQCN auto-deduction). Non-empty values must match
     *                                            AsDeployTask::TASK_ID_PATTERN.
     * @param int                  $priority      Execution priority (higher runs first, default 0)
     * @param string|string[]|null $env           Restrict to specific environment(s), null for all
     * @param int|null             $timeout       Max execution time in seconds, null for default. 0 disables the
     *                                            runner's soft timeout check. Must be >= 0.
     * @param bool|null            $transactional Wrap execution in a database transaction. Null = use global config
     *                                            default.
     * @param string|null          $description   Human-readable description (overrides
     *                                            DeployTaskInterface::getDescription())
     * @param string|string[]|null $groups        Groups the task belongs to; null = default group (runs only when
     *                                            deploytasks:run is called without --group). Names must match
     *                                            AsDeployTask::GROUP_NAME_PATTERN and each name must be unique,
     *                                            ignoring letter case.
     *
     * @throws \InvalidArgumentException When a non-empty id does not match TASK_ID_PATTERN
     * @throws \InvalidArgumentException When env is an empty array
     * @throws \InvalidArgumentException When env contains a non-string entry
     * @throws \InvalidArgumentException When groups is an empty array
     * @throws \InvalidArgumentException When groups contains a non-string entry
     * @throws \InvalidArgumentException When a group name does not match GROUP_NAME_PATTERN
     * @throws \InvalidArgumentException When the same group name appears more than once, or two group names differ only by letter case
     * @throws \InvalidArgumentException When timeout is negative
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
        if ([] === $env) {
            throw new \InvalidArgumentException('env cannot be an empty array — omit (or pass null) to mean all environments.');
        }

        if (\is_array($env)) {
            foreach ($env as $entry) {
                /* @phpstan-ignore function.alreadyNarrowedType */
                if (!\is_string($entry)) {
                    throw new \InvalidArgumentException(\sprintf('env entries must be strings, got %s.', \get_debug_type($entry)));
                }
            }
        }

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

        // Deduplicated on the lowercased name: a multi-group task records one
        // storage slot per (id, group) pair, and case-insensitive backends
        // (MySQL *_ci collations, APFS/NTFS file names) collapse same-id slots
        // whose groups differ only by case onto a single record. Case-differing
        // group names across DIFFERENT tasks stay legal — their storage keys
        // always differ in the id component.
        /** @var array<string, string> $seenGroups lowercased group name → declared spelling */
        $seenGroups = [];

        foreach ($groupList as $group) {
            if (1 !== \preg_match(self::GROUP_NAME_PATTERN, $group)) {
                throw new \InvalidArgumentException(\sprintf('Invalid group name "%s" in #[AsDeployTask]: must match %s.', $group, self::GROUP_NAME_PATTERN));
            }

            $lowercased = \strtolower($group);

            if (isset($seenGroups[$lowercased])) {
                if ($seenGroups[$lowercased] === $group) {
                    throw new \InvalidArgumentException(\sprintf('Duplicate group "%s" in #[AsDeployTask]: declare each group once.', $group));
                }

                throw new \InvalidArgumentException(\sprintf('Groups "%s" and "%s" in #[AsDeployTask] differ only by letter case. Case-insensitive storage backends (MySQL *_ci collations, APFS/NTFS file names) treat them as the same record slot, so the task would silently run for only one of them. Rename one group so they differ beyond case.', $seenGroups[$lowercased], $group));
            }

            $seenGroups[$lowercased] = $group;
        }

        if ('' !== $id && 1 !== \preg_match(self::TASK_ID_PATTERN, $id)) {
            throw new \InvalidArgumentException(\sprintf('Invalid task id "%s" in #[AsDeployTask]: must match %s.', $id, self::TASK_ID_PATTERN));
        }

        if (null !== $timeout && $timeout < 0) {
            throw new \InvalidArgumentException(\sprintf('Invalid timeout %d in #[AsDeployTask]: must be >= 0.', $timeout));
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

    /**
     * Returns the task's declared environments as a list of names, or null if the
     * task runs in every environment (attribute missing or `env: null`).
     *
     * @param class-string|DeployTaskInterface $classOrTask
     *
     * @return list<string>|null
     *
     * @throws \ReflectionException
     */
    public static function envsOf(string|DeployTaskInterface $classOrTask): ?array
    {
        $attribute = self::of($classOrTask);

        if (null === $attribute || null === $attribute->env) {
            return null;
        }

        return \is_array($attribute->env) ? \array_values($attribute->env) : [$attribute->env];
    }

    /**
     * Returns the attribute-declared task id, or '' when the attribute is absent
     * or declares no id.
     *
     * @param class-string|DeployTaskInterface $classOrTask
     *
     * @throws \ReflectionException
     */
    public static function idOf(string|DeployTaskInterface $classOrTask): string
    {
        return self::of($classOrTask)->id ?? '';
    }

    /**
     * Declared timeout of a task class, or null when the class has no
     * attribute or the attribute declares no timeout.
     *
     * @param class-string|DeployTaskInterface $classOrTask
     *
     * @throws \ReflectionException
     */
    public static function timeoutOf(string|DeployTaskInterface $classOrTask): ?int
    {
        return self::of($classOrTask)?->timeout;
    }
}
