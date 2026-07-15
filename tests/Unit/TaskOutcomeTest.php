<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
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
            executedAt: new \DateTimeImmutable(),
        );

        self::assertSame(0.0, $outcome->durationSeconds);
    }

    public function testExplicitDurationSecondsIsPreserved(): void
    {
        $outcome = new TaskOutcome(
            result: TaskResult::SUCCESS,
            executedAt: new \DateTimeImmutable(),
            durationSeconds: 1.5,
        );

        self::assertSame(1.5, $outcome->durationSeconds);
    }

    public function testNullErrorByDefault(): void
    {
        $outcome = new TaskOutcome(
            result: TaskResult::FAILURE,
            executedAt: new \DateTimeImmutable(),
        );

        self::assertNull($outcome->error);
    }

    /**
     * The outcome no longer stores a status — it derives it from the result via
     * TaskResult::toStatus(), the single owner of the mapping.
     */
    #[DataProvider('provideStatusDerivation')]
    public function testStatusDerivesFromResult(TaskResult $result, TaskStatus $expected): void
    {
        $outcome = new TaskOutcome(
            result: $result,
            executedAt: new \DateTimeImmutable(),
        );

        self::assertSame($expected, $outcome->status());
    }

    /**
     * @return iterable<string, array{TaskResult, TaskStatus}>
     */
    public static function provideStatusDerivation(): iterable
    {
        yield 'success persists as ran' => [TaskResult::SUCCESS, TaskStatus::Ran];
        yield 'skipped persists as skipped' => [TaskResult::SKIPPED, TaskStatus::Skipped];
        yield 'failure persists as failed' => [TaskResult::FAILURE, TaskStatus::Failed];
    }
}
