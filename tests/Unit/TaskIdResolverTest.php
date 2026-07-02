<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Soviann\DeployTasksBundle\Exception\MismatchedTaskIdException;
use Soviann\DeployTasksBundle\Identifier\DefaultTaskIdGenerator;
use Soviann\DeployTasksBundle\Identifier\TaskIdResolver;
use Soviann\DeployTasksBundle\Tests\Fixtures\AttributeOnlyTask;
use Soviann\DeployTasksBundle\Tests\Fixtures\MismatchedIdTask;
use Soviann\DeployTasksBundle\Tests\Fixtures\NoAttributeSeedCategoriesTask;
use Soviann\DeployTasksBundle\Tests\Fixtures\ProviderAndAttributeTask;
use Soviann\DeployTasksBundle\Tests\Fixtures\SimpleTask;

#[CoversClass(TaskIdResolver::class)]
final class TaskIdResolverTest extends TestCase
{
    private TaskIdResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new TaskIdResolver(new DefaultTaskIdGenerator());
    }

    public function testProviderTakesPrecedenceOverAttribute(): void
    {
        $task = new ProviderAndAttributeTask();

        // ProviderAndAttributeTask implements TaskIdProviderInterface and has #[AsDeployTask(id: 'matching_id')]
        // Provider value is used when both are present
        self::assertSame('matching_id', $this->resolver->resolve($task));
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

    public function testMismatchedDeclarationsThrow(): void
    {
        // Two conflicting non-empty IDs are a config bug: whichever "won" would
        // silently rewrite stored history if the other declaration were removed.
        $task = new MismatchedIdTask();

        $this->expectException(MismatchedTaskIdException::class);
        $this->expectExceptionMessageMatches('/mismatched IDs.*"method_id".*"attribute_id"/');

        $this->resolver->resolve($task);
    }
}
