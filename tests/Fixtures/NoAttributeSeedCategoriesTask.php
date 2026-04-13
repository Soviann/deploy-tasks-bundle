<?php

declare(strict_types=1);

namespace Soviann\DeployTasks\Tests\Fixtures;

use Soviann\DeployTasks\Contract\DeployTaskInterface;
use Soviann\DeployTasks\Contract\TaskResult;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Task with no attribute and no TaskIdProviderInterface, used to test FQCN auto-deduction.
 * Expected deduced ID: "no_attribute_seed_categories" (short class name → snake_case, "Task" suffix stripped).
 */
final class NoAttributeSeedCategoriesTask implements DeployTaskInterface
{
    public function getDescription(): string
    {
        return 'Seed categories (no attribute)';
    }

    public function run(OutputInterface $output): int
    {
        return TaskResult::SUCCESS;
    }
}
