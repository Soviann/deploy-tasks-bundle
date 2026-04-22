<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Tests\Fixtures;

use Soviann\DeployTasksBundle\DeployTaskInterface;
use Soviann\DeployTasksBundle\Identifier\TaskIdProviderInterface;
use Soviann\DeployTasksBundle\TaskResult;
use Symfony\Component\Console\Output\OutputInterface;

final class SleepingTask implements DeployTaskInterface, TaskIdProviderInterface
{
    public function __construct(
        private readonly string $id,
        private readonly int $sleepMicroseconds,
    ) {
    }

    public function getTaskId(): string
    {
        return $this->id;
    }

    public function getDescription(): string
    {
        return 'Sleeping task';
    }

    public function run(OutputInterface $output): TaskResult
    {
        \usleep($this->sleepMicroseconds);

        return TaskResult::SUCCESS;
    }
}
