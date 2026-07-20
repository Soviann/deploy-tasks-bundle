<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Tests\Fixtures;

use Soviann\DeployTasksBundle\Attribute\AsDeployTask;
use Soviann\DeployTasksBundle\DeployTaskInterface;
use Soviann\DeployTasksBundle\TaskResult;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Declares a 1s per-task slow-task threshold. The injected $onRun hook lets a
 * test advance a MockClock past that threshold while the task "runs", so the
 * runner's slow-task warning must fire from the attribute value even when the
 * configured threshold is far higher — without any real sleeping.
 */
#[AsDeployTask(id: 'test.slow_threshold_lowering', slowTaskThreshold: 1)]
final class SlowThresholdLoweringTask implements DeployTaskInterface
{
    public function __construct(private readonly ?\Closure $onRun = null)
    {
    }

    public function getDescription(): string
    {
        return 'Simulates a run past its own 1s slow-task threshold';
    }

    public function run(OutputInterface $output): TaskResult
    {
        if (null !== $this->onRun) {
            ($this->onRun)();
        }

        return TaskResult::SUCCESS;
    }
}
