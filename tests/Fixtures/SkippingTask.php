<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Tests\Fixtures;

use Soviann\DeployTasksBundle\Attribute\AsDeployTask;
use Soviann\DeployTasksBundle\DeployTaskInterface;
use Soviann\DeployTasksBundle\TaskResult;
use Symfony\Component\Console\Output\OutputInterface;

#[AsDeployTask(id: 'test.skipping')]
final class SkippingTask implements DeployTaskInterface
{
    public function getDescription(): string
    {
        return 'A task that skips itself';
    }

    public function run(OutputInterface $output): TaskResult
    {
        return TaskResult::SKIPPED;
    }
}
