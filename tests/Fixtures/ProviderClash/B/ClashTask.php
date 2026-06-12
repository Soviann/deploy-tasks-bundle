<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Tests\Fixtures\ProviderClash\B;

use Soviann\DeployTasksBundle\DeployTaskInterface;
use Soviann\DeployTasksBundle\Identifier\TaskIdProviderInterface;
use Soviann\DeployTasksBundle\TaskResult;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Provider task sharing its short class name with ProviderClash\A\ClashTask.
 */
final class ClashTask implements DeployTaskInterface, TaskIdProviderInterface
{
    public function getTaskId(): string
    {
        return 'provider.b.clash';
    }

    public function getDescription(): string
    {
        return 'Provider clash task B';
    }

    public function run(OutputInterface $output): TaskResult
    {
        return TaskResult::SUCCESS;
    }
}
