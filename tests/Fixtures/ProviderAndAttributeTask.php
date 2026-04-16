<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Tests\Fixtures;

use Soviann\DeployTasksBundle\Attribute\AsDeployTask;
use Soviann\DeployTasksBundle\Identifier\TaskIdProviderInterface;
use Soviann\DeployTasksBundle\TaskResult;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Task where attribute id and getTaskId() return the same value.
 */
#[AsDeployTask(id: 'matching_id')]
final class ProviderAndAttributeTask implements TaskIdProviderInterface
{
    public function getTaskId(): string
    {
        return 'matching_id';
    }

    public function getDescription(): string
    {
        return 'Task with matching provider and attribute IDs';
    }

    public function run(OutputInterface $output): TaskResult
    {
        return TaskResult::SUCCESS;
    }
}
