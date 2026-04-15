<?php

declare(strict_types=1);

namespace Soviann\DeployTasks\Contract;

/**
 * Resolves the canonical ID for a deploy task.
 *
 * Default resolution order (implemented by DefaultTaskIdResolver):
 *  1. {@see TaskIdProviderInterface::getTaskId()} — if the task implements it and returns non-empty
 *  2. {@see Attribute\AsDeployTask} attribute `id` — if present and non-empty
 *  3. Auto-deduced from FQCN via {@see TaskIdGeneratorInterface}
 */
interface TaskIdResolverInterface
{
    public function resolve(DeployTaskInterface $task): string;
}
