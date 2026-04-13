<?php

declare(strict_types=1);

namespace Soviann\DeployTasks\Tests\Fixtures;

use Soviann\DeployTasks\Contract\Attribute\AsDeployTask;
use Soviann\DeployTasks\Contract\DeployTaskInterface;
use Soviann\DeployTasks\Contract\TaskResult;
use Symfony\Component\Console\Output\OutputInterface;

#[AsDeployTask(id: 'test.prod_only', env: 'prod', description: 'Prod-only task')]
final class ProdOnlyTask implements DeployTaskInterface
{
    public function getDescription(): string
    {
        return 'Prod-only task';
    }

    public function run(OutputInterface $output): int
    {
        return TaskResult::SUCCESS;
    }
}
