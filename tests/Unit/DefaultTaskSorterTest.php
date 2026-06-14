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

    public function testUnattributedTaskHasSamePriorityAsExplicitZero(): void
    {
        // A task without #[AsDeployTask] must default to priority 0, equal to an attributed
        // task with explicit priority: 0. Original registration order must be the tiebreaker.
        // Mutant DecrementInteger (-1): unattributed gets -1, so it sorts AFTER the attributed
        //   task regardless of registration order — test fails.
        // Mutant IncrementInteger (1): unattributed gets 1, so it sorts BEFORE the attributed
        //   task even if it was registered second — need a second test covering that direction.
        $unattributed = new SimpleTask('task.no_attr');   // no #[AsDeployTask] → priority defaults to 0
        $attributed = new \Soviann\DeployTasksBundle\Tests\Fixtures\AttributeOnlyTask(); // priority: 0 explicitly

        // registered: [unattributed, attributed] → same priority → order preserved
        $sorted = $this->sorter->sort([$unattributed, $attributed]);

        self::assertSame(
            $unattributed,
            $sorted[0],
            'Unattributed task registered first must stay first when priorities are equal.',
        );
        self::assertSame($attributed, $sorted[1]);
    }

    public function testUnattributedTaskDoesNotGainHigherPriorityThanExplicitZero(): void
    {
        // Mutant IncrementInteger (1): unattributed task gets priority 1, outranking any
        // attributed task with explicit priority: 0 and sorting before it even if registered after.
        $attributed = new \Soviann\DeployTasksBundle\Tests\Fixtures\AttributeOnlyTask(); // priority: 0
        $unattributed = new SimpleTask('task.no_attr');   // registered second

        // registered: [attributed (p=0), unattributed (p=0)] → order preserved
        $sorted = $this->sorter->sort([$attributed, $unattributed]);

        self::assertSame(
            $attributed,
            $sorted[0],
            'Attributed task registered first must stay first when priorities are equal.',
        );
        self::assertSame($unattributed, $sorted[1]);
    }

    public function testMultipleDatelessTasksPreserveRegistrationOrder(): void
    {
        // Two no-date tasks: if compareDates(null, null) returns anything other than 0,
        // their relative order will flip for some comparisons in usort (Mutant 230: returns -1).
        $alpha = new SimpleTask('task.alpha_no_date');
        $beta = new SimpleTask('task.beta_no_date');
        $gamma = new SimpleTask('task.gamma_no_date');

        $sorted = $this->sorter->sort([$alpha, $beta, $gamma]);

        // All same priority (0), no date — registration order must be preserved.
        self::assertSame($alpha, $sorted[0]);
        self::assertSame($beta, $sorted[1]);
        self::assertSame($gamma, $sorted[2]);
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
