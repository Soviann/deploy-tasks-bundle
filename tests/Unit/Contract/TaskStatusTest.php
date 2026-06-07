<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Tests\Unit\Contract;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Soviann\DeployTasksBundle\Storage\TaskStatus;

#[CoversClass(TaskStatus::class)]
final class TaskStatusTest extends TestCase
{
    /**
     * @return iterable<string, array{string, TaskStatus}>
     */
    public static function provideBackingValues(): iterable
    {
        yield 'ran' => ['ran', TaskStatus::Ran];
        yield 'failed' => ['failed', TaskStatus::Failed];
        yield 'skipped' => ['skipped', TaskStatus::Skipped];
    }

    #[DataProvider('provideBackingValues')]
    public function testBackingValueDocumentsTheContract(string $value, TaskStatus $expected): void
    {
        self::assertSame($expected, TaskStatus::from($value));
    }

    public function testFromResolvesEveryCase(): void
    {
        foreach (TaskStatus::cases() as $case) {
            self::assertSame($case, TaskStatus::from($case->value));
        }
    }
}
