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
        $parents = \class_parents(TaskNotFoundException::class);
        self::assertNotFalse($parents);
        self::assertContains(\InvalidArgumentException::class, $parents);
    }

    public function testCreateReturnsInstanceWithExpectedMessage(): void
    {
        $exception = TaskNotFoundException::create('task.seed_categories');

        self::assertSame('Deploy task "task.seed_categories" not found.', $exception->getMessage());
    }

    public function testCreateIncludesTaskIdInMessage(): void
    {
        $taskId = 'task_20260413_my_task';
        $exception = TaskNotFoundException::create($taskId);

        self::assertStringContainsString($taskId, $exception->getMessage());
    }
}
