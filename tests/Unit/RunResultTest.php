<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Soviann\DeployTasksBundle\Runner\RunResult;

#[CoversClass(RunResult::class)]
final class RunResultTest extends TestCase
{
    public function testConstruction(): void
    {
        $result = new RunResult(
            ran: 5,
            skipped: 2,
            failed: 1,
            deferred: 3,
            locked: true,
            dryRun: true,
        );

        self::assertSame(5, $result->ran);
        self::assertSame(2, $result->skipped);
        self::assertSame(1, $result->failed);
        self::assertSame(3, $result->deferred);
        self::assertTrue($result->locked);
        self::assertTrue($result->dryRun);
    }

    public function testIsSuccessfulWhenNoFailuresAndNotLocked(): void
    {
        $result = new RunResult(ran: 3, skipped: 1, failed: 0);

        self::assertTrue($result->isSuccessful());
    }

    public function testIsSuccessfulWhenTasksOnlyDeferred(): void
    {
        // A self-skipped (deferred) task is not a failure: the run exits successfully
        // and the task simply retries on the next deploy.
        $result = new RunResult(ran: 0, skipped: 0, failed: 0, deferred: 2);

        self::assertTrue($result->isSuccessful());
    }

    public function testIsNotSuccessfulWhenFailed(): void
    {
        $result = new RunResult(ran: 2, skipped: 0, failed: 1);

        self::assertFalse($result->isSuccessful());
    }

    public function testIsNotSuccessfulWhenLocked(): void
    {
        $result = new RunResult(ran: 0, skipped: 0, failed: 0, locked: true);

        self::assertFalse($result->isSuccessful());
    }

    public function testDefaults(): void
    {
        $result = new RunResult(ran: 1, skipped: 0, failed: 0);

        self::assertSame(0, $result->deferred);
        self::assertFalse($result->locked);
        self::assertFalse($result->leaseLost);
        self::assertFalse($result->dryRun);
    }
}
