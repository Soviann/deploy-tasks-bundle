<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Soviann\DeployTasksBundle\Identifier\DefaultTaskIdGenerator;

#[CoversClass(DefaultTaskIdGenerator::class)]
final class DefaultTaskIdGeneratorTest extends TestCase
{
    private DefaultTaskIdGenerator $generator;

    protected function setUp(): void
    {
        $this->generator = new DefaultTaskIdGenerator();
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideClassNames(): iterable
    {
        yield 'strips Task suffix' => ['SeedCategoriesTask', 'seed_categories'];
        yield 'strips DeployTask suffix' => ['SeedCategoriesDeployTask', 'seed_categories'];
        yield 'strips DeployTask prefix (timestamp class)' => ['DeployTask20260416205300', 'task_20260416205300'];
        yield 'strips Task prefix (timestamp class)' => ['Task20260416205300', 'task_20260416205300'];
        yield 'strips DeployTask prefix (with name)' => ['DeployTaskSeedCategories', 'seed_categories'];
        yield 'strips Task prefix' => ['TaskSeedCategories', 'seed_categories'];
        yield 'no suffix — converts CamelCase as-is' => ['SeedCategories', 'seed_categories'];
        yield 'uses short class name (FQCN)' => ['App\Tasks\SeedCategories', 'seed_categories'];
        yield 'uses short class name with Task suffix (FQCN)' => ['App\Tasks\SeedCategoriesTask', 'seed_categories'];
        yield 'uses short class name with DeployTask prefix (FQCN)' => [
            'App\Tasks\DeployTask20260416205300',
            'task_20260416205300',
        ];
    }

    #[DataProvider('provideClassNames')]
    public function testGenerate(string $className, string $expectedId): void
    {
        // Deliberately feeds non-existent class names to exercise the pure string-manipulation
        // edge cases (e.g. 'Task', 'DeployTask' alone) — the generator performs no reflection.
        /* @phpstan-ignore argument.type */
        self::assertSame($expectedId, $this->generator->generate($className));
    }

    #[DataProvider('provideClassNames')]
    public function testGenerateStatic(string $className, string $expectedId): void
    {
        /* @phpstan-ignore argument.type */
        self::assertSame($expectedId, DefaultTaskIdGenerator::generateStatic($className));
    }

    public function testMixedAlphanumericSuffixIsNotTreatedAsTimestamp(): void
    {
        // After stripping the 'Task' suffix, the remainder is 'Seed123'.
        // '/^\d+$/' (anchored both ends) must NOT match — 'Seed123' is not purely numeric.
        // The mutant PregMatchRemoveCaret ('/\d+$/') would wrongly match and return 'task_123'.
        // The correct result is the snake_case conversion: 'seed123'.
        /* @phpstan-ignore argument.type */
        self::assertSame('seed123', DefaultTaskIdGenerator::generateStatic('Seed123Task'));
    }

    public function testMixedAlphanumericPrefixIsNotTreatedAsTimestamp(): void
    {
        // After stripping the 'Task' prefix, the remainder is '123Seed'.
        // '/^\d+$/' (anchored both ends) must NOT match — '123Seed' is not purely numeric.
        // The mutant PregMatchRemoveDollar ('/^\d+/') would wrongly match and return 'task_123Seed'.
        // The correct result is the snake_case conversion: '123_seed'.
        /* @phpstan-ignore argument.type */
        self::assertSame('123_seed', DefaultTaskIdGenerator::generateStatic('Task123Seed'));
    }

    public function testDoesNotStripTaskPrefixMidWord(): void
    {
        // 'Task' is only a prefix at a CamelCase/digit boundary — 'Tasking' and
        // 'Taskmaster' are single words and must keep their full names.
        /* @phpstan-ignore argument.type */
        self::assertSame('tasking', DefaultTaskIdGenerator::generateStatic('App\Tasking'));
        /* @phpstan-ignore argument.type */
        self::assertSame('taskmaster', DefaultTaskIdGenerator::generateStatic('App\Taskmaster'));
    }

    public function testStillStripsRealPrefixes(): void
    {
        /* @phpstan-ignore argument.type */
        self::assertSame('task_20260416205300', DefaultTaskIdGenerator::generateStatic('App\Task20260416205300'));
        /* @phpstan-ignore argument.type */
        self::assertSame('seed_categories', DefaultTaskIdGenerator::generateStatic('App\TaskSeedCategories'));
    }

    public function testEmptyIdRaisesForRootNamespaceSingleWordClass(): void
    {
        if (!\class_exists('Task', false)) {
            eval('class Task {}'); // phpcs:ignore
        }

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Cannot derive task id from class name "Task"; supply #[AsDeployTask(id: ...)] explicitly.',
        );

        /* @phpstan-ignore argument.type */
        $this->generator->generate('Task');
    }

    public function testEmptyIdRaisesForRootNamespaceDeployTask(): void
    {
        if (!\class_exists('DeployTask', false)) {
            eval('class DeployTask {}'); // phpcs:ignore
        }

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Cannot derive task id from class name "DeployTask"; supply #[AsDeployTask(id: ...)] explicitly.',
        );

        /* @phpstan-ignore argument.type */
        $this->generator->generate('DeployTask');
    }
}
