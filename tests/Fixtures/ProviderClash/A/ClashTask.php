<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Tests\Fixtures\ProviderClash\A;

use Soviann\DeployTasksBundle\DeployTaskInterface;
use Soviann\DeployTasksBundle\Identifier\TaskIdProviderInterface;
use Soviann\DeployTasksBundle\TaskResult;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Provider task sharing its short class name with ProviderClash\B\ClashTask.
 */
final class ClashTask implements DeployTaskInterface, TaskIdProviderInterface
{
    public function getTaskId(): string
    {
        return 'provider.a.clash';
    }

    public function getDescription(): string
    {
        return 'Provider clash task A';
    }

    public function run(OutputInterface $output): TaskResult
    {
        return TaskResult::SUCCESS;
    }
}
