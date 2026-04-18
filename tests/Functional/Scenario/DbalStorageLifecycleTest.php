<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Tests\Functional\Scenario;

use Soviann\DeployTasksBundle\Tests\Functional\Scenario\Kernel\DbalLifecycleScenarioKernel;

final class DbalStorageLifecycleTest extends AbstractStorageLifecycleTestCase
{
    protected static function getKernelClass(): string
    {
        return DbalLifecycleScenarioKernel::class;
    }
}
