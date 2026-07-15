<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Tests\Unit\Storage;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Soviann\DeployTasksBundle\Storage\TaskExecution;
use Soviann\DeployTasksBundle\Storage\TaskStatus;
use Soviann\DeployTasksBundle\Storage\TaskStorageInterface;

/**
 * Behavioral contract every TaskStorageInterface implementation must satisfy.
 *
 * Concrete subclasses provide the backend via createStorage(); backend-specific
 * behavior (permissions, SQL shapes, races, instance identity) stays in the
 * concrete test classes. Assertions here compare record fields, never object
 * identity, so they hold for both in-process and round-tripping backends.
 */
abstract class TaskStorageContractTestCase extends TestCase
{
    public function testHasReturnsFalseForMissingTask(): void
    {
        self::assertFalse($this->createStorage()->has('task.missing'));
    }

    public function testGetReturnsNullForMissingTask(): void
    {
        self::assertNull($this->createStorage()->get('task.missing'));
    }

    public function testSaveAndRetrieve(): void
    {
        $storage = $this->createStorage();
        $execution = new TaskExecution('task.1', TaskStatus::Ran, new \DateTimeImmutable('2026-04-12T14:30:00+00:00'));

        $storage->save($execution);

        self::assertTrue($storage->has('task.1'));

        $retrieved = $storage->get('task.1');

        self::assertNotNull($retrieved);
        self::assertSame($execution->id, $retrieved->id);
        self::assertSame($execution->status, $retrieved->status);
        self::assertSame(
            $execution->executedAt->format(\DateTimeInterface::ATOM),
            $retrieved->executedAt->format(\DateTimeInterface::ATOM),
        );
        self::assertSame($execution->error, $retrieved->error);
    }

    public function testSaveOverwrites(): void
    {
        $storage = $this->createStorage();
        $first = new TaskExecution('task.1', TaskStatus::Ran, new \DateTimeImmutable('2026-04-12T14:30:00+00:00'));
        $second = new TaskExecution('task.1', TaskStatus::Failed, new \DateTimeImmutable('2026-04-12T15:00:00+00:00'));

        $storage->save($first);
        $storage->save($second);

        $retrieved = $storage->get('task.1');

        self::assertNotNull($retrieved);
        self::assertSame(TaskStatus::Failed, $retrieved->status);
    }

    public function testRemove(): void
    {
        $storage = $this->createStorage();
        $execution = new TaskExecution('task.1', TaskStatus::Ran, new \DateTimeImmutable('2026-04-12T14:30:00+00:00'));

        $storage->save($execution);
        $storage->remove('task.1');

        self::assertFalse($storage->has('task.1'));
    }

    public function testRemoveNonExistent(): void
    {
        $storage = $this->createStorage();

        // Should not throw
        $storage->remove('task.nonexistent');

        self::assertFalse($storage->has('task.nonexistent'));
    }

    public function testAllReturnsFlatList(): void
    {
        $storage = $this->createStorage();
        $storage->save(new TaskExecution(
            'task.1', TaskStatus::Ran, new \DateTimeImmutable('2026-04-12T14:30:00+00:00'),
        ));
        $storage->save(new TaskExecution(
            'task.2', TaskStatus::Skipped, new \DateTimeImmutable('2026-04-12T15:00:00+00:00'),
        ));
        $storage->save(new TaskExecution(
            'task.2', TaskStatus::Ran, new \DateTimeImmutable('2026-04-12T15:30:00+00:00'), null, 'predeploy',
        ));

        $all = $storage->all();

        self::assertCount(3, $all);

        $keys = \array_map(static fn (TaskExecution $e): string => $e->id.'@'.($e->group ?? ''), $all);
        \sort($keys);

        self::assertSame(['task.1@', 'task.2@', 'task.2@predeploy'], $keys);
    }

    public function testAllEmpty(): void
    {
        self::assertSame([], $this->createStorage()->all());
    }

    public function testSaveAndGetWithGroup(): void
    {
        $storage = $this->createStorage();
        $execution = new TaskExecution(
            'task.1', TaskStatus::Ran, new \DateTimeImmutable('2026-04-12T14:30:00+00:00'), null, 'predeploy',
        );

        $storage->save($execution);

        $retrieved = $storage->get('task.1', 'predeploy');

        self::assertNotNull($retrieved);
        self::assertSame('task.1', $retrieved->id);
        self::assertSame('predeploy', $retrieved->group);
        self::assertNull($storage->get('task.1'));
        self::assertNull($storage->get('task.1', 'postdeploy'));
    }

    public function testHasIsScopedByGroup(): void
    {
        $storage = $this->createStorage();
        $storage->save(new TaskExecution('task.1', TaskStatus::Ran, new \DateTimeImmutable(), null, 'predeploy'));

        self::assertTrue($storage->has('task.1', 'predeploy'));
        self::assertFalse($storage->has('task.1'));
        self::assertFalse($storage->has('task.1', 'postdeploy'));
    }

    public function testRemoveIsScopedByGroup(): void
    {
        $storage = $this->createStorage();
        $storage->save(new TaskExecution('task.1', TaskStatus::Ran, new \DateTimeImmutable(), null, 'predeploy'));
        $storage->save(new TaskExecution('task.1', TaskStatus::Ran, new \DateTimeImmutable(), null, 'postdeploy'));

        $storage->remove('task.1', 'predeploy');

        self::assertFalse($storage->has('task.1', 'predeploy'));
        self::assertTrue($storage->has('task.1', 'postdeploy'));
    }

    /**
     * The empty string is not a valid group name. Backends that key the default
     * (null) slot on '' internally would silently alias the default slot if ''
     * were accepted from callers, so every backend must reject it identically —
     * as an input-contract violation, before the backend is touched.
     *
     * @param \Closure(TaskStorageInterface): mixed $call
     */
    #[DataProvider('provideEmptyStringGroupCalls')]
    public function testEmptyStringGroupIsRejectedAsInvalidInput(\Closure $call): void
    {
        $storage = $this->createStorage();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must not be the empty string');

        $call($storage);
    }

    /**
     * @return iterable<string, array{\Closure(TaskStorageInterface): mixed}>
     */
    public static function provideEmptyStringGroupCalls(): iterable
    {
        yield 'has' => [static fn (TaskStorageInterface $storage): bool => $storage->has('task.1', '')];

        yield 'get' => [static fn (TaskStorageInterface $storage): ?TaskExecution => $storage->get('task.1', '')];

        yield 'remove' => [static function (TaskStorageInterface $storage): void {
            $storage->remove('task.1', '');
        }];

        yield 'save' => [static function (TaskStorageInterface $storage): void {
            $storage->save(new TaskExecution(
                'task.1', TaskStatus::Ran, new \DateTimeImmutable('2026-04-12T14:30:00+00:00'), null, '',
            ));
        }];
    }

    public function testRemoveAllDeletesEverySlot(): void
    {
        $storage = $this->createStorage();
        $storage->save(new TaskExecution('task.1', TaskStatus::Ran, new \DateTimeImmutable()));
        $storage->save(new TaskExecution('task.1', TaskStatus::Ran, new \DateTimeImmutable(), null, 'predeploy'));
        $storage->save(new TaskExecution('task.1', TaskStatus::Ran, new \DateTimeImmutable(), null, 'postdeploy'));
        $storage->save(new TaskExecution('task.2', TaskStatus::Ran, new \DateTimeImmutable()));

        $storage->removeAll('task.1');

        self::assertFalse($storage->has('task.1'));
        self::assertFalse($storage->has('task.1', 'predeploy'));
        self::assertFalse($storage->has('task.1', 'postdeploy'));
        self::assertTrue($storage->has('task.2'));
    }

    public function testFindByTaskIdReturnsEverySlot(): void
    {
        $storage = $this->createStorage();
        $storage->save(new TaskExecution(
            'task.1', TaskStatus::Ran, new \DateTimeImmutable('2026-04-12T14:30:00+00:00'),
        ));
        $storage->save(new TaskExecution(
            'task.1', TaskStatus::Ran, new \DateTimeImmutable('2026-04-12T14:35:00+00:00'), null, 'predeploy',
        ));
        $storage->save(new TaskExecution(
            'task.1', TaskStatus::Skipped, new \DateTimeImmutable('2026-04-12T14:40:00+00:00'), null, 'postdeploy',
        ));
        $storage->save(new TaskExecution(
            'task.2', TaskStatus::Ran, new \DateTimeImmutable('2026-04-12T14:50:00+00:00'),
        ));

        $matches = [...$storage->findByTaskId('task.1')];
        $ids = \array_map(static fn (TaskExecution $e): string => $e->id, $matches);
        $groups = \array_map(static fn (TaskExecution $e): ?string => $e->group, $matches);

        self::assertCount(3, $matches);
        self::assertSame(['task.1', 'task.1', 'task.1'], $ids);
        self::assertEqualsCanonicalizing([null, 'predeploy', 'postdeploy'], $groups);
    }

    public function testFindByTaskIdReturnsSingleSlotWhenOnlyDefaultStored(): void
    {
        $storage = $this->createStorage();
        $storage->save(new TaskExecution(
            'task.1', TaskStatus::Ran, new \DateTimeImmutable('2026-04-12T14:30:00+00:00'),
        ));

        $matches = [...$storage->findByTaskId('task.1')];

        self::assertCount(1, $matches);
        self::assertSame('task.1', $matches[0]->id);
        self::assertNull($matches[0]->group);
    }

    public function testFindByTaskIdUnknownIdReturnsEmpty(): void
    {
        $storage = $this->createStorage();
        $storage->save(new TaskExecution('task.1', TaskStatus::Ran, new \DateTimeImmutable()));

        self::assertSame([], [...$storage->findByTaskId('task.missing')]);
    }

    public function testReset(): void
    {
        $storage = $this->createStorage();
        $storage->save(new TaskExecution('task.1', TaskStatus::Ran, new \DateTimeImmutable()));
        $storage->save(new TaskExecution('task.2', TaskStatus::Skipped, new \DateTimeImmutable()));

        $storage->reset();

        self::assertSame([], $storage->all());
    }

    public function testResetEmpty(): void
    {
        $storage = $this->createStorage();

        $storage->reset();

        self::assertSame([], $storage->all());
    }

    /**
     * Returns a fresh, empty storage backend under test.
     */
    abstract protected function createStorage(): TaskStorageInterface;
}
