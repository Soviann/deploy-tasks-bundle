<?php

declare(strict_types=1);

namespace Soviann\DeployTasks\Contract;

/**
 * Optional interface for tasks that compute their ID dynamically.
 *
 * When a task implements this interface, the default ID resolver
 * uses getTaskId() with highest priority — before the #[AsDeployTask]
 * attribute id and FQCN auto-deduction.
 */
interface TaskIdProviderInterface extends DeployTaskInterface
{
    /**
     * Returns the task's unique identifier.
     */
    public function getTaskId(): string;
}
