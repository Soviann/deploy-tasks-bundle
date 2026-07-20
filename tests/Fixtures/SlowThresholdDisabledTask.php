<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Tests\Fixtures;

use Soviann\DeployTasksBundle\Attribute\AsDeployTask;
use Soviann\DeployTasksBundle\DeployTaskInterface;
use Soviann\DeployTasksBundle\TaskResult;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Opts out of the slow-task check (`slowTaskThreshold: 0`). The injected
 * $onRun hook lets a test advance a MockClock past any threshold while the
 * task "runs", so no slow-task warning may fire — without any real sleeping.
 */
#[AsDeployTask(id: 'test.slow_threshold_disabled', slowTaskThreshold: 0)]
final class SlowThresholdDisabledTask implements DeployTaskInterface
{
    public function __construct(private readonly ?\Closure $onRun = null)
    {
    }

    public function getDescription(): string
    {
        return 'Simulates a long run with the slow-task check disabled';
    }

    public function run(OutputInterface $output): TaskResult
    {
        if (null !== $this->onRun) {
            ($this->onRun)();
        }

        return TaskResult::SUCCESS;
    }
}
