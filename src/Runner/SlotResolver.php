<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Runner;

use Soviann\DeployTasksBundle\Attribute\AsDeployTask;
use Soviann\DeployTasksBundle\DeployTaskInterface;
use Soviann\DeployTasksBundle\Exception\TaskGroupMismatchException;

/**
 * Resolves the storage slots targeted when a single task is addressed
 * explicitly (deploytasks:run --id, deploytasks:skip) — the one owner of the
 * group-selection rules, so the runner and the ops commands cannot drift.
 *
 * @internal
 */
final class SlotResolver
{
    /**
     * Resolves the slots a single addressed task is targeted on.
     *
     * Same expansion as a bulk run ({@see self::expand()}), plus strict
     * validation: addressing a task with a group it does not declare is an
     * error, where a bulk run would silently filter the task out.
     *
     * @param list<string> $groups requested group name(s); [] targets every slot
     *
     * @return list<?string> in declared order, regardless of the request order
     *
     * @throws TaskGroupMismatchException When a requested group is not declared on the task
     * @throws \ReflectionException       When the #[AsDeployTask] attribute lookup fails
     */
    public static function resolve(string $taskId, DeployTaskInterface $task, array $groups): array
    {
        $declared = AsDeployTask::groupsOf($task);

        if ([] !== $groups) {
            if (null === $declared) {
                throw TaskGroupMismatchException::create($taskId, $groups, []);
            }

            $undeclared = \array_values(\array_diff($groups, $declared));

            if ([] !== $undeclared) {
                throw TaskGroupMismatchException::create($taskId, $undeclared, $declared);
            }
        }

        return self::expand($declared, $groups);
    }

    /**
     * Expands the slots a task participates in for the current invocation.
     *
     * Mirrors {@see \Soviann\DeployTasksBundle\Command\DeployTasksRollupCommand::slotsFor()}: with no
     * requested groups, every slot is targeted — the default slot for an
     * ungrouped task, every declared group for a grouped one. A non-empty
     * request narrows to the intersection with the declared groups, in
     * declared order; the default slot is only ever targeted by an unfiltered
     * invocation.
     *
     * The expansion can never yield a duplicate slot: requested groups are
     * deduplicated by {@see RunOptions}, declared groups by the attribute.
     *
     * @param list<string>|null $declaredGroups  groups declared on the task; null when ungrouped
     * @param list<string>      $requestedGroups [] targets every slot
     *
     * @return list<?string>
     */
    public static function expand(?array $declaredGroups, array $requestedGroups): array
    {
        if (null === $declaredGroups) {
            return [] === $requestedGroups ? [null] : [];
        }

        if ([] === $requestedGroups) {
            return $declaredGroups;
        }

        return \array_values(\array_intersect($declaredGroups, $requestedGroups));
    }
}
