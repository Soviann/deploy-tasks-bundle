<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Runner;

use Soviann\DeployTasksBundle\Attribute\AsDeployTask;
use Soviann\DeployTasksBundle\DeployTaskInterface;
use Soviann\DeployTasksBundle\Exception\DuplicateTaskIdException;
use Soviann\DeployTasksBundle\Exception\TaskNotFoundException;
use Soviann\DeployTasksBundle\Identifier\TaskIdResolver;

/**
 * In-memory registry of all discovered deploy tasks, keyed by ID.
 */
final class TaskRegistry
{
    /** @var array<string, DeployTaskInterface> */
    private array $tasks = [];

    /**
     * @param iterable<DeployTaskInterface> $tasks      Tagged deploy task services
     * @param TaskIdResolver                $idResolver Resolves the canonical ID for each task
     *
     * @throws DuplicateTaskIdException  When two tasks resolve to the same id, or to ids differing only by letter case
     * @throws \InvalidArgumentException When a resolved id does not match AsDeployTask::TASK_ID_PATTERN
     * @throws \ReflectionException      When the #[AsDeployTask] attribute lookup fails for a tagged task
     */
    public function __construct(iterable $tasks, TaskIdResolver $idResolver)
    {
        // Collisions are detected on the lowercased id: MySQL *_ci collations and
        // APFS/NTFS file names treat "Seed_Users" and "seed_users" as the same
        // storage key, so ids differing only by case would silently share one
        // execution record — and one of the tasks would never run. Checking here
        // keeps the guard backend-agnostic and covers ids the compiler pass cannot
        // see (TaskIdProviderInterface).
        /** @var array<string, string> $seenIds lowercased id → registered id */
        $seenIds = [];

        foreach ($tasks as $task) {
            $id = $idResolver->resolve($task);

            if (1 !== \preg_match(AsDeployTask::TASK_ID_PATTERN, $id)) {
                throw new \InvalidArgumentException(\sprintf('Invalid task id "%s" (from %s): must match %s.', $id, $task::class, AsDeployTask::TASK_ID_PATTERN));
            }

            $lowercased = \strtolower($id);

            if (isset($seenIds[$lowercased])) {
                $existingId = $seenIds[$lowercased];
                $existingFqcn = $this->tasks[$existingId]::class;

                if ($existingId === $id) {
                    throw DuplicateTaskIdException::create($id, $existingFqcn, $task::class);
                }

                throw DuplicateTaskIdException::createCaseInsensitive($existingId, $existingFqcn, $id, $task::class);
            }

            $seenIds[$lowercased] = $id;
            $this->tasks[$id] = $task;
        }
    }

    /**
     * Returns registered tasks, keyed by ID, after applying environment and group filters.
     *
     * - Env: null environment matches every task; otherwise keep tasks whose `env`
     *   is null or contains the requested environment.
     * - Groups: empty array returns default-only tasks (those with no declared groups);
     *   a non-empty array returns tasks whose declared groups intersect with it.
     *
     * @param list<string> $groups
     *
     * @return array<string, DeployTaskInterface>
     *
     * @throws \ReflectionException When the #[AsDeployTask] attribute lookup fails for a registered task
     */
    public function all(?string $environment = null, array $groups = []): array
    {
        $filtered = [];

        foreach ($this->tasks as $id => $task) {
            if (!self::matchesEnvironment($task, $environment)) {
                continue;
            }

            if (!self::matchesGroups($task, $groups)) {
                continue;
            }

            $filtered[$id] = $task;
        }

        return $filtered;
    }

    /**
     * Returns every registered task keyed by ID, bypassing environment and group filters.
     *
     * Used by commands that report on or manipulate the full task inventory
     * (status, rollup) rather than selecting tasks for a specific run.
     *
     * @return array<string, DeployTaskInterface>
     */
    public function allRegistered(): array
    {
        return $this->tasks;
    }

    /**
     * Returns the task with the given ID.
     *
     * @throws TaskNotFoundException If no task is registered with this ID
     */
    public function get(string $id): DeployTaskInterface
    {
        if (!isset($this->tasks[$id])) {
            throw TaskNotFoundException::create($id);
        }

        return $this->tasks[$id];
    }

    /**
     * Whether a task with the given ID is registered.
     */
    public function has(string $id): bool
    {
        return isset($this->tasks[$id]);
    }

    /**
     * @throws \ReflectionException
     */
    private static function matchesEnvironment(DeployTaskInterface $task, ?string $environment): bool
    {
        if (null === $environment) {
            return true;
        }

        $envs = AsDeployTask::envsOf($task);

        return null === $envs || \in_array($environment, $envs, true);
    }

    /**
     * @param list<string> $requestedGroups
     *
     * @throws \ReflectionException
     */
    private static function matchesGroups(DeployTaskInterface $task, array $requestedGroups): bool
    {
        $declared = AsDeployTask::groupsOf($task);

        if ([] === $requestedGroups) {
            return null === $declared;
        }

        if (null === $declared) {
            return false;
        }

        return [] !== \array_intersect($declared, $requestedGroups);
    }
}
