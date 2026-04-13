<?php

declare(strict_types=1);

namespace Soviann\DeployTasks\Tests\Unit\Contract;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Soviann\DeployTasks\Contract\TaskResult;

#[CoversClass(TaskResult::class)]
final class TaskResultTest extends TestCase
{
    public function testSuccessConstantValue(): void
    {
        self::assertSame(0, TaskResult::SUCCESS);
    }

    public function testFailureConstantValue(): void
    {
        self::assertSame(1, TaskResult::FAILURE);
    }

    public function testSkippedConstantValue(): void
    {
        self::assertSame(2, TaskResult::SKIPPED);
    }
}
