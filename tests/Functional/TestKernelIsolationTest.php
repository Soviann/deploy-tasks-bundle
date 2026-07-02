<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Tests\Functional;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Soviann\DeployTasksBundle\Tests\Fixtures\FailingTask;
use Soviann\DeployTasksBundle\Tests\Fixtures\ProdOnlyGroupedTask;

#[CoversNothing]
final class TestKernelIsolationTest extends TestCase
{
    public function testKernelsWithDifferentExtraTasksNeverShareACompiledContainer(): void
    {
        $kernelA = new TestKernel('test', true, extraTasks: [FailingTask::class]);
        $kernelA->boot();
        $kernelA->shutdown();
        \restore_exception_handler();

        $kernelB = new TestKernel('test', true, extraTasks: [ProdOnlyGroupedTask::class]);
        $kernelB->boot();
        self::assertNotSame(
            $kernelA->getCacheDir(),
            $kernelB->getCacheDir(),
            'Different extraTasks must compile into different cache dirs'
        );
        $kernelB->shutdown();
        \restore_exception_handler();
    }
}
