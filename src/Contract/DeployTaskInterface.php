<?php

declare(strict_types=1);

namespace Soviann\DeployTasks\Contract;

use Symfony\Component\Console\Output\OutputInterface;

/**
 * A one-time deploy task that runs once per environment.
 *
 * Implement this interface and tag with #[AsDeployTask] to register
 * a task that will be discovered and executed by the deploy runner.
 */
interface DeployTaskInterface
{
    /**
     * Unique identifier for this task (e.g. "app.2026_04_12.seed_categories").
     */
    public function getId(): string;

    /**
     * Human-readable description shown in CLI output.
     */
    public function getDescription(): string;

    /**
     * Execute the task logic.
     *
     * @return TaskResult::*
     */
    public function run(OutputInterface $output): int;
}
