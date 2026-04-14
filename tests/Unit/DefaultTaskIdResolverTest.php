<?php

declare(strict_types=1);

namespace Soviann\DeployTasks\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Soviann\DeployTasks\DefaultTaskIdGenerator;
use Soviann\DeployTasks\DefaultTaskIdResolver;
use Soviann\DeployTasks\Tests\Fixtures\AttributeOnlyTask;
use Soviann\DeployTasks\Tests\Fixtures\MismatchedIdTask;
use Soviann\DeployTasks\Tests\Fixtures\NoAttributeSeedCategoriesTask;
use Soviann\DeployTasks\Tests\Fixtures\ProdOnlyTask;
use Soviann\DeployTasks\Tests\Fixtures\SimpleTask;

#[CoversClass(DefaultTaskIdResolver::class)]
final class DefaultTaskIdResolverTest extends TestCase
{
    private DefaultTaskIdResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new DefaultTaskIdResolver(new DefaultTaskIdGenerator());
    }

    public function testTaskIdProviderTakesPrecedenceOverAttribute(): void
    {
        $task = new ProdOnlyTask();

        // ProdOnlyTask has #[AsDeployTask(id: 'test.prod_only')] and getTaskId() returns 'test.prod_only'
        // Both match, so no conflict — interface value is used
        self::assertSame('test.prod_only', $this->resolver->resolve($task));
    }

    public function testAttributeFallbackWhenNoProvider(): void
    {
        $task = new AttributeOnlyTask();

        // AttributeOnlyTask has attribute id but does not implement TaskIdProviderInterface
        self::assertSame('attribute_only', $this->resolver->resolve($task));
    }

    public function testTaskIdProviderWhenNoAttribute(): void
    {
        $task = new SimpleTask('my.task.id');

        // SimpleTask implements TaskIdProviderInterface, no attribute
        self::assertSame('my.task.id', $this->resolver->resolve($task));
    }

    public function testFqcnAutoDeductionWhenBothEmpty(): void
    {
        $task = new NoAttributeSeedCategoriesTask();

        // No attribute, no TaskIdProviderInterface → deduce from FQCN
        // NoAttributeSeedCategoriesTask → strip "Task" suffix → "NoAttributeSeedCategories" → snake_case
        self::assertSame('no_attribute_seed_categories', $this->resolver->resolve($task));
    }

    public function testMismatchTriggersWarningAndUsesInterfaceValue(): void
    {
        $task = new MismatchedIdTask();

        $warning = null;
        \set_error_handler(static function (int $errno, string $errstr) use (&$warning): bool {
            $warning = $errstr;

            return true;
        }, \E_USER_WARNING);

        try {
            $result = $this->resolver->resolve($task);
        } finally {
            \restore_error_handler();
        }

        self::assertSame('method_id', $result);
        self::assertNotNull($warning);
        self::assertStringContainsString('mismatched IDs', $warning);
        self::assertStringContainsString('attribute_id', (string) $warning);
        self::assertStringContainsString('method_id', (string) $warning);
    }
}
