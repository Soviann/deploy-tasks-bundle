<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Tests\Fixtures;

use Soviann\DeployTasksBundle\Attribute\AsDeployTask;
use Soviann\DeployTasksBundle\DeployTaskInterface;
use Soviann\DeployTasksBundle\TaskResult;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Declares a 1s per-task slow-task threshold and sleeps past it (~1.1s), so the
 * runner's slow-task warning must fire from the attribute value even when the
 * configured threshold is far higher. Slow by design.
 */
#[AsDeployTask(id: 'test.slow_threshold_lowering', slowTaskThreshold: 1)]
final class SlowThresholdLoweringTask implements DeployTaskInterface
{
    public function getDescription(): string
    {
        return 'Sleeps past its own 1s slow-task threshold';
    }

    public function run(OutputInterface $output): TaskResult
    {
        \usleep(1_100_000);

        return TaskResult::SUCCESS;
    }
}
