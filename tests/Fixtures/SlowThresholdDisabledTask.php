<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Tests\Fixtures;

use Soviann\DeployTasksBundle\Attribute\AsDeployTask;
use Soviann\DeployTasksBundle\DeployTaskInterface;
use Soviann\DeployTasksBundle\TaskResult;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Opts out of the slow-task check (`slowTaskThreshold: 0`) and sleeps ~1.1s, so
 * no slow-task warning may fire even when the configured threshold would.
 * Slow by design.
 */
#[AsDeployTask(id: 'test.slow_threshold_disabled', slowTaskThreshold: 0)]
final class SlowThresholdDisabledTask implements DeployTaskInterface
{
    public function getDescription(): string
    {
        return 'Sleeps with the slow-task check disabled';
    }

    public function run(OutputInterface $output): TaskResult
    {
        \usleep(1_100_000);

        return TaskResult::SUCCESS;
    }
}
