<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Tests\Fixtures;

use Doctrine\DBAL\Exception\InvalidArgumentException as DbalInvalidArgumentException;
use Soviann\DeployTasksBundle\Attribute\AsDeployTask;
use Soviann\DeployTasksBundle\DeployTaskInterface;
use Soviann\DeployTasksBundle\Exception\StorageException;
use Soviann\DeployTasksBundle\TaskResult;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Task that throws a StorageException wrapping a DBAL exception — the exact
 * shape that used to let DSN fragments surface in the logger's previous-trace
 * serialisation. Kept in the fixtures namespace so it's reusable across the
 * TaskRunner tests that verify the scrubbing behaviour.
 */
#[AsDeployTask(id: 'test.dbal-failing')]
final class DbalFailingTask implements DeployTaskInterface
{
    public const DBAL_MESSAGE = 'Connection to postgres://user:s3cret@db.example.com/app failed';

    public function getDescription(): string
    {
        return 'A task whose failure chain contains a Doctrine DBAL exception';
    }

    public function run(OutputInterface $output): TaskResult
    {
        throw new StorageException('Failed to save task "test.dbal-failing".', 0, new DbalInvalidArgumentException(self::DBAL_MESSAGE));
    }
}
