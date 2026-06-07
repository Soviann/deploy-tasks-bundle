<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Tests\Unit\Contract;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Soviann\DeployTasksBundle\TaskResult;

#[CoversClass(TaskResult::class)]
final class TaskResultTest extends TestCase
{
    /**
     * @return iterable<string, array{int, TaskResult}>
     */
    public static function provideBackingValues(): iterable
    {
        yield 'SUCCESS' => [0, TaskResult::SUCCESS];
        yield 'FAILURE' => [1, TaskResult::FAILURE];
        yield 'SKIPPED' => [2, TaskResult::SKIPPED];
        yield 'LOCKED' => [3, TaskResult::LOCKED];
    }

    #[DataProvider('provideBackingValues')]
    public function testBackingValueDocumentsTheContract(int $value, TaskResult $expected): void
    {
        self::assertSame($expected, TaskResult::from($value));
    }
}
