<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Tests\Fixtures;

use Soviann\DeployTasksBundle\Attribute\AsDeployTask;
use Soviann\DeployTasksBundle\DeployTaskInterface;
use Soviann\DeployTasksBundle\TaskResult;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Fails with 4-byte UTF-8 characters in its message — the shape a utf8mb3
 * MySQL/MariaDB connection rejects with error 1366.
 */
#[AsDeployTask(id: 'test.four_byte_error')]
final class FourByteErrorTask implements DeployTaskInterface
{
    public const MESSAGE = "Payment provider down \u{1F4A5} (caf\u{E9} \u{1D11E})";

    public function getDescription(): string
    {
        return 'A task whose error message holds 4-byte characters';
    }

    public function run(OutputInterface $output): TaskResult
    {
        throw new \RuntimeException(self::MESSAGE);
    }
}
