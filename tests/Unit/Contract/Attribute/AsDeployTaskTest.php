<?php

declare(strict_types=1);

namespace Soviann\DeployTasks\Tests\Unit\Contract\Attribute;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Soviann\DeployTasks\Contract\Attribute\AsDeployTask;
use Soviann\DeployTasks\Contract\DeployTaskInterface;
use Soviann\DeployTasks\Contract\TaskResult;
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
        self::assertFalse($attribute->transactional);
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
}

#[AsDeployTask(id: 'test.attributed', priority: 5, description: 'Test task')]
final class AttributedTestTask implements DeployTaskInterface
{
    public function getId(): string
    {
        return 'test.attributed';
    }

    public function getDescription(): string
    {
        return 'Test task';
    }

    public function run(OutputInterface $output): int
    {
        return TaskResult::SUCCESS;
    }
}

final class UnattributedTestTask implements DeployTaskInterface
{
    public function getId(): string
    {
        return 'test.unattributed';
    }

    public function getDescription(): string
    {
        return 'Unattributed test task';
    }

    public function run(OutputInterface $output): int
    {
        return TaskResult::SUCCESS;
    }
}
