<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Tests\Fixtures;

use Soviann\DeployTasksBundle\Attribute\AsDeployTask;
use Soviann\DeployTasksBundle\DeployTaskInterface;
use Soviann\DeployTasksBundle\TaskResult;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Declares only the hard-kill knob (`timeout:`), pinning that `timeout:` no
 * longer influences the runner's slow-task check. The injected $onRun hook
 * lets a test advance a MockClock past the configured threshold while the
 * task "runs" — the warning must still fire, without any real sleeping.
 */
#[AsDeployTask(id: 'test.hard_timeout_only', timeout: 3600)]
final class HardTimeoutOnlySleepingTask implements DeployTaskInterface
{
    public function __construct(private readonly ?\Closure $onRun = null)
    {
    }

    public function getDescription(): string
    {
        return 'Simulates a long run with only a hard Process timeout declared';
    }

    public function run(OutputInterface $output): TaskResult
    {
        if (null !== $this->onRun) {
            ($this->onRun)();
        }

        return TaskResult::SUCCESS;
    }
}
