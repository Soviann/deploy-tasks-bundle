<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Sorting;

use Soviann\DeployTasksBundle\DeployTaskInterface;

/**
 * Sorts deploy tasks into execution order.
 *
 * Implementations MUST return a permutation of the input: dropping a task makes the
 * runner silently never execute it, and adding one executes an unregistered task.
 * Input order is the registration order (container definition order) — the default
 * sorter relies on it for stable ordering of equal-priority tasks, and custom
 * implementations may do the same. Task IDs, when needed, can be resolved via
 * {@see \Soviann\DeployTasksBundle\Identifier\TaskIdResolver}.
 */
interface TaskSorterInterface
{
    /**
     * Returns the tasks sorted in the order they should be executed.
     *
     * @param array<DeployTaskInterface> $tasks
     *
     * @return list<DeployTaskInterface> A permutation of $tasks
     */
    public function sort(array $tasks): array;
}
