<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Tests\Fixtures;

use Soviann\DeployTasksBundle\Attribute\AsDeployTask;
use Soviann\DeployTasksBundle\DeployTaskInterface;
use Soviann\DeployTasksBundle\TaskResult;
use Symfony\Component\Console\Output\OutputInterface;

#[AsDeployTask(id: 'test.returns_failure')]
final class ReturnsFailureTask implements DeployTaskInterface
{
    public function __construct(private readonly TaskResult $result = TaskResult::FAILURE)
    {
    }

    public function getDescription(): string
    {
        return 'Returns TaskResult::FAILURE without throwing';
    }

    public function run(OutputInterface $output): TaskResult
    {
        return $this->result;
    }
}
