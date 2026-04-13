<?php

declare(strict_types=1);

namespace Soviann\DeployTasks\Tests\Fixtures;

use Soviann\DeployTasks\Contract\DeployTaskInterface;
use Soviann\DeployTasks\Contract\TaskIdResolverInterface;

final class CustomIdResolverFixture implements TaskIdResolverInterface
{
    public function resolve(DeployTaskInterface $task): string
    {
        return 'custom.'.$task::class;
    }
}
