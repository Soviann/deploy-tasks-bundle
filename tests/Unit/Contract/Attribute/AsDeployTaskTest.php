<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Tests\Unit\Contract\Attribute;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Soviann\DeployTasksBundle\Attribute\AsDeployTask;
use Soviann\DeployTasksBundle\DeployTaskInterface;
use Soviann\DeployTasksBundle\TaskResult;
use Symfony\Component\Console\Output\OutputInterface;

#[CoversClass(AsDeployTask::class)]
final class AsDeployTaskTest extends TestCase
{
    public function testDefaultValues(): void
    {
        $attribute = new AsDeployTask(id: 'task.seed');

        self::assertSame('task.seed', $attribute->id);
        self::assertSame(0, $attribute->priority);
        self::assertNull($attribute->env);
        self::assertNull($attribute->timeout);
        self::assertNull($attribute->transactional);
        self::assertNull($attribute->description);
    }

    public function testCustomValues(): void
    {
        $attribute = new AsDeployTask(
            id: 'task.custom',
            priority: 10,
            env: ['prod', 'staging'],
            timeout: 60,
            transactional: true,
            description: 'Seeds the category table',
        );

        self::assertSame('task.custom', $attribute->id);
        self::assertSame(10, $attribute->priority);
        self::assertSame(['prod', 'staging'], $attribute->env);
        self::assertSame(60, $attribute->timeout);
        self::assertTrue($attribute->transactional);
        self::assertSame('Seeds the category table', $attribute->description);
    }

    public function testEnvAsString(): void
    {
        $attribute = new AsDeployTask(id: 'task.prod_only', env: 'prod');

        self::assertSame('prod', $attribute->env);
    }

    public function testEnvAsArray(): void
    {
        $attribute = new AsDeployTask(id: 'task.multi_env', env: ['dev', 'test']);

        self::assertSame(['dev', 'test'], $attribute->env);
    }

    public function testOfReturnsAttribute(): void
    {
        $task = new AttributedTestTask();
        $attribute = AsDeployTask::of($task);

        self::assertNotNull($attribute);
        self::assertInstanceOf(AsDeployTask::class, $attribute);
        self::assertSame('test.attributed', $attribute->id);
        self::assertSame(5, $attribute->priority);
        self::assertSame('Test task', $attribute->description);
    }

    public function testOfReturnsNullWhenNoAttribute(): void
    {
        $task = new UnattributedTestTask();
        $attribute = AsDeployTask::of($task);

        self::assertNull($attribute);
    }

    public function testGroupsDefaultsToNull(): void
    {
        $attribute = new AsDeployTask(id: 'task.default');

        self::assertNull($attribute->groups);
    }

    public function testGroupsAcceptsString(): void
    {
        $attribute = new AsDeployTask(id: 'task.pre', groups: 'predeploy');

        self::assertSame('predeploy', $attribute->groups);
    }

    public function testGroupsAcceptsArray(): void
    {
        $attribute = new AsDeployTask(id: 'task.both', groups: ['predeploy', 'postdeploy']);

        self::assertSame(['predeploy', 'postdeploy'], $attribute->groups);
    }

    public function testGroupsOfReturnsNullWhenAttributeMissing(): void
    {
        self::assertNull(AsDeployTask::groupsOf(new UnattributedTestTask()));
    }

    public function testGroupsOfReturnsNullWhenAttributeHasNoGroups(): void
    {
        self::assertNull(AsDeployTask::groupsOf(new AttributedTestTask()));
    }

    public function testGroupsOfNormalisesStringToList(): void
    {
        self::assertSame(['predeploy'], AsDeployTask::groupsOf(new SingleGroupTestTask()));
    }

    public function testGroupsOfReturnsList(): void
    {
        self::assertSame(['predeploy', 'postdeploy'], AsDeployTask::groupsOf(new MultiGroupTestTask()));
    }
}

#[AsDeployTask(id: 'test.single_group', groups: 'predeploy')]
final class SingleGroupTestTask implements DeployTaskInterface
{
    public function getDescription(): string
    {
        return 'Single group';
    }

    public function run(OutputInterface $output): TaskResult
    {
        return TaskResult::SUCCESS;
    }
}

#[AsDeployTask(id: 'test.multi_group', groups: ['predeploy', 'postdeploy'])]
final class MultiGroupTestTask implements DeployTaskInterface
{
    public function getDescription(): string
    {
        return 'Multi group';
    }

    public function run(OutputInterface $output): TaskResult
    {
        return TaskResult::SUCCESS;
    }
}

#[AsDeployTask(id: 'test.attributed', priority: 5, description: 'Test task')]
final class AttributedTestTask implements DeployTaskInterface
{
    public function getDescription(): string
    {
        return 'Test task';
    }

    public function run(OutputInterface $output): TaskResult
    {
        return TaskResult::SUCCESS;
    }
}

final class UnattributedTestTask implements DeployTaskInterface
{
    public function getDescription(): string
    {
        return 'Unattributed test task';
    }

    public function run(OutputInterface $output): TaskResult
    {
        return TaskResult::SUCCESS;
    }
}
