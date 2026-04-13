<?php

declare(strict_types=1);

namespace Soviann\DeployTasks\Tests\Unit\Contract;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Soviann\DeployTasks\Contract\TaskStatus;

#[CoversClass(TaskStatus::class)]
final class TaskStatusTest extends TestCase
{
    public function testRanCaseValue(): void
    {
        self::assertSame('ran', TaskStatus::Ran->value);
    }

    public function testFailedCaseValue(): void
    {
        self::assertSame('failed', TaskStatus::Failed->value);
    }

    public function testSkippedCaseValue(): void
    {
        self::assertSame('skipped', TaskStatus::Skipped->value);
    }

    public function testFromRan(): void
    {
        self::assertSame(TaskStatus::Ran, TaskStatus::from('ran'));
    }

    public function testFromFailed(): void
    {
        self::assertSame(TaskStatus::Failed, TaskStatus::from('failed'));
    }

    public function testFromSkipped(): void
    {
        self::assertSame(TaskStatus::Skipped, TaskStatus::from('skipped'));
    }
}
