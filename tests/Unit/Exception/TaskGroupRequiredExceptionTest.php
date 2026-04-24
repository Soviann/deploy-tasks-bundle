<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Tests\Unit\Exception;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Soviann\DeployTasksBundle\Exception\TaskGroupRequiredException;

#[CoversClass(TaskGroupRequiredException::class)]
final class TaskGroupRequiredExceptionTest extends TestCase
{
    public function testExtendsRuntimeException(): void
    {
        $parents = \class_parents(TaskGroupRequiredException::class);
        self::assertNotFalse($parents);
        self::assertContains(\RuntimeException::class, $parents);
    }

    public function testCreateMentionsTaskIdAndDeclaredGroups(): void
    {
        $exception = TaskGroupRequiredException::create(
            'task.seed_categories',
            ['staging', 'prod'],
        );

        self::assertSame(
            'Task "task.seed_categories" belongs to groups [staging, prod]; specify --group=… to target a slot.',
            $exception->getMessage(),
        );
    }

    public function testCreateWithSingleGroupStillListsIt(): void
    {
        $exception = TaskGroupRequiredException::create(
            'task.migrate_users',
            ['prod'],
        );

        self::assertStringContainsString('task.migrate_users', $exception->getMessage());
        self::assertStringContainsString('[prod]', $exception->getMessage());
    }
}
