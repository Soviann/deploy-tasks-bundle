<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Tests\Fixtures;

use Soviann\DeployTasksBundle\Attribute\AsDeployTask;
use Soviann\DeployTasksBundle\DeployTaskInterface;
use Soviann\DeployTasksBundle\TaskResult;
use Symfony\Component\Console\Output\OutputInterface;

#[AsDeployTask(id: 'test.multi_group', groups: ['predeploy', 'postdeploy'])]
final class MultiGroupTask implements DeployTaskInterface
{
    public function getDescription(): string
    {
        return 'Task declared in multiple groups';
    }

    public function run(OutputInterface $output): TaskResult
    {
        return TaskResult::SUCCESS;
    }
}
