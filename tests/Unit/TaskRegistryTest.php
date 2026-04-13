<?php

declare(strict_types=1);

namespace Soviann\DeployTasks\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Soviann\DeployTasks\DefaultTaskIdResolver;
use Soviann\DeployTasks\Exception\DuplicateTaskIdException;
use Soviann\DeployTasks\Exception\TaskNotFoundException;
use Soviann\DeployTasks\TaskRegistry;
use Soviann\DeployTasks\Tests\Fixtures\AttributeOnlyTask;
use Soviann\DeployTasks\Tests\Fixtures\MultiEnvTask;
use Soviann\DeployTasks\Tests\Fixtures\ProdOnlyTask;
use Soviann\DeployTasks\Tests\Fixtures\SimpleTask;

#[CoversClass(TaskRegistry::class)]
final class TaskRegistryTest extends TestCase
{
    private DefaultTaskIdResolver $idResolver;

    protected function setUp(): void
    {
        $this->idResolver = new DefaultTaskIdResolver();
    }

    public function testConstructWithTasks(): void
    {
        $task1 = new SimpleTask('task.one');
        $task2 = new SimpleTask('task.two');

        $registry = new TaskRegistry([$task1, $task2], $this->idResolver);

        self::assertTrue($registry->has('task.one'));
        self::assertTrue($registry->has('task.two'));
    }

    public function testConstructThrowsOnDuplicateId(): void
    {
        $task1 = new SimpleTask('task.duplicate');
        $task2 = new SimpleTask('task.duplicate');

        self::expectException(DuplicateTaskIdException::class);

        new TaskRegistry([$task1, $task2], $this->idResolver);
    }

    public function testGetReturnsTask(): void
    {
        $task = new SimpleTask('task.one', 'My task description');
        $registry = new TaskRegistry([$task], $this->idResolver);

        $retrieved = $registry->get('task.one');

        self::assertSame($task, $retrieved);
    }

    public function testGetThrowsForUnknown(): void
    {
        $registry = new TaskRegistry([], $this->idResolver);

        self::expectException(TaskNotFoundException::class);

        $registry->get('nonexistent');
    }

    public function testHasReturnsTrueForRegistered(): void
    {
        $registry = new TaskRegistry([new SimpleTask('task.one')], $this->idResolver);

        self::assertTrue($registry->has('task.one'));
    }

    public function testHasReturnsFalseForUnknown(): void
    {
        $registry = new TaskRegistry([], $this->idResolver);

        self::assertFalse($registry->has('task.unknown'));
    }

    public function testAllReturnsAllTasks(): void
    {
        $task1 = new SimpleTask('task.one');
        $task2 = new SimpleTask('task.two');
        $registry = new TaskRegistry([$task1, $task2], $this->idResolver);

        $all = $registry->all();

        self::assertCount(2, $all);
        self::assertArrayHasKey('task.one', $all);
        self::assertArrayHasKey('task.two', $all);
    }

    public function testAllFiltersByEnvironment(): void
    {
        $simple = new SimpleTask('task.simple');
        $prodOnly = new ProdOnlyTask();
        $multiEnv = new MultiEnvTask();

        $registry = new TaskRegistry([$simple, $prodOnly, $multiEnv], $this->idResolver);

        $prodTasks = $registry->all('prod');
        self::assertArrayHasKey('task.simple', $prodTasks);
        self::assertArrayHasKey('test.prod_only', $prodTasks);
        self::assertArrayNotHasKey('test.multi_env', $prodTasks);

        $devTasks = $registry->all('dev');
        self::assertArrayHasKey('task.simple', $devTasks);
        self::assertArrayHasKey('test.multi_env', $devTasks);
        self::assertArrayNotHasKey('test.prod_only', $devTasks);

        $stagingTasks = $registry->all('staging');
        self::assertArrayHasKey('task.simple', $stagingTasks);
        self::assertArrayNotHasKey('test.prod_only', $stagingTasks);
        self::assertArrayNotHasKey('test.multi_env', $stagingTasks);
    }

    public function testAllWithNullEnvironment(): void
    {
        $simple = new SimpleTask('task.simple');
        $prodOnly = new ProdOnlyTask();
        $multiEnv = new MultiEnvTask();

        $registry = new TaskRegistry([$simple, $prodOnly, $multiEnv], $this->idResolver);

        $all = $registry->all(null);

        self::assertCount(3, $all);
        self::assertArrayHasKey('task.simple', $all);
        self::assertArrayHasKey('test.prod_only', $all);
        self::assertArrayHasKey('test.multi_env', $all);
    }

    public function testAttributeIdUsedByResolver(): void
    {
        $task = new AttributeOnlyTask();

        $registry = new TaskRegistry([$task], $this->idResolver);

        self::assertTrue($registry->has('attribute_only'));
    }

    public function testConstructWithResolverDetectsDuplicates(): void
    {
        $task1 = new SimpleTask('same_id');
        $task2 = new SimpleTask('same_id');

        self::expectException(DuplicateTaskIdException::class);

        new TaskRegistry([$task1, $task2], $this->idResolver);
    }
}
