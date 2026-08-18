<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Tests\Unit\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Soviann\DeployTasksBundle\Command\CommandMessages;
use Soviann\DeployTasksBundle\Storage\TaskStatus;

#[CoversClass(CommandMessages::class)]
final class CommandMessagesTest extends TestCase
{
    #[DataProvider('statusProvider')]
    public function testStatusTag(TaskStatus $status, string $expected): void
    {
        self::assertSame($expected, CommandMessages::statusTag($status));
    }

    /**
     * @return iterable<string, array{TaskStatus, string}>
     */
    public static function statusProvider(): iterable
    {
        yield 'ran' => [TaskStatus::Ran, '<info>ran</info>'];
        yield 'failed' => [TaskStatus::Failed, '<error>failed</error>'];
        yield 'skipped' => [TaskStatus::Skipped, '<comment>skipped</comment>'];
    }

    #[DataProvider('durationProvider')]
    public function testFormatDuration(int $durationMs, string $expected): void
    {
        self::assertSame($expected, CommandMessages::formatDuration($durationMs));
    }

    /**
     * @return iterable<string, array{int, string}>
     */
    public static function durationProvider(): iterable
    {
        yield 'zero' => [0, '0ms'];
        yield 'small' => [42, '42ms'];
        yield 'sub-second boundary' => [999, '999ms'];
        yield 'exact one second' => [1000, '1.0s'];
        yield 'one second and one ms' => [1001, '1.0s'];
        yield 'one and half second' => [1500, '1.5s'];
        yield 'two seconds' => [2000, '2.0s'];
        yield 'fraction rounding' => [2560, '2.6s'];
    }
}
