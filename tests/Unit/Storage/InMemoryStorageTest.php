<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Tests\Unit\Storage;

use PHPUnit\Framework\Attributes\CoversClass;
use Soviann\DeployTasksBundle\Storage\InMemory\InMemoryStorage;
use Soviann\DeployTasksBundle\Storage\TaskExecution;
use Soviann\DeployTasksBundle\Storage\TaskStatus;
use Soviann\DeployTasksBundle\Storage\TaskStorageInterface;

#[CoversClass(InMemoryStorage::class)]
final class InMemoryStorageTest extends TaskStorageContractTestCase
{
    private InMemoryStorage $storage;

    protected function setUp(): void
    {
        $this->storage = new InMemoryStorage();
    }

    /**
     * Unlike round-tripping backends, the in-memory store keeps the exact
     * TaskExecution instances it was given — get() returns them by identity.
     */
    public function testGetReturnsTheExactSavedInstance(): void
    {
        $execution = new TaskExecution('task.1', TaskStatus::Ran, new \DateTimeImmutable('2026-04-12T14:30:00+00:00'));

        $this->storage->save($execution);

        self::assertSame($execution, $this->storage->get('task.1'));
    }

    public function testSaveOverwritesReturnsTheLatestInstance(): void
    {
        $first = new TaskExecution('task.1', TaskStatus::Ran, new \DateTimeImmutable('2026-04-12T14:30:00+00:00'));
        $second = new TaskExecution('task.1', TaskStatus::Failed, new \DateTimeImmutable('2026-04-12T15:00:00+00:00'));

        $this->storage->save($first);
        $this->storage->save($second);

        self::assertSame($second, $this->storage->get('task.1'));
    }

    public function testGetWithGroupReturnsTheExactSavedInstance(): void
    {
        $execution = new TaskExecution(
            'task.1', TaskStatus::Ran, new \DateTimeImmutable('2026-04-12T14:30:00+00:00'), null, 'predeploy',
        );

        $this->storage->save($execution);

        self::assertSame($execution, $this->storage->get('task.1', 'predeploy'));
    }

    public function testAllReturnsTheExactSavedInstances(): void
    {
        $first = new TaskExecution('task.1', TaskStatus::Ran, new \DateTimeImmutable('2026-04-12T14:30:00+00:00'));
        $second = new TaskExecution('task.2', TaskStatus::Skipped, new \DateTimeImmutable('2026-04-12T15:00:00+00:00'));
        $third = new TaskExecution(
            'task.2', TaskStatus::Ran, new \DateTimeImmutable('2026-04-12T15:30:00+00:00'), null, 'predeploy',
        );

        $this->storage->save($first);
        $this->storage->save($second);
        $this->storage->save($third);

        $all = $this->storage->all();

        self::assertCount(3, $all);
        self::assertContains($first, $all);
        self::assertContains($second, $all);
        self::assertContains($third, $all);
    }

    public function testFindByTaskIdReturnsTheExactSavedInstances(): void
    {
        $default = new TaskExecution('task.1', TaskStatus::Ran, new \DateTimeImmutable('2026-04-12T14:30:00+00:00'));
        $pre = new TaskExecution(
            'task.1', TaskStatus::Ran, new \DateTimeImmutable('2026-04-12T14:35:00+00:00'), null, 'predeploy',
        );
        $post = new TaskExecution(
            'task.1', TaskStatus::Skipped, new \DateTimeImmutable('2026-04-12T14:40:00+00:00'), null, 'postdeploy',
        );
        $other = new TaskExecution('task.2', TaskStatus::Ran, new \DateTimeImmutable('2026-04-12T14:50:00+00:00'));

        $this->storage->save($default);
        $this->storage->save($pre);
        $this->storage->save($post);
        $this->storage->save($other);

        $matches = [...$this->storage->findByTaskId('task.1')];

        self::assertCount(3, $matches);
        self::assertContains($default, $matches);
        self::assertContains($pre, $matches);
        self::assertContains($post, $matches);
        self::assertNotContains($other, $matches);
    }

    public function testFindByTaskIdSingleSlotReturnsTheExactSavedInstance(): void
    {
        $execution = new TaskExecution('task.1', TaskStatus::Ran, new \DateTimeImmutable('2026-04-12T14:30:00+00:00'));

        $this->storage->save($execution);

        $matches = [...$this->storage->findByTaskId('task.1')];

        self::assertCount(1, $matches);
        self::assertSame($execution, $matches[0]);
    }

    /**
     * Kills ConcatOperandRemoval mutant on line 45 in removeAll():
     * mutation replaces `$taskId."\0"` with `$taskId`, making str_starts_with()
     * match keys for tasks whose ID merely starts with $taskId (e.g. task.10 when removing task.1).
     *
     * The internal key format is `$taskId . NUL . $group`. Without the NUL separator,
     * str_starts_with("task.10\0", "task.1") is TRUE, so task.10 would be wrongly removed.
     */
    public function testRemoveAllDoesNotMatchTaskIdThatIsAPrefixOfAnother(): void
    {
        $this->storage->save(new TaskExecution('task.1', TaskStatus::Ran, new \DateTimeImmutable()));
        $this->storage->save(new TaskExecution('task.1', TaskStatus::Ran, new \DateTimeImmutable(), null, 'predeploy'));
        $this->storage->save(new TaskExecution('task.10', TaskStatus::Ran, new \DateTimeImmutable()));
        $this->storage->save(new TaskExecution(
            'task.10', TaskStatus::Ran, new \DateTimeImmutable(), null, 'predeploy',
        ));

        $this->storage->removeAll('task.1');

        self::assertFalse($this->storage->has('task.1'), 'task.1 default slot must be removed.');
        self::assertFalse($this->storage->has('task.1', 'predeploy'), 'task.1 predeploy slot must be removed.');
        self::assertTrue($this->storage->has('task.10'), 'task.10 must NOT be removed by removeAll(task.1).');
        self::assertTrue(
            $this->storage->has('task.10', 'predeploy'),
            'task.10 predeploy must NOT be removed by removeAll(task.1).',
        );
    }

    /**
     * Kills UnwrapArrayValues mutant on line 70 in all():
     * mutation removes array_values(), returning the internal associative array
     * (with composite NUL-keyed keys) instead of a proper list (0-indexed).
     *
     * The contract requires a list<TaskExecution> with sequential integer keys.
     */
    public function testAllReturnsAListWithSequentialIntegerKeys(): void
    {
        $first = new TaskExecution('task.1', TaskStatus::Ran, new \DateTimeImmutable('2026-04-12T14:30:00+00:00'));
        $second = new TaskExecution('task.2', TaskStatus::Skipped, new \DateTimeImmutable('2026-04-12T15:00:00+00:00'));

        $this->storage->save($first);
        $this->storage->save($second);

        $all = $this->storage->all();

        // array_keys (not assertCount, which would let PHPStan fold the length) proves
        // the result is re-indexed: without array_values() the keys are the internal
        // task-id keys, not 0..n-1.
        self::assertSame([0, 1], \array_keys($all), 'all() must return a list with sequential integer keys from 0.');
    }

    protected function createStorage(): TaskStorageInterface
    {
        return $this->storage;
    }
}
