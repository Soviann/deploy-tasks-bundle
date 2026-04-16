<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Tests\Unit\Exception;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Soviann\DeployTasksBundle\Exception\TaskNotFoundException;

#[CoversClass(TaskNotFoundException::class)]
final class TaskNotFoundExceptionTest extends TestCase
{
    public function testExtendsInvalidArgumentException(): void
    {
        $exception = TaskNotFoundException::create('task.seed');

        self::assertInstanceOf(\InvalidArgumentException::class, $exception);
    }

    public function testCreateReturnsInstanceWithExpectedMessage(): void
    {
        $exception = TaskNotFoundException::create('task.seed_categories');

        self::assertInstanceOf(TaskNotFoundException::class, $exception);
        self::assertSame('Deploy task "task.seed_categories" not found.', $exception->getMessage());
    }

    public function testCreateIncludesTaskIdInMessage(): void
    {
        $taskId = 'task_20260413_my_task';
        $exception = TaskNotFoundException::create($taskId);

        self::assertStringContainsString($taskId, $exception->getMessage());
    }
}
