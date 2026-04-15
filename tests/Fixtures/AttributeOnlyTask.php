<?php

declare(strict_types=1);

namespace Soviann\DeployTasks\Tests\Fixtures;

use Soviann\DeployTasks\Contract\Attribute\AsDeployTask;
use Soviann\DeployTasks\Contract\DeployTaskInterface;
use Soviann\DeployTasks\Contract\TaskResult;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Task with no TaskIdProviderInterface, so the resolver should use the attribute id.
 */
#[AsDeployTask(id: 'attribute_only')]
final class AttributeOnlyTask implements DeployTaskInterface
{
    public function getDescription(): string
    {
        return 'Task with attribute ID only';
    }

    public function run(OutputInterface $output): TaskResult
    {
        return TaskResult::SUCCESS;
    }
}
