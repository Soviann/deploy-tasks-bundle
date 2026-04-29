<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Tests\Unit\Attribute;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Soviann\DeployTasksBundle\Attribute\AsDeployTask;

/**
 * Guards the groups validation rules added in phase 12.
 *
 * - Empty array is rejected (use null for the default group).
 * - Non-string entries inside the array are rejected before the regex check.
 */
#[CoversClass(AsDeployTask::class)]
final class AsDeployTaskGroupsValidationTest extends TestCase
{
    public function testConstructorRejectsEmptyGroupsArray(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/groups cannot be an empty array/');

        new AsDeployTask(id: 'task.empty-groups', groups: []);
    }

    public function testConstructorRejectsIntegerEntryInGroupsArray(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/int/');

        // @phpstan-ignore argument.type (intentional wrong type for validation test)
        new AsDeployTask(id: 'task.bad-entry', groups: ['ok', 42]);
    }

    public function testConstructorAcceptsNullGroups(): void
    {
        $attribute = new AsDeployTask(id: 'task.no-groups', groups: null);

        self::assertNull($attribute->groups);
    }

    public function testConstructorAcceptsStringGroups(): void
    {
        $attribute = new AsDeployTask(id: 'task.one-group', groups: 'predeploy');

        self::assertSame('predeploy', $attribute->groups);
    }

    public function testConstructorAcceptsStringArrayGroups(): void
    {
        $attribute = new AsDeployTask(id: 'task.multi-group', groups: ['predeploy', 'postdeploy']);

        self::assertSame(['predeploy', 'postdeploy'], $attribute->groups);
    }
}
