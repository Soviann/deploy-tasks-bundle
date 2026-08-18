<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Tests\Unit\Storage;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Soviann\DeployTasksBundle\Storage\TaskExecution;
use Soviann\DeployTasksBundle\Storage\TaskStatus;

#[CoversClass(TaskExecution::class)]
final class TaskExecutionTest extends TestCase
{
    public function testConstructAndProperties(): void
    {
        $now = new \DateTimeImmutable('2026-08-17 10:00:00');
        $execution = new TaskExecution(
            id: 'task_demo',
            status: TaskStatus::Ran,
            executedAt: $now,
            error: null,
            group: 'predeploy',
            durationMs: 1250,
        );

        self::assertSame('task_demo', $execution->id);
        self::assertSame(TaskStatus::Ran, $execution->status);
        self::assertSame($now, $execution->executedAt);
        self::assertNull($execution->error);
        self::assertSame('predeploy', $execution->group);
        self::assertSame(1250, $execution->durationMs);
    }

    public function testSlotKeyWithGroup(): void
    {
        $slotKey = TaskExecution::slotKey('task_a', 'group_b');

        self::assertSame("task_a\0group_b", $slotKey);
        self::assertNotSame(TaskExecution::slotKey('group_b', 'task_a'), $slotKey);
    }

    public function testSlotKeyWithNullGroup(): void
    {
        $slotKey = TaskExecution::slotKey('task_a', null);

        self::assertSame("task_a\0", $slotKey);
    }

    public function testSlotKeyWithEmptyStringGroup(): void
    {
        $slotKey = TaskExecution::slotKey('task_a', '');

        self::assertSame("task_a\0", $slotKey);
    }

    public function testSlotKeyDistinctness(): void
    {
        // "task" in group "1" vs "task1" with no group
        self::assertNotSame(
            TaskExecution::slotKey('task', '1'),
            TaskExecution::slotKey('task1', null),
        );
    }
}
