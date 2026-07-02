<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Tests\Unit\Helper;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Soviann\DeployTasksBundle\Helper\SystemClock;

#[CoversClass(SystemClock::class)]
final class SystemClockTest extends TestCase
{
    public function testNowReturnsCurrentSystemTime(): void
    {
        // Bracket the call with time() instead of asserting exact wall-clock
        // equality — the clock is only required to track the system time.
        $before = \time();
        $now = (new SystemClock())->now();
        $after = \time();

        self::assertGreaterThanOrEqual($before, $now->getTimestamp());
        self::assertLessThanOrEqual($after + 2, $now->getTimestamp());
    }

    public function testConsecutiveCallsReturnFreshInstances(): void
    {
        $clock = new SystemClock();

        self::assertNotSame($clock->now(), $clock->now());
    }
}
