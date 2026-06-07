<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Soviann\DeployTasksBundle\Runner\TaskOutcome;
use Soviann\DeployTasksBundle\Storage\TaskStatus;
use Soviann\DeployTasksBundle\TaskResult;

#[CoversClass(TaskOutcome::class)]
final class TaskOutcomeTest extends TestCase
{
    public function testDefaultDurationSecondsIsZero(): void
    {
        // Mutant OneZeroFloat changes the default from 0.0 to 1.0.
        // Creating a TaskOutcome without specifying durationSeconds must yield exactly 0.0.
        $outcome = new TaskOutcome(
            result: TaskResult::SUCCESS,
            status: TaskStatus::Ran,
            executedAt: new \DateTimeImmutable(),
        );

        self::assertSame(0.0, $outcome->durationSeconds);
    }

    public function testExplicitDurationSecondsIsPreserved(): void
    {
        $outcome = new TaskOutcome(
            result: TaskResult::SUCCESS,
            status: TaskStatus::Ran,
            executedAt: new \DateTimeImmutable(),
            durationSeconds: 1.5,
        );

        self::assertSame(1.5, $outcome->durationSeconds);
    }

    public function testNullErrorByDefault(): void
    {
        $outcome = new TaskOutcome(
            result: TaskResult::FAILURE,
            status: TaskStatus::Failed,
            executedAt: new \DateTimeImmutable(),
        );

        self::assertNull($outcome->error);
    }
}
