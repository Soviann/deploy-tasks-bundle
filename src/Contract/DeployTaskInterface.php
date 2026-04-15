<?php

declare(strict_types=1);

namespace Soviann\DeployTasks\Contract;

use Symfony\Component\Console\Output\OutputInterface;

/**
 * A one-time deploy task that runs once per environment.
 *
 * Implement this interface to register a task that will be discovered
 * and executed by the deploy runner. Optionally add #[AsDeployTask]
 * to configure priority, environment filtering, timeout, and transactional behavior.
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
