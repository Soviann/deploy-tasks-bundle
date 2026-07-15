<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Soviann\DeployTasksBundle\DeployTaskInterface;
use Soviann\DeployTasksBundle\Exception\DuplicateTaskIdException;
use Soviann\DeployTasksBundle\Exception\TaskNotFoundException;
use Soviann\DeployTasksBundle\Identifier\TaskIdProviderInterface;
use Soviann\DeployTasksBundle\Identifier\TaskIdResolver;
use Soviann\DeployTasksBundle\Runner\TaskRegistry;
use Soviann\DeployTasksBundle\TaskResult;
use Soviann\DeployTasksBundle\Tests\Fixtures\AttributeOnlyTask;
use Soviann\DeployTasksBundle\Tests\Fixtures\MultiEnvTask;
use Soviann\DeployTasksBundle\Tests\Fixtures\MultiGroupTask;
use Soviann\DeployTasksBundle\Tests\Fixtures\PredeployTask;
use Soviann\DeployTasksBundle\Tests\Fixtures\ProdOnlyTask;
use Soviann\DeployTasksBundle\Tests\Fixtures\SimpleTask;
use Symfony\Component\Console\Output\OutputInterface;

#[CoversClass(TaskRegistry::class)]
final class TaskRegistryTest extends TestCase
{
    private TaskIdResolver $idResolver;

    protected function setUp(): void
    {
        $this->idResolver = new TaskIdResolver();
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

    public function testDuplicateIdExceptionMessageNamesBothFqcns(): void
    {
        $fqcn1 = 'App\Migrations\FooTask';
        $fqcn2 = 'App\Tasks\FooTask';

        // Both tasks return the same ID so the registry collision fires.
        $task1 = new class($fqcn1) implements DeployTaskInterface, TaskIdProviderInterface {
            public function __construct(private readonly string $id)
            {
            }

            public function getTaskId(): string
            {
                return 'foo';
            }

            public function getDescription(): string
            {
                return $this->id;
            }

            public function run(OutputInterface $output): TaskResult
            {
                return TaskResult::SUCCESS;
            }
        };

        $task2 = new class($fqcn2) implements DeployTaskInterface, TaskIdProviderInterface {
            public function __construct(private readonly string $id)
            {
            }

            public function getTaskId(): string
            {
                return 'foo';
            }

            public function getDescription(): string
            {
                return $this->id;
            }

            public function run(OutputInterface $output): TaskResult
            {
                return TaskResult::SUCCESS;
            }
        };

        try {
            new TaskRegistry([$task1, $task2], $this->idResolver);
            self::fail('Expected DuplicateTaskIdException was not thrown.');
        } catch (DuplicateTaskIdException $e) {
            self::assertStringContainsString('"foo"', $e->getMessage());
            self::assertStringContainsString($task1::class, $e->getMessage());
            self::assertStringContainsString($task2::class, $e->getMessage());
            self::assertStringContainsString('#[AsDeployTask(id: ...)]', $e->getMessage());
        }
    }

    public function testConstructThrowsOnIdsDifferingOnlyByCase(): void
    {
        // MySQL *_ci collations and APFS/NTFS file names treat these two ids as
        // the same storage key — both tasks would share one execution record and
        // one of them would silently never run. SimpleTask provides its id via
        // TaskIdProviderInterface, so this collision is invisible to the compiler
        // pass and MUST be caught here at boot.
        $task1 = new SimpleTask('Seed_Users');
        $task2 = new SimpleTask('seed_users');

        try {
            new TaskRegistry([$task1, $task2], $this->idResolver);
            self::fail('Expected DuplicateTaskIdException was not thrown.');
        } catch (DuplicateTaskIdException $e) {
            self::assertStringContainsString('"Seed_Users"', $e->getMessage());
            self::assertStringContainsString('"seed_users"', $e->getMessage());
            self::assertStringContainsString($task1::class, $e->getMessage());
            self::assertStringContainsString('letter case', $e->getMessage());
        }
    }

    public function testRejectsProviderIdWithDisallowedCharacters(): void
    {
        $task = new class implements DeployTaskInterface, TaskIdProviderInterface {
            public function getTaskId(): string
            {
                return "bad id\x1b!";
            }

            public function getDescription(): string
            {
                return 'Task with a hostile provider id';
            }

            public function run(OutputInterface $output): TaskResult
            {
                return TaskResult::SUCCESS;
            }
        };

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Invalid task id/');

        new TaskRegistry([$task], new TaskIdResolver());
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

    public function testAllWithNoGroupsReturnsOnlyDefaultTasks(): void
    {
        $default = new SimpleTask('task.default');
        $predeploy = new PredeployTask();

        $registry = new TaskRegistry([$default, $predeploy], $this->idResolver);

        $all = $registry->all();

        self::assertArrayHasKey('task.default', $all);
        self::assertArrayNotHasKey('test.predeploy', $all);
    }

    public function testAllWithGroupFilterReturnsTasksInGroup(): void
    {
        $default = new SimpleTask('task.default');
        $predeploy = new PredeployTask();

        $registry = new TaskRegistry([$default, $predeploy], $this->idResolver);

        $filtered = $registry->all(null, ['predeploy']);

        self::assertArrayHasKey('test.predeploy', $filtered);
        self::assertArrayNotHasKey('task.default', $filtered);
    }

    public function testAllWithMultipleGroupsReturnsUnion(): void
    {
        $predeploy = new PredeployTask();
        $multi = new MultiGroupTask();

        $registry = new TaskRegistry([$predeploy, $multi], $this->idResolver);

        $filtered = $registry->all(null, ['predeploy', 'postdeploy']);

        self::assertArrayHasKey('test.predeploy', $filtered);
        self::assertArrayHasKey('test.multi_group', $filtered);
    }

    public function testAllWithGroupExcludesDefault(): void
    {
        $default = new SimpleTask('task.default');
        $multi = new MultiGroupTask();

        $registry = new TaskRegistry([$default, $multi], $this->idResolver);

        $filtered = $registry->all(null, ['postdeploy']);

        self::assertArrayHasKey('test.multi_group', $filtered);
        self::assertArrayNotHasKey('task.default', $filtered);
    }

    public function testAllCombinesEnvAndGroups(): void
    {
        $default = new SimpleTask('task.default');
        $predeploy = new PredeployTask();
        $prodOnly = new ProdOnlyTask();

        $registry = new TaskRegistry([$default, $predeploy, $prodOnly], $this->idResolver);

        $filtered = $registry->all('prod', ['predeploy']);

        self::assertArrayHasKey('test.predeploy', $filtered);
        self::assertArrayNotHasKey('task.default', $filtered);
        self::assertArrayNotHasKey('test.prod_only', $filtered);
    }

    public function testGroupFilterExcludesTasksThatShareNoGroupWithRequestedGroups(): void
    {
        // Mutants 173–174: UnwrapArrayIntersect — array_intersect removed so the
        // entire $declared array is used instead of its intersection. Without
        // intersection, a task whose declared groups do NOT overlap the requested
        // groups would still be included.
        //
        // MultiGroupTask has groups ['predeploy', 'postdeploy'].
        // PredeployTask has group ['predeploy'].
        // Requesting only ['postdeploy'] must return MultiGroupTask but NOT PredeployTask.
        $predeploy = new PredeployTask();
        $multi = new MultiGroupTask();

        $registry = new TaskRegistry([$predeploy, $multi], $this->idResolver);

        $filtered = $registry->all(null, ['postdeploy']);

        self::assertArrayHasKey('test.multi_group', $filtered, 'Multi-group task with postdeploy must be included.');
        self::assertArrayNotHasKey(
            'test.predeploy',
            $filtered,
            'Predeploy-only task must NOT be included when requesting postdeploy.',
        );
    }

    public function testGroupFilterRequiresIntersectionNotSuperset(): void
    {
        // Complementary to the previous test: requesting ['predeploy'] must include
        // MultiGroupTask (predeploy ∩ [predeploy,postdeploy] = [predeploy] ≠ [])
        // but must exclude a task that only has ['postdeploy'].
        $predeploy = new PredeployTask();   // groups: ['predeploy']
        $multi = new MultiGroupTask();      // groups: ['predeploy', 'postdeploy']

        $registry = new TaskRegistry([$predeploy, $multi], $this->idResolver);

        $filtered = $registry->all(null, ['predeploy']);

        self::assertArrayHasKey('test.predeploy', $filtered);
        self::assertArrayHasKey(
            'test.multi_group',
            $filtered,
            'Task whose groups intersect the requested set must be included.',
        );
    }
}
