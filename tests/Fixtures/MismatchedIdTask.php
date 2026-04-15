<?php

declare(strict_types=1);

namespace Soviann\DeployTasks\Tests\Fixtures;

use Soviann\DeployTasks\Contract\Attribute\AsDeployTask;
use Soviann\DeployTasks\Contract\TaskIdProviderInterface;
use Soviann\DeployTasks\Contract\TaskResult;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Task where attribute id and getTaskId() return different values.
 */
#[AsDeployTask(id: 'attribute_id')]
final class MismatchedIdTask implements TaskIdProviderInterface
{
    public function getTaskId(): string
    {
        return 'method_id';
    }

    public function getDescription(): string
    {
        return 'Task with mismatched IDs';
    }

    public function run(OutputInterface $output): TaskResult
    {
        return TaskResult::SUCCESS;
    }
}
