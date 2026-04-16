<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Tests\Fixtures;

use Soviann\DeployTasksBundle\Attribute\AsDeployTask;
use Soviann\DeployTasksBundle\Identifier\TaskIdProviderInterface;
use Soviann\DeployTasksBundle\TaskResult;
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
