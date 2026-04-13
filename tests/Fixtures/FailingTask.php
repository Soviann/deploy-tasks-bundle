<?php

declare(strict_types=1);

namespace Soviann\DeployTasks\Tests\Fixtures;

use Soviann\DeployTasks\Contract\Attribute\AsDeployTask;
use Soviann\DeployTasks\Contract\DeployTaskInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsDeployTask(id: 'test.failing')]
final class FailingTask implements DeployTaskInterface
{
    public function getId(): string
    {
        return 'test.failing';
    }

    public function getDescription(): string
    {
        return 'A task that always fails';
    }

    public function run(OutputInterface $output): int
    {
        throw new \RuntimeException('Task failed!');
    }
}
