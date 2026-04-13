<?php

declare(strict_types=1);

namespace Soviann\DeployTasks\Contract;

/**
 * Resolves the canonical ID for a deploy task.
 */
interface TaskIdResolverInterface
{
    /**
     * Resolves the canonical ID for a task.
     */
    public function resolve(DeployTaskInterface $task): string;
}
