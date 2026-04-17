<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Tests\Unit\Contract;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Soviann\DeployTasksBundle\Storage\TaskStatus;

#[CoversClass(TaskStatus::class)]
final class TaskStatusTest extends TestCase
{
    public function testBackingValuesDocumentTheContract(): void
    {
        $map = [];
        foreach (TaskStatus::cases() as $case) {
            $map[$case->name] = $case->value;
        }

        self::assertSame(
            ['Ran' => 'ran', 'Failed' => 'failed', 'Skipped' => 'skipped'],
            $map,
        );
    }

    public function testFromResolvesEveryCase(): void
    {
        foreach (TaskStatus::cases() as $case) {
            self::assertSame($case, TaskStatus::from($case->value));
        }
    }
}
