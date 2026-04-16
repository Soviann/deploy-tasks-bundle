<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Tests\Fixtures;

use Soviann\DeployTasksBundle\Ordering\OrderedTaskCollection;
use Soviann\DeployTasksBundle\Ordering\TaskOrderResolverInterface;

final class CustomOrderResolverFixture implements TaskOrderResolverInterface
{
    public function resolve(array $tasks): OrderedTaskCollection
    {
        return new OrderedTaskCollection(...$tasks);
    }
}
