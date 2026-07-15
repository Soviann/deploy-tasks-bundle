<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Tests\Fixtures;

use Soviann\DeployTasksBundle\Attribute\AsDeployTask;
use Soviann\DeployTasksBundle\DeployTaskInterface;
use Soviann\DeployTasksBundle\TaskResult;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Fails with an error message that tries to smuggle a console formatter tag
 * (terminal-hyperlink spoof) and a raw ANSI escape sequence into the output.
 */
#[AsDeployTask(id: 'test.hostile_error')]
final class HostileErrorTask implements DeployTaskInterface
{
    public function getDescription(): string
    {
        return 'A task whose error message carries a formatter tag and an ANSI escape';
    }

    public function run(OutputInterface $output): TaskResult
    {
        throw new \RuntimeException("<href=https://evil.example>spoof</>\x1b[2Jboom");
    }
}
