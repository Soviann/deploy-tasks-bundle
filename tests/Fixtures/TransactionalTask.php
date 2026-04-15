<?php

declare(strict_types=1);

namespace Soviann\DeployTasks\Tests\Fixtures;

use Soviann\DeployTasks\Contract\Attribute\AsDeployTask;
use Soviann\DeployTasks\Contract\DeployTaskInterface;
use Soviann\DeployTasks\Contract\TaskResult;
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
