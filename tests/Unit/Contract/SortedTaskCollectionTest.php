<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Tests\Unit\Contract;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Soviann\DeployTasksBundle\DeployTaskInterface;
use Soviann\DeployTasksBundle\Sorting\SortedTaskCollection;

#[CoversClass(SortedTaskCollection::class)]
final class SortedTaskCollectionTest extends TestCase
{
    public function testEmptyCollection(): void
    {
        $collection = new SortedTaskCollection();

        self::assertTrue($collection->isEmpty());
        self::assertCount(0, $collection);
        self::assertSame([], $collection->toArray());
    }

    public function testCollectionWithTasks(): void
    {
        $task1 = $this->createMock(DeployTaskInterface::class);
        $task2 = $this->createMock(DeployTaskInterface::class);

        $collection = new SortedTaskCollection($task1, $task2);

        self::assertFalse($collection->isEmpty());
        self::assertCount(2, $collection);
        self::assertSame([$task1, $task2], $collection->toArray());
    }

    public function testIterable(): void
    {
        $task1 = $this->createMock(DeployTaskInterface::class);
        $task2 = $this->createMock(DeployTaskInterface::class);

        $collection = new SortedTaskCollection($task1, $task2);

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

        $collection = new SortedTaskCollection($task1, $task2, $task3);

        self::assertCount(3, $collection);
    }

    public function testSingleTaskCollection(): void
    {
        $task = $this->createMock(DeployTaskInterface::class);
        $collection = new SortedTaskCollection($task);

        self::assertFalse($collection->isEmpty());
        self::assertCount(1, $collection);
        self::assertSame([$task], $collection->toArray());
    }
}
