<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Tests\Unit\Exception;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Soviann\DeployTasksBundle\Exception\TaskNotFoundException;

#[CoversClass(TaskNotFoundException::class)]
final class TaskNotFoundExceptionTest extends TestCase
{
    public function testExtendsRuntimeException(): void
    {
        // RuntimeException like the other CLI-targeting exceptions (group/env mismatch):
        // they all mean "the operator targeted a task the current setup cannot serve".
        $parents = \class_parents(TaskNotFoundException::class);
        self::assertNotFalse($parents);
        self::assertContains(\RuntimeException::class, $parents);
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
