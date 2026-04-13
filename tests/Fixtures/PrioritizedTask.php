<?php

declare(strict_types=1);

namespace Soviann\DeployTasks\Tests\Fixtures;

use Soviann\DeployTasks\Contract\Attribute\AsDeployTask;
use Soviann\DeployTasks\Contract\DeployTaskInterface;
use Soviann\DeployTasks\Contract\TaskResult;
use Symfony\Component\Console\Output\OutputInterface;

#[AsDeployTask(id: 'test.prioritized', priority: 10)]
final class PrioritizedTask implements DeployTaskInterface
{
    public function getId(): string
    {
        return 'test.prioritized';
    }

    public function getDescription(): string
    {
        return 'Prioritized task';
    }

    public function run(OutputInterface $output): int
    {
        return TaskResult::SUCCESS;
    }
}
