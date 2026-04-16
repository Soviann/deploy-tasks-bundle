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
     * @return iterable<string, array{class-string, string}>
     */
    public static function provideClassNames(): iterable
    {
        yield 'strips Task suffix' => ['SeedCategoriesTask', 'seed_categories'];
        yield 'strips DeployTask suffix' => ['SeedCategoriesDeployTask', 'seed_categories'];
        yield 'strips DeployTask prefix (timestamp class)' => ['DeployTask20260416205300', '20260416205300'];
        yield 'strips DeployTask prefix (with name)' => ['DeployTaskSeedCategories', 'seed_categories'];
        yield 'strips Task prefix' => ['TaskSeedCategories', 'seed_categories'];
        yield 'no suffix — converts CamelCase as-is' => ['SeedCategories', 'seed_categories'];
        yield 'falls back when only Task remains' => ['Task', 'task'];
        yield 'falls back when only DeployTask remains' => ['DeployTask', 'deploy_task'];
        yield 'uses short class name (FQCN)' => ['App\Tasks\SeedCategories', 'seed_categories'];
        yield 'uses short class name with Task suffix (FQCN)' => ['App\Tasks\SeedCategoriesTask', 'seed_categories'];
        yield 'uses short class name with DeployTask prefix (FQCN)' => ['App\Tasks\DeployTask20260416205300', '20260416205300'];
    }

    /**
     * @param class-string $className
     */
    #[DataProvider('provideClassNames')]
    public function testGenerate(string $className, string $expectedId): void
    {
        self::assertSame($expectedId, $this->generator->generate($className));
    }

    /**
     * @param class-string $className
     */
    #[DataProvider('provideClassNames')]
    public function testGenerateStatic(string $className, string $expectedId): void
    {
        self::assertSame($expectedId, DefaultTaskIdGenerator::generateStatic($className));
    }

    public function testGenerateStaticNeverReturnsNull(): void
    {
        $result = DefaultTaskIdGenerator::generateStatic('App\Tasks\SeedCategories');

        self::assertNotNull($result);
        self::assertIsString($result);
    }
}
