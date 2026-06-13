<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Tests\Fixtures;

use Soviann\DeployTasksBundle\Attribute\AsDeployTask;
use Soviann\DeployTasksBundle\DeployTaskInterface;
use Soviann\DeployTasksBundle\TaskResult;
use Symfony\Component\Console\Output\OutputInterface;

#[AsDeployTask(id: 'test.grouped_failing', groups: ['unstable'])]
final class GroupedFailingTask implements DeployTaskInterface
{
    public function getDescription(): string
    {
        return 'A grouped task that always fails';
    }

    public function run(OutputInterface $output): TaskResult
    {
        throw new \RuntimeException('Task failed!');
    }
}
