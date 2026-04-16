<?php

declare(strict_types=1);

namespace Soviann\DeployTasks\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Soviann\DeployTasks\DefaultTaskOrderResolver;
use Soviann\DeployTasks\TaskIdResolver;
use Soviann\DeployTasks\Tests\Fixtures\PrioritizedTask;
use Soviann\DeployTasks\Tests\Fixtures\SimpleTask;

#[CoversClass(DefaultTaskOrderResolver::class)]
final class DefaultTaskOrderResolverTest extends TestCase
{
    private DefaultTaskOrderResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new DefaultTaskOrderResolver(new TaskIdResolver());
    }

    public function testSortsByPriorityDescending(): void
    {
        $lowPriority = new SimpleTask('task.low');
        $highPriority = new PrioritizedTask();

        $result = $this->resolver->resolve([$lowPriority, $highPriority]);
        $tasks = $result->toArray();

        self::assertSame($highPriority, $tasks[0]);
        self::assertSame($lowPriority, $tasks[1]);
    }

    public function testSortsByDateAscending(): void
    {
        $newer = new SimpleTask('task_2026_04_15_foo');
        $older = new SimpleTask('task_2026_04_10_bar');

        $result = $this->resolver->resolve([$newer, $older]);
        $tasks = $result->toArray();

        self::assertSame($older, $tasks[0]);
        self::assertSame($newer, $tasks[1]);
    }

    public function testTasksWithoutDateSortAfterDated(): void
    {
        $noDate = new SimpleTask('no_date_task');
        $dated = new SimpleTask('task_2026_04_10');

        $result = $this->resolver->resolve([$noDate, $dated]);
        $tasks = $result->toArray();

        self::assertSame($dated, $tasks[0]);
        self::assertSame($noDate, $tasks[1]);
    }

    public function testOriginalOrderPreserved(): void
    {
        $first = new SimpleTask('task.alpha');
        $second = new SimpleTask('task.beta');

        $result = $this->resolver->resolve([$first, $second]);
        $tasks = $result->toArray();

        self::assertSame($first, $tasks[0]);
        self::assertSame($second, $tasks[1]);
    }

    public function testEmptyCollection(): void
    {
        $result = $this->resolver->resolve([]);

        self::assertTrue($result->isEmpty());
        self::assertCount(0, $result);
    }

    public function testMixedScenario(): void
    {
        // Priority 10, no date → runs first
        $highPriority = new PrioritizedTask();
        // Priority 0, older date → second
        $olderDated = new SimpleTask('task_2026_04_01_seed');
        // Priority 0, newer date → third
        $newerDated = new SimpleTask('task_2026_04_15_patch');
        // Priority 0, no date → last
        $noDate = new SimpleTask('task.no_date');

        $result = $this->resolver->resolve([$noDate, $newerDated, $olderDated, $highPriority]);
        $tasks = $result->toArray();

        self::assertSame($highPriority, $tasks[0]);
        self::assertSame($olderDated, $tasks[1]);
        self::assertSame($newerDated, $tasks[2]);
        self::assertSame($noDate, $tasks[3]);
    }
}
