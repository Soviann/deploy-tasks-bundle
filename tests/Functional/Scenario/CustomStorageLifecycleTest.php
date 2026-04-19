<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Tests\Functional\Scenario;

use Soviann\DeployTasksBundle\Tests\Functional\Scenario\Kernel\CustomLifecycleScenarioKernel;

final class CustomStorageLifecycleTest extends AbstractStorageLifecycleTestCase
{
    protected static function getKernelClass(): string
    {
        return CustomLifecycleScenarioKernel::class;
    }
}
