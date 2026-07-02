<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Soviann\DeployTasksBundle\Exception\StorageException;
use Soviann\DeployTasksBundle\Storage\TaskStatus;
use Soviann\DeployTasksBundle\TaskResult;

#[CoversClass(TaskStatus::class)]
#[CoversClass(TaskResult::class)]
final class TaskStatusTest extends TestCase
{
    public function testOnlyFailedSlotsRerun(): void
    {
        // Single owner of the retry rule: the runner's pending predicate and any
        // "what runs next" UI both derive from this.
        self::assertTrue(TaskStatus::Failed->willRerun());
        self::assertFalse(TaskStatus::Ran->willRerun());
        self::assertFalse(TaskStatus::Skipped->willRerun());
    }

    public function testFromStoredParsesValidValues(): void
    {
        self::assertSame(TaskStatus::Ran, TaskStatus::fromStored('ran', 'task.a', null));
        self::assertSame(TaskStatus::Failed, TaskStatus::fromStored('failed', 'task.a', 'grp'));
        self::assertSame(TaskStatus::Skipped, TaskStatus::fromStored('skipped', 'task.a', null));
    }

    public function testFromStoredMapsUnknownValueToCorruptedRow(): void
    {
        $this->expectException(StorageException::class);
        $this->expectExceptionMessageMatches('/Corrupted storage row for task "task.a" \(group "grp"\).*"bogus"/');

        TaskStatus::fromStored('bogus', 'task.a', 'grp');
    }

    public function testTaskResultToStatusMapping(): void
    {
        self::assertSame(TaskStatus::Ran, TaskResult::SUCCESS->toStatus());
        self::assertSame(TaskStatus::Skipped, TaskResult::SKIPPED->toStatus());
        self::assertSame(TaskStatus::Failed, TaskResult::FAILURE->toStatus());
        // LOCKED is runner-reserved: a task returning it is recorded as a failure.
        self::assertSame(TaskStatus::Failed, TaskResult::LOCKED->toStatus());
    }
}
