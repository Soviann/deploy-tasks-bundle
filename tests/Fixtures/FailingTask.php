<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Tests\Fixtures;

use Soviann\DeployTasksBundle\Attribute\AsDeployTask;
use Soviann\DeployTasksBundle\DeployTaskInterface;
use Soviann\DeployTasksBundle\TaskResult;
use Symfony\Component\Console\Output\OutputInterface;

#[AsDeployTask(id: 'test.failing')]
final class FailingTask implements DeployTaskInterface
{
    public function getDescription(): string
    {
        return 'A task that always fails';
    }

    public function run(OutputInterface $output): TaskResult
    {
        throw new \RuntimeException('Task failed!');
    }
}
