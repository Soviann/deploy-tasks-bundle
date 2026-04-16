<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle;

use Symfony\Component\Console\Output\OutputInterface;

/**
 * A one-time deploy task that runs once per (task, group) slot.
 *
 * Implement this interface to register a task that will be discovered
 * and executed by the deploy runner. Optionally add #[AsDeployTask] to
 * configure id, priority, environment filtering, timeout, transactional
 * behavior, description, and group membership.
 *
 * @see TaskIdProviderInterface  Optional interface to provide a dynamic task ID
 * @see Attribute\AsDeployTask   Attribute for static configuration (id, priority, env, timeout, transactional, description, groups)
 */
interface DeployTaskInterface
{
    /**
     * Human-readable description shown in CLI output.
     */
    public function getDescription(): string;

    /**
     * Execute the task logic.
     */
    public function run(OutputInterface $output): TaskResult;
}
