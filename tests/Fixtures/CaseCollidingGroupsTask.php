<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Tests\Fixtures;

use Soviann\DeployTasksBundle\Attribute\AsDeployTask;
use Soviann\DeployTasksBundle\DeployTaskInterface;
use Soviann\DeployTasksBundle\TaskResult;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Declares two groups that differ only by letter case — used to exercise the
 * case-insensitive group collision guard. The attribute is only instantiated
 * when read (via reflection), so declaring this fixture is legal; reading it
 * must throw.
 */
#[AsDeployTask(id: 'case_colliding_groups', groups: ['Predeploy', 'predeploy'])]
final class CaseCollidingGroupsTask implements DeployTaskInterface
{
    public function getDescription(): string
    {
        return 'Task whose declared groups collide by case only';
    }

    public function run(OutputInterface $output): TaskResult
    {
        return TaskResult::SUCCESS;
    }
}
