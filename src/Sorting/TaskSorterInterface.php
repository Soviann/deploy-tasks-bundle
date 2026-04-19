<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Sorting;

use Soviann\DeployTasksBundle\DeployTaskInterface;

/**
 * Sorts deploy tasks into execution order.
 */
interface TaskSorterInterface
{
    /**
     * Returns the tasks sorted in the order they should be executed.
     *
     * @param array<DeployTaskInterface> $tasks
     */
    public function sort(array $tasks): SortedTaskCollection;
}
