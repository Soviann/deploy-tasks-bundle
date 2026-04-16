<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Tests\Unit\Exception;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Soviann\DeployTasksBundle\Exception\DuplicateTaskIdException;

#[CoversClass(DuplicateTaskIdException::class)]
final class DuplicateTaskIdExceptionTest extends TestCase
{
    public function testExtendsLogicException(): void
    {
        $exception = DuplicateTaskIdException::create('task.seed');

        self::assertInstanceOf(\LogicException::class, $exception);
    }

    public function testCreateReturnsInstanceWithExpectedMessage(): void
    {
        $exception = DuplicateTaskIdException::create('task.seed_categories');

        self::assertInstanceOf(DuplicateTaskIdException::class, $exception);
        self::assertSame('Deploy task ID "task.seed_categories" is already registered.', $exception->getMessage());
    }

    public function testCreateIncludesTaskIdInMessage(): void
    {
        $taskId = 'task_20260413_my_task';
        $exception = DuplicateTaskIdException::create($taskId);

        self::assertStringContainsString($taskId, $exception->getMessage());
    }
}
