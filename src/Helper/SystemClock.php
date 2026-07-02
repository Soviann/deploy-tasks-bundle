<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Helper;

use Psr\Clock\ClockInterface;

/**
 * PSR-20 system clock, used as the promoted default wherever the bundle needs
 * the current time. Inject a fixed clock (e.g. symfony/clock's MockClock) via
 * the constructor for deterministic time in tests.
 *
 * @internal
 */
final class SystemClock implements ClockInterface
{
    public function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable();
    }
}
