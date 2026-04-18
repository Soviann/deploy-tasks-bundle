<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Tests\Functional\Scenario;

use Soviann\DeployTasksBundle\Tests\Functional\Scenario\Kernel\FilesystemLifecycleScenarioKernel;

final class FilesystemStorageLifecycleTest extends AbstractStorageLifecycleTestCase
{
    protected static function getKernelClass(): string
    {
        return FilesystemLifecycleScenarioKernel::class;
    }
}
