<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Tests\Fixtures;

use Soviann\DeployTasksBundle\Attribute\AsDeployTask;
use Soviann\DeployTasksBundle\DeployTaskInterface;
use Soviann\DeployTasksBundle\TaskResult;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Declares only the hard-kill knob (`timeout:`) and sleeps ~1.1s, pinning that
 * `timeout:` no longer influences the runner's slow-task check — the warning
 * must still fire from the configured threshold. Slow by design.
 */
#[AsDeployTask(id: 'test.hard_timeout_only', timeout: 3600)]
final class HardTimeoutOnlySleepingTask implements DeployTaskInterface
{
    public function getDescription(): string
    {
        return 'Sleeps with only a hard Process timeout declared';
    }

    public function run(OutputInterface $output): TaskResult
    {
        \usleep(1_100_000);

        return TaskResult::SUCCESS;
    }
}
