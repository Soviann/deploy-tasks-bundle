<?php

declare(strict_types=1);

namespace Soviann\DeployTasks\Tests\Fixtures;

use Soviann\DeployTasks\Contract\DeployTaskInterface;
use Soviann\DeployTasks\Contract\TaskResult;
use Symfony\Component\Console\Output\OutputInterface;

final class SimpleTask implements DeployTaskInterface
{
    public function __construct(
        private readonly string $id,
        private readonly string $description = 'Simple task',
    ) {
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function run(OutputInterface $output): int
    {
        return TaskResult::SUCCESS;
    }
}
