<?php

declare(strict_types=1);

namespace Soviann\DeployTasks\Tests\Fixtures;

use Soviann\DeployTasks\Contract\Attribute\AsDeployTask;
use Soviann\DeployTasks\Contract\DeployTaskInterface;
use Soviann\DeployTasks\Contract\TaskResult;
use Symfony\Component\Console\Output\OutputInterface;

#[AsDeployTask(id: 'test.skipping')]
final class SkippingTask implements DeployTaskInterface
{
    public function getDescription(): string
    {
        return 'A task that skips itself';
    }

    public function run(OutputInterface $output): int
    {
        return TaskResult::SKIPPED;
    }
}
