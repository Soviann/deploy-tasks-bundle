<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Tests\Fixtures;

use Soviann\DeployTasksBundle\Sorting\TaskSorterInterface;

final class CustomSorterFixture implements TaskSorterInterface
{
    public function sort(array $tasks): array
    {
        return \array_values($tasks);
    }
}
