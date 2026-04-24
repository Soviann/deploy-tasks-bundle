<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Tests\Unit\Exception;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Soviann\DeployTasksBundle\Exception\TaskGroupMismatchException;

#[CoversClass(TaskGroupMismatchException::class)]
final class TaskGroupMismatchExceptionTest extends TestCase
{
    public function testExtendsRuntimeException(): void
    {
        $parents = \class_parents(TaskGroupMismatchException::class);
        self::assertNotFalse($parents);
        self::assertContains(\RuntimeException::class, $parents);
    }

    public function testCreateWithDeclaredGroupsReportsRequestedAndDeclared(): void
    {
        $exception = TaskGroupMismatchException::create(
            'task.seed_categories',
            ['staging', 'prod'],
            ['default', 'prod'],
        );

        self::assertSame(
            'Groups [staging, prod] are not declared on task "task.seed_categories" (declared: default, prod).',
            $exception->getMessage(),
        );
    }

    public function testCreateWithEmptyDeclaredReportsNoGroupsDeclared(): void
    {
        $exception = TaskGroupMismatchException::create(
            'task.seed_categories',
            ['staging'],
            [],
        );

        self::assertSame(
            'Task "task.seed_categories" has no groups declared; cannot target --group=[staging].',
            $exception->getMessage(),
        );
    }
}
