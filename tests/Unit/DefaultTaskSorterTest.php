<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Soviann\DeployTasksBundle\Identifier\TaskIdResolver;
use Soviann\DeployTasksBundle\Sorting\DefaultTaskSorter;
use Soviann\DeployTasksBundle\Tests\Fixtures\PrioritizedTask;
use Soviann\DeployTasksBundle\Tests\Fixtures\SimpleTask;

#[CoversClass(DefaultTaskSorter::class)]
final class DefaultTaskSorterTest extends TestCase
{
    private DefaultTaskSorter $sorter;

    protected function setUp(): void
    {
        $this->sorter = new DefaultTaskSorter(new TaskIdResolver());
    }

    public function testSortsByPriorityDescending(): void
    {
        $lowPriority = new SimpleTask('task.low');
        $highPriority = new PrioritizedTask();

        $tasks = $this->sorter->sort([$lowPriority, $highPriority]);

        self::assertSame($highPriority, $tasks[0]);
        self::assertSame($lowPriority, $tasks[1]);
    }

    public function testSortsByDateAscending(): void
    {
        $newer = new SimpleTask('task_20260415_foo');
        $older = new SimpleTask('task_20260410_bar');

        $tasks = $this->sorter->sort([$newer, $older]);

        self::assertSame($older, $tasks[0]);
        self::assertSame($newer, $tasks[1]);
    }

    public function testTasksWithoutDateSortAfterDated(): void
    {
        $noDate = new SimpleTask('no_date_task');
        $dated = new SimpleTask('task_20260410');

        $tasks = $this->sorter->sort([$noDate, $dated]);

        self::assertSame($dated, $tasks[0]);
        self::assertSame($noDate, $tasks[1]);
    }

    public function testOriginalOrderPreserved(): void
    {
        $first = new SimpleTask('task.alpha');
        $second = new SimpleTask('task.beta');

        $tasks = $this->sorter->sort([$first, $second]);

        self::assertSame($first, $tasks[0]);
        self::assertSame($second, $tasks[1]);
    }

    public function testEmptyCollection(): void
    {
        self::assertSame([], $this->sorter->sort([]));
    }

    public function testMixedScenario(): void
    {
        // Priority 10, no date → runs first
        $highPriority = new PrioritizedTask();
        // Priority 0, older date → second
        $olderDated = new SimpleTask('task_20260401_seed');
        // Priority 0, newer date → third
        $newerDated = new SimpleTask('task_20260415_patch');
        // Priority 0, no date → last
        $noDate = new SimpleTask('task.no_date');

        $tasks = $this->sorter->sort([$noDate, $newerDated, $olderDated, $highPriority]);

        self::assertSame($highPriority, $tasks[0]);
        self::assertSame($olderDated, $tasks[1]);
        self::assertSame($newerDated, $tasks[2]);
        self::assertSame($noDate, $tasks[3]);
    }
}
