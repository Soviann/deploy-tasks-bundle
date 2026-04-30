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
        $parents = \class_parents(DuplicateTaskIdException::class);
        self::assertNotFalse($parents);
        self::assertContains(\LogicException::class, $parents);
    }

    public function testCreateReturnsInstanceWithExpectedMessage(): void
    {
        $exception = DuplicateTaskIdException::create(
            'task.seed_categories',
            'App\Migrations\SeedCategoriesTask',
            'App\Tasks\SeedCategoriesTask',
        );

        self::assertStringContainsString('task.seed_categories', $exception->getMessage());
        self::assertStringContainsString('App\Migrations\SeedCategoriesTask', $exception->getMessage());
        self::assertStringContainsString('App\Tasks\SeedCategoriesTask', $exception->getMessage());
        self::assertStringContainsString('#[AsDeployTask(id: ...)]', $exception->getMessage());
    }

    public function testCreateNamesAllThreeComponents(): void
    {
        $exception = DuplicateTaskIdException::create(
            'seed',
            'App\Migrations\Seed',
            'App\Tasks\Seed',
        );

        self::assertStringContainsString('"seed"', $exception->getMessage());
        self::assertStringContainsString('"App\Migrations\Seed"', $exception->getMessage());
        self::assertStringContainsString('"App\Tasks\Seed"', $exception->getMessage());
    }
}
