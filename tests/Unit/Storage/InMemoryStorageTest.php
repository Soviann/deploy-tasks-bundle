<?php

declare(strict_types=1);

namespace Soviann\DeployTasks\Tests\Unit\Storage;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Soviann\DeployTasks\Contract\TaskExecution;
use Soviann\DeployTasks\Contract\TaskStatus;
use Soviann\DeployTasks\Storage\InMemoryStorage;

#[CoversClass(InMemoryStorage::class)]
final class InMemoryStorageTest extends TestCase
{
    private InMemoryStorage $storage;

    protected function setUp(): void
    {
        $this->storage = new InMemoryStorage();
    }

    public function testHasReturnsFalseForMissingTask(): void
    {
        self::assertFalse($this->storage->has('task.missing'));
    }

    public function testGetReturnsNullForMissingTask(): void
    {
        self::assertNull($this->storage->get('task.missing'));
    }

    public function testSaveAndRetrieve(): void
    {
        $execution = new TaskExecution('task.1', TaskStatus::Ran, new \DateTimeImmutable('2026-04-12T14:30:00+00:00'));

        $this->storage->save($execution);

        self::assertTrue($this->storage->has('task.1'));
        self::assertSame($execution, $this->storage->get('task.1'));
    }

    public function testSaveOverwrites(): void
    {
        $first = new TaskExecution('task.1', TaskStatus::Ran, new \DateTimeImmutable('2026-04-12T14:30:00+00:00'));
        $second = new TaskExecution('task.1', TaskStatus::Failed, new \DateTimeImmutable('2026-04-12T15:00:00+00:00'));

        $this->storage->save($first);
        $this->storage->save($second);

        self::assertSame($second, $this->storage->get('task.1'));
    }

    public function testRemove(): void
    {
        $execution = new TaskExecution('task.1', TaskStatus::Ran, new \DateTimeImmutable('2026-04-12T14:30:00+00:00'));

        $this->storage->save($execution);
        $this->storage->remove('task.1');

        self::assertFalse($this->storage->has('task.1'));
    }

    public function testRemoveNonExistent(): void
    {
        // Should not throw
        $this->storage->remove('task.nonexistent');

        self::assertFalse($this->storage->has('task.nonexistent'));
    }

    public function testAll(): void
    {
        $first = new TaskExecution('task.1', TaskStatus::Ran, new \DateTimeImmutable('2026-04-12T14:30:00+00:00'));
        $second = new TaskExecution('task.2', TaskStatus::Skipped, new \DateTimeImmutable('2026-04-12T15:00:00+00:00'));

        $this->storage->save($first);
        $this->storage->save($second);

        $all = $this->storage->all();

        self::assertCount(2, $all);
        self::assertSame($first, $all['task.1']);
        self::assertSame($second, $all['task.2']);
    }

    public function testAllEmpty(): void
    {
        self::assertSame([], $this->storage->all());
    }

    public function testReset(): void
    {
        $this->storage->save(new TaskExecution('task.1', TaskStatus::Ran, new \DateTimeImmutable()));
        $this->storage->save(new TaskExecution('task.2', TaskStatus::Skipped, new \DateTimeImmutable()));

        $this->storage->reset();

        self::assertSame([], $this->storage->all());
    }

    public function testResetEmpty(): void
    {
        $this->storage->reset();

        self::assertSame([], $this->storage->all());
    }
}
