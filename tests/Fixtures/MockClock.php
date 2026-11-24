<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Tests\Fixtures;

use Psr\Clock\ClockInterface;

/**
 * Frozen PSR-20 clock whose time only moves through sleep().
 *
 * Stands in for symfony/clock's MockClock, which has no Symfony 5.4 release —
 * the suite must run on every supported Symfony line.
 */
final class MockClock implements ClockInterface
{
    private \DateTimeImmutable $now;

    public function __construct(\DateTimeImmutable|string $now = 'now')
    {
        $this->now = \is_string($now) ? new \DateTimeImmutable($now) : $now;
    }

    public function now(): \DateTimeImmutable
    {
        return $this->now;
    }

    public function sleep(float|int $seconds): void
    {
        $microseconds = (int) \round($seconds * 1_000_000);

        $this->now = $this->now->modify(\sprintf('+%d microseconds', $microseconds));
    }
}
