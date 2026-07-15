<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Tests\Fixtures;

use Soviann\DeployTasksBundle\Attribute\AsDeployTask;
use Soviann\DeployTasksBundle\DeployTaskInterface;
use Soviann\DeployTasksBundle\TaskResult;
use Symfony\Component\Console\Output\OutputInterface;

#[AsDeployTask(id: 'test.non_transactional', transactional: false)]
final class NonTransactionalTask implements DeployTaskInterface
{
    public function getDescription(): string
    {
        return 'Task opting out of the per-task transaction';
    }

    public function run(OutputInterface $output): TaskResult
    {
        $output->writeln('Non-transactional task executed');

        return TaskResult::SUCCESS;
    }
}
