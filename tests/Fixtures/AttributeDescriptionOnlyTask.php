<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Tests\Fixtures;

use Soviann\DeployTasksBundle\Attribute\AsDeployTask;
use Soviann\DeployTasksBundle\DeployTaskInterface;
use Soviann\DeployTasksBundle\TaskResult;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Task that returns an empty description from the interface method but declares
 * one via #[AsDeployTask(description: ...)] — exercises the attribute fallback
 * in TaskDescriptionResolver.
 */
#[AsDeployTask(id: 'test.attribute_description', description: 'From attribute only')]
final class AttributeDescriptionOnlyTask implements DeployTaskInterface
{
    public function getDescription(): string
    {
        return '';
    }

    public function run(OutputInterface $output): TaskResult
    {
        return TaskResult::SUCCESS;
    }
}
