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
        yield 'falls back when only Task remains' => ['Task', 'task'];
        yield 'falls back when only DeployTask remains' => ['DeployTask', 'deploy_task'];
        yield 'uses short class name (FQCN)' => ['App\Tasks\SeedCategories', 'seed_categories'];
        yield 'uses short class name with Task suffix (FQCN)' => ['App\Tasks\SeedCategoriesTask', 'seed_categories'];
        yield 'uses short class name with DeployTask prefix (FQCN)' => ['App\Tasks\DeployTask20260416205300', 'task_20260416205300'];
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

    public function testGenerateStaticReturnsNonEmptyString(): void
    {
        /* @phpstan-ignore argument.type */
        $result = DefaultTaskIdGenerator::generateStatic('App\Tasks\SeedCategories');

        self::assertNotEmpty($result);
    }
}
