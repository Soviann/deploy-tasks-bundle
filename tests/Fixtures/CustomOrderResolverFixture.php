<?php

declare(strict_types=1);

namespace Soviann\DeployTasks\Tests\Fixtures;

use Soviann\DeployTasks\Contract\OrderedTaskCollection;
use Soviann\DeployTasks\Contract\TaskOrderResolverInterface;

final class CustomOrderResolverFixture implements TaskOrderResolverInterface
{
    public function resolve(array $tasks): OrderedTaskCollection
    {
        return new OrderedTaskCollection($tasks);
    }
}
