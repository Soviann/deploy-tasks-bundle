<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Tests\Unit\Contract;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Soviann\DeployTasksBundle\TaskResult;

#[CoversClass(TaskResult::class)]
final class TaskResultTest extends TestCase
{
    public function testSuccessBackingValue(): void
    {
        self::assertSame(0, TaskResult::SUCCESS->value);
    }

    public function testFailureBackingValue(): void
    {
        self::assertSame(1, TaskResult::FAILURE->value);
    }

    public function testSkippedBackingValue(): void
    {
        self::assertSame(2, TaskResult::SKIPPED->value);
    }
}
