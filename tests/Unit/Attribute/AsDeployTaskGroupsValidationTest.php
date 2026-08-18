<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Tests\Unit\Attribute;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Soviann\DeployTasksBundle\Attribute\AsDeployTask;
use Soviann\DeployTasksBundle\DeployTaskInterface;
use Soviann\DeployTasksBundle\TaskResult;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Guards the groups and attribute helper methods of AsDeployTask.
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

    public function testGroupsOfWithAssociativeArray(): void
    {
        $task = new #[AsDeployTask(id: 'task.assoc-groups', groups: ['first' => 'predeploy', 'second' => 'postdeploy'])] class implements DeployTaskInterface {
            public function getDescription(): string
            {
                return '';
            }

            public function run(OutputInterface $output): TaskResult
            {
                return TaskResult::SUCCESS;
            }
        };

        $groups = AsDeployTask::groupsOf($task);
        self::assertNotNull($groups);
        self::assertSame(['predeploy', 'postdeploy'], $groups);
    }

    public function testEnvsOfWithAssociativeArray(): void
    {
        $task = new #[AsDeployTask(id: 'task.assoc-envs', env: ['k1' => 'prod', 'k2' => 'staging'])] class implements DeployTaskInterface {
            public function getDescription(): string
            {
                return '';
            }

            public function run(OutputInterface $output): TaskResult
            {
                return TaskResult::SUCCESS;
            }
        };

        $envs = AsDeployTask::envsOf($task);
        self::assertNotNull($envs);
        self::assertSame(['prod', 'staging'], $envs);
    }

    public function testIdOfAndTimeoutOf(): void
    {
        $task = new #[AsDeployTask(id: 'task.custom-id', timeout: 120)] class implements DeployTaskInterface {
            public function getDescription(): string
            {
                return '';
            }

            public function run(OutputInterface $output): TaskResult
            {
                return TaskResult::SUCCESS;
            }
        };

        self::assertSame('task.custom-id', AsDeployTask::idOf($task));
        self::assertSame(120, AsDeployTask::timeoutOf($task));
    }

    public function testHelpersWithNoAttribute(): void
    {
        $task = new class implements DeployTaskInterface {
            public function getDescription(): string
            {
                return '';
            }

            public function run(OutputInterface $output): TaskResult
            {
                return TaskResult::SUCCESS;
            }
        };

        self::assertNull(AsDeployTask::groupsOf($task));
        self::assertNull(AsDeployTask::envsOf($task));
        self::assertSame('', AsDeployTask::idOf($task));
        self::assertNull(AsDeployTask::timeoutOf($task));
    }
}
