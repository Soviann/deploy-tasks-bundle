<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Tests\Unit\Contract;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Soviann\DeployTasksBundle\DeployTaskInterface;
use Soviann\DeployTasksBundle\Ordering\OrderedTaskCollection;

#[CoversClass(OrderedTaskCollection::class)]
final class OrderedTaskCollectionTest extends TestCase
{
    public function testEmptyCollection(): void
    {
        $collection = new OrderedTaskCollection();

        self::assertTrue($collection->isEmpty());
        self::assertSame(0, \count($collection));
        self::assertSame([], $collection->toArray());
    }

    public function testCollectionWithTasks(): void
    {
        $task1 = $this->createMock(DeployTaskInterface::class);
        $task2 = $this->createMock(DeployTaskInterface::class);

        $collection = new OrderedTaskCollection($task1, $task2);

        self::assertFalse($collection->isEmpty());
        self::assertSame(2, \count($collection));
        self::assertSame([$task1, $task2], $collection->toArray());
    }

    public function testIterable(): void
    {
        $task1 = $this->createMock(DeployTaskInterface::class);
        $task2 = $this->createMock(DeployTaskInterface::class);

        $collection = new OrderedTaskCollection($task1, $task2);

        $iterated = [];
        foreach ($collection as $task) {
            $iterated[] = $task;
        }

        self::assertSame([$task1, $task2], $iterated);
    }

    public function testCountable(): void
    {
        $task1 = $this->createMock(DeployTaskInterface::class);
        $task2 = $this->createMock(DeployTaskInterface::class);
        $task3 = $this->createMock(DeployTaskInterface::class);

        $collection = new OrderedTaskCollection($task1, $task2, $task3);

        self::assertSame(3, \count($collection));
    }

    public function testSingleTaskCollection(): void
    {
        $task = $this->createMock(DeployTaskInterface::class);
        $collection = new OrderedTaskCollection($task);

        self::assertFalse($collection->isEmpty());
        self::assertSame(1, \count($collection));
        self::assertSame([$task], $collection->toArray());
    }
}
