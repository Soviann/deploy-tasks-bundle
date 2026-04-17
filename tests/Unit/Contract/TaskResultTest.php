<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Tests\Unit\Contract;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Soviann\DeployTasksBundle\TaskResult;

#[CoversClass(TaskResult::class)]
final class TaskResultTest extends TestCase
{
    public function testBackingValuesDocumentTheContract(): void
    {
        $map = [];
        foreach (TaskResult::cases() as $case) {
            $map[$case->name] = $case->value;
        }

        self::assertSame(
            ['SUCCESS' => 0, 'FAILURE' => 1, 'SKIPPED' => 2, 'LOCKED' => 3],
            $map,
        );
    }
}
