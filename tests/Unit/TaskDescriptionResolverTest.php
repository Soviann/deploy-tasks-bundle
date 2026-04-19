<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Soviann\DeployTasksBundle\Attribute\AsDeployTask;
use Soviann\DeployTasksBundle\DeployTaskInterface;
use Soviann\DeployTasksBundle\Identifier\TaskDescriptionResolver;
use Soviann\DeployTasksBundle\TaskResult;
use Symfony\Component\Console\Output\OutputInterface;

#[CoversClass(TaskDescriptionResolver::class)]
final class TaskDescriptionResolverTest extends TestCase
{
    private TaskDescriptionResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new TaskDescriptionResolver();
    }

    public function testMethodWinsWhenNonEmpty(): void
    {
        $task = new MethodDescriptionTask();

        self::assertSame('from method', $this->resolver->resolve($task));
    }

    public function testFallsBackToAttributeWhenMethodEmpty(): void
    {
        $task = new AttributeDescriptionFallbackTask();

        self::assertSame('from attribute', $this->resolver->resolve($task));
    }

    public function testReturnsEmptyWhenNoAttribute(): void
    {
        $task = new EmptyDescriptionNoAttributeTask();

        self::assertSame('', $this->resolver->resolve($task));
    }

    public function testReturnsEmptyWhenAttributeDescriptionIsNull(): void
    {
        $task = new EmptyDescriptionNullAttributeTask();

        self::assertSame('', $this->resolver->resolve($task));
    }
}

#[AsDeployTask(description: 'from attribute')]
final class MethodDescriptionTask implements DeployTaskInterface
{
    public function getDescription(): string
    {
        return 'from method';
    }

    public function run(OutputInterface $output): TaskResult
    {
        return TaskResult::SUCCESS;
    }
}

#[AsDeployTask(description: 'from attribute')]
final class AttributeDescriptionFallbackTask implements DeployTaskInterface
{
    public function getDescription(): string
    {
        return '';
    }

    public function run(OutputInterface $output): TaskResult
    {
        return TaskResult::SUCCESS;
    }
}

final class EmptyDescriptionNoAttributeTask implements DeployTaskInterface
{
    public function getDescription(): string
    {
        return '';
    }

    public function run(OutputInterface $output): TaskResult
    {
        return TaskResult::SUCCESS;
    }
}

#[AsDeployTask(id: 'empty_null_attr')]
final class EmptyDescriptionNullAttributeTask implements DeployTaskInterface
{
    public function getDescription(): string
    {
        return '';
    }

    public function run(OutputInterface $output): TaskResult
    {
        return TaskResult::SUCCESS;
    }
}
