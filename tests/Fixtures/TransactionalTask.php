<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Tests\Fixtures;

use Soviann\DeployTasksBundle\Attribute\AsDeployTask;
use Soviann\DeployTasksBundle\DeployTaskInterface;
use Soviann\DeployTasksBundle\TaskResult;
use Symfony\Component\Console\Output\OutputInterface;

#[AsDeployTask(id: 'test.transactional', transactional: true)]
final class TransactionalTask implements DeployTaskInterface
{
    public function getDescription(): string
    {
        return 'Transactional task';
    }

    public function run(OutputInterface $output): TaskResult
    {
        $output->writeln('Transactional task executed');

        return TaskResult::SUCCESS;
    }
}
