<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Tests\Unit\Contract;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Soviann\DeployTasksBundle\TaskResult;

#[CoversClass(TaskResult::class)]
final class TaskResultTest extends TestCase
{
    public function testCasesAreExactlyTheAuthorReturnableOutcomes(): void
    {
        // The enum is what a task author returns from run(): only outcomes a task
        // can legitimately report belong here. Lock contention is the runner's
        // business and is represented as null from runOne(), not as a case.
        // @phpstan-ignore staticMethod.alreadyNarrowedType (contract pin: fails when a case is added, removed, or reordered)
        self::assertSame(
            [TaskResult::SUCCESS, TaskResult::FAILURE, TaskResult::SKIPPED],
            TaskResult::cases(),
        );
    }

    public function testEnumIsUnbacked(): void
    {
        // The former int backing claimed to map to CLI exit codes, but no code path
        // ever used it as one (the run command exits via Command::* and EX_TEMPFAIL)
        // and nothing persists or rehydrates it — keep it a pure value set.
        // @phpstan-ignore method.impossibleType (contract pin: fails if the enum grows a backing again)
        self::assertFalse((new \ReflectionEnum(TaskResult::class))->isBacked());
    }
}
