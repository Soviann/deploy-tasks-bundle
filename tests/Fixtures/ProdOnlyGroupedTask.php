<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Tests\Fixtures;

use Soviann\DeployTasksBundle\Attribute\AsDeployTask;
use Soviann\DeployTasksBundle\DeployTaskInterface;
use Soviann\DeployTasksBundle\TaskResult;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Grouped AND env-restricted: in any non-prod environment this is the only task
 * matching `--group=prodonly`, letting tests prove that --require-some honours
 * the environment filter (a group match alone must not satisfy the gate).
 */
#[AsDeployTask(id: 'test.prod_only_grouped', env: 'prod', groups: 'prodonly', description: 'Prod-only grouped task')]
final class ProdOnlyGroupedTask implements DeployTaskInterface
{
    public function getDescription(): string
    {
        return 'Prod-only grouped task';
    }

    public function run(OutputInterface $output): TaskResult
    {
        return TaskResult::SUCCESS;
    }
}
