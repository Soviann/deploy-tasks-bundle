<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Runner;

use Soviann\DeployTasksBundle\Attribute\AsDeployTask;
use Soviann\DeployTasksBundle\DeployTaskInterface;
use Soviann\DeployTasksBundle\Exception\TaskGroupMismatchException;
use Soviann\DeployTasksBundle\Exception\TaskGroupRequiredException;

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
     * @param list<string> $groups requested group name(s); [] targets the default slot
     *
     * @return list<?string>
     *
     * @throws TaskGroupRequiredException When the task declares groups but none was requested
     * @throws TaskGroupMismatchException When a requested group is not declared on the task
     * @throws \ReflectionException       When the #[AsDeployTask] attribute lookup fails
     */
    public static function resolve(string $taskId, DeployTaskInterface $task, array $groups): array
    {
        $declared = AsDeployTask::groupsOf($task);

        if ([] === $groups) {
            if (null !== $declared) {
                throw TaskGroupRequiredException::create($taskId, $declared);
            }

            return [null];
        }

        if (null === $declared) {
            throw TaskGroupMismatchException::create($taskId, $groups, []);
        }

        $undeclared = \array_values(\array_diff($groups, $declared));

        if ([] !== $undeclared) {
            throw TaskGroupMismatchException::create($taskId, $undeclared, $declared);
        }

        return $groups;
    }
}
