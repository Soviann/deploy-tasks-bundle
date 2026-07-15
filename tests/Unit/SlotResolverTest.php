<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Soviann\DeployTasksBundle\Exception\TaskGroupMismatchException;
use Soviann\DeployTasksBundle\Runner\SlotResolver;
use Soviann\DeployTasksBundle\Tests\Fixtures\MultiGroupTask;
use Soviann\DeployTasksBundle\Tests\Fixtures\SimpleTask;

#[CoversClass(SlotResolver::class)]
final class SlotResolverTest extends TestCase
{
    public function testEmptyRequestOnUngroupedTaskTargetsDefaultSlot(): void
    {
        self::assertSame([null], SlotResolver::resolve('task.default', new SimpleTask('task.default'), []));
    }

    public function testEmptyRequestOnGroupedTaskTargetsEveryDeclaredSlot(): void
    {
        // Phase 3 rule: absent --group, single-task targeting operates on ALL
        // slots — every declared group — mirroring the bulk-run expansion.
        self::assertSame(
            ['predeploy', 'postdeploy'],
            SlotResolver::resolve('test.multi_group', new MultiGroupTask(), []),
        );
    }

    public function testRequestNarrowsToTheRequestedSlot(): void
    {
        self::assertSame(
            ['postdeploy'],
            SlotResolver::resolve('test.multi_group', new MultiGroupTask(), ['postdeploy']),
        );
    }

    public function testResolvedSlotsFollowDeclaredOrder(): void
    {
        // Canonical (declared) order keeps the resolver consistent with the
        // bulk-run expansion regardless of the CLI --group order.
        self::assertSame(
            ['predeploy', 'postdeploy'],
            SlotResolver::resolve('test.multi_group', new MultiGroupTask(), ['postdeploy', 'predeploy']),
        );
    }

    public function testUndeclaredRequestedGroupThrows(): void
    {
        $this->expectException(TaskGroupMismatchException::class);

        SlotResolver::resolve('test.multi_group', new MultiGroupTask(), ['predeploy', 'nonexistent']);
    }

    public function testAnyRequestedGroupOnUngroupedTaskThrows(): void
    {
        $this->expectException(TaskGroupMismatchException::class);

        SlotResolver::resolve('task.default', new SimpleTask('task.default'), ['predeploy']);
    }

    public function testExpandExcludesUngroupedTaskFromGroupFilteredRun(): void
    {
        // Bulk runs filter instead of erroring: an ungrouped task simply does
        // not participate when a group filter is active.
        self::assertSame([], SlotResolver::expand(null, ['predeploy']));
    }
}
