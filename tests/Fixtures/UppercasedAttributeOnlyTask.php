<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Tests\Fixtures;

use Soviann\DeployTasksBundle\Attribute\AsDeployTask;
use Soviann\DeployTasksBundle\DeployTaskInterface;
use Soviann\DeployTasksBundle\TaskResult;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Attribute id differs from AttributeOnlyTask's "attribute_only" only by letter
 * case — used to exercise the case-insensitive id collision guard.
 */
#[AsDeployTask(id: 'Attribute_Only')]
final class UppercasedAttributeOnlyTask implements DeployTaskInterface
{
    public function getDescription(): string
    {
        return 'Task whose attribute ID collides with AttributeOnlyTask by case only';
    }

    public function run(OutputInterface $output): TaskResult
    {
        return TaskResult::SUCCESS;
    }
}
