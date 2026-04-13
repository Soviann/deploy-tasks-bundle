<?php

declare(strict_types=1);

namespace Soviann\DeployTasks\Tests\Unit\Contract;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Soviann\DeployTasks\Contract\TaskExecution;
use Soviann\DeployTasks\Contract\TaskStatus;

#[CoversClass(TaskExecution::class)]
final class TaskExecutionTest extends TestCase
{
    public function testConstructionWithAllArguments(): void
    {
        $executedAt = new \DateTimeImmutable('2026-04-13 10:00:00');
        $execution = new TaskExecution(
            id: 'task_seed_categories',
            status: TaskStatus::Failed,
            executedAt: $executedAt,
            error: 'Something went wrong',
        );

        self::assertSame('task_seed_categories', $execution->id);
        self::assertSame(TaskStatus::Failed, $execution->status);
        self::assertSame($executedAt, $execution->executedAt);
        self::assertSame('Something went wrong', $execution->error);
    }

    public function testConstructionWithoutErrorDefaultsToNull(): void
    {
        $executedAt = new \DateTimeImmutable('2026-04-13 10:00:00');
        $execution = new TaskExecution(
            id: 'task_seed_categories',
            status: TaskStatus::Ran,
            executedAt: $executedAt,
        );

        self::assertSame('task_seed_categories', $execution->id);
        self::assertSame(TaskStatus::Ran, $execution->status);
        self::assertSame($executedAt, $execution->executedAt);
        self::assertNull($execution->error);
    }

    public function testConstructionWithErrorString(): void
    {
        $executedAt = new \DateTimeImmutable('2026-04-13 10:00:00');
        $execution = new TaskExecution(
            id: 'task_cleanup',
            status: TaskStatus::Failed,
            executedAt: $executedAt,
            error: 'Database connection failed',
        );

        self::assertSame('Database connection failed', $execution->error);
    }

    public function testConstructionWithSkippedStatus(): void
    {
        $executedAt = new \DateTimeImmutable('2026-04-13 10:00:00');
        $execution = new TaskExecution(
            id: 'task_seed_categories',
            status: TaskStatus::Skipped,
            executedAt: $executedAt,
        );

        self::assertSame(TaskStatus::Skipped, $execution->status);
        self::assertNull($execution->error);
    }
}
