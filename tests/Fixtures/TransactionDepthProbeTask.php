<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Tests\Fixtures;

use Soviann\DeployTasksBundle\DeployTaskInterface;
use Soviann\DeployTasksBundle\Identifier\TaskIdProviderInterface;
use Soviann\DeployTasksBundle\TaskResult;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Attribute-less task recording whether its run() executed inside a
 * storage transaction, probed via the fixture's transaction depth.
 */
final class TransactionDepthProbeTask implements DeployTaskInterface, TaskIdProviderInterface
{
    public bool $ranInsideTransaction = false;

    public function __construct(
        private readonly string $taskId,
        private readonly RollbackTransactionalStorageFixture $storage,
    ) {
    }

    public function getTaskId(): string
    {
        return $this->taskId;
    }

    public function getDescription(): string
    {
        return 'Probes the transaction depth at run() time';
    }

    public function run(OutputInterface $output): TaskResult
    {
        $this->ranInsideTransaction = $this->storage->transactionDepth > 0;

        return TaskResult::SUCCESS;
    }
}
