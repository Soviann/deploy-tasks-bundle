<?php

declare(strict_types=1);

namespace Soviann\DeployTasks\Contract;

/**
 * Determines the execution order of deploy tasks.
 */
interface TaskOrderResolverInterface
{
    /**
     * Returns the tasks sorted in the order they should be executed.
     *
     * @param array<DeployTaskInterface> $tasks
     */
    public function resolve(array $tasks): OrderedTaskCollection;
}
