<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Tests\Unit\Storage;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Soviann\DeployTasksBundle\Exception\StorageException;
use Soviann\DeployTasksBundle\Storage\Filesystem\FilesystemStorage;
use Soviann\DeployTasksBundle\Storage\TaskExecution;
use Soviann\DeployTasksBundle\Storage\TaskStatus;

#[CoversClass(FilesystemStorage::class)]
final class FilesystemStorageTest extends TestCase
{
    private string $storagePath;
    private FilesystemStorage $storage;

    protected function setUp(): void
    {
        $this->storagePath = \sys_get_temp_dir().'/deploy-tasks-test-'.\uniqid();
        $this->storage = new FilesystemStorage($this->storagePath);
    }

    protected function tearDown(): void
    {
        if (\is_dir($this->storagePath)) {
            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->storagePath, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($files as $file) {
                \assert($file instanceof \SplFileInfo);
                $file->isDir() ? \rmdir($file->getPathname()) : \unlink($file->getPathname());
            }
            \rmdir($this->storagePath);
        }
    }

    public function testDirectoryCreatedWithOwnerOnlyPermissions(): void
    {
        if ('/' !== \DIRECTORY_SEPARATOR) {
            self::markTestSkipped('POSIX file permissions not enforced on non-Unix systems.');
        }

        $this->storage->save(new TaskExecution('task.1', TaskStatus::Ran, new \DateTimeImmutable()));

        self::assertSame(0700, \fileperms($this->storagePath) & 0777);
    }

    public function testStateFilePersistedWithOwnerOnlyPermissions(): void
    {
        if ('/' !== \DIRECTORY_SEPARATOR) {
            self::markTestSkipped('POSIX file permissions not enforced on non-Unix systems.');
        }

        $this->storage->save(new TaskExecution('task.1', TaskStatus::Ran, new \DateTimeImmutable()));

        self::assertSame(0600, \fileperms($this->storagePath.'/task.1.json') & 0777);
    }

    public function testDirectoryNotCreatedOnConstruct(): void
    {
        self::assertDirectoryDoesNotExist($this->storagePath);
    }

    public function testDirectoryCreatedOnFirstSave(): void
    {
        $execution = new TaskExecution('task.1', TaskStatus::Ran, new \DateTimeImmutable('2026-04-12T14:30:00+00:00'));

        $this->storage->save($execution);

        self::assertDirectoryExists($this->storagePath);
    }

    public function testSaveAndRetrieve(): void
    {
        $execution = new TaskExecution('task.1', TaskStatus::Ran, new \DateTimeImmutable('2026-04-12T14:30:00+00:00'));

        $this->storage->save($execution);

        self::assertTrue($this->storage->has('task.1'));

        $retrieved = $this->storage->get('task.1');

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
        $first = new TaskExecution('task.1', TaskStatus::Ran, new \DateTimeImmutable('2026-04-12T14:30:00+00:00'));
        $second = new TaskExecution('task.1', TaskStatus::Failed, new \DateTimeImmutable('2026-04-12T15:00:00+00:00'));

        $this->storage->save($first);
        $this->storage->save($second);

        $retrieved = $this->storage->get('task.1');

        self::assertNotNull($retrieved);
        self::assertSame(TaskStatus::Failed, $retrieved->status);
    }

    public function testGetReturnsNullForMissingTask(): void
    {
        self::assertNull($this->storage->get('task.missing'));
    }

    public function testHasReturnsFalseForMissingTask(): void
    {
        self::assertFalse($this->storage->has('task.missing'));
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

    public function testAllReturnsFlatList(): void
    {
        $this->storage->save(new TaskExecution('task.1', TaskStatus::Ran, new \DateTimeImmutable('2026-04-12T14:30:00+00:00')));
        $this->storage->save(new TaskExecution('task.2', TaskStatus::Skipped, new \DateTimeImmutable('2026-04-12T15:00:00+00:00')));
        $this->storage->save(new TaskExecution('task.2', TaskStatus::Ran, new \DateTimeImmutable('2026-04-12T15:30:00+00:00'), null, 'predeploy'));

        $all = $this->storage->all();

        self::assertCount(3, $all);

        $ids = \array_map(static fn (TaskExecution $e): string => $e->id.'@'.($e->group ?? ''), $all);
        \sort($ids);

        self::assertSame(['task.1@', 'task.2@', 'task.2@predeploy'], $ids);
    }

    public function testSaveAndGetWithGroup(): void
    {
        $execution = new TaskExecution('task.1', TaskStatus::Ran, new \DateTimeImmutable('2026-04-12T14:30:00+00:00'), null, 'predeploy');

        $this->storage->save($execution);

        $retrieved = $this->storage->get('task.1', 'predeploy');

        self::assertNotNull($retrieved);
        self::assertSame('task.1', $retrieved->id);
        self::assertSame('predeploy', $retrieved->group);
        self::assertNull($this->storage->get('task.1'));
        self::assertNull($this->storage->get('task.1', 'postdeploy'));
    }

    public function testHasIsScopedByGroup(): void
    {
        $this->storage->save(new TaskExecution('task.1', TaskStatus::Ran, new \DateTimeImmutable(), null, 'predeploy'));

        self::assertTrue($this->storage->has('task.1', 'predeploy'));
        self::assertFalse($this->storage->has('task.1'));
        self::assertFalse($this->storage->has('task.1', 'postdeploy'));
    }

    public function testRemoveIsScopedByGroup(): void
    {
        $this->storage->save(new TaskExecution('task.1', TaskStatus::Ran, new \DateTimeImmutable(), null, 'predeploy'));
        $this->storage->save(new TaskExecution('task.1', TaskStatus::Ran, new \DateTimeImmutable(), null, 'postdeploy'));

        $this->storage->remove('task.1', 'predeploy');

        self::assertFalse($this->storage->has('task.1', 'predeploy'));
        self::assertTrue($this->storage->has('task.1', 'postdeploy'));
    }

    public function testRemoveAllDeletesEverySlot(): void
    {
        $this->storage->save(new TaskExecution('task.1', TaskStatus::Ran, new \DateTimeImmutable()));
        $this->storage->save(new TaskExecution('task.1', TaskStatus::Ran, new \DateTimeImmutable(), null, 'predeploy'));
        $this->storage->save(new TaskExecution('task.1', TaskStatus::Ran, new \DateTimeImmutable(), null, 'postdeploy'));
        $this->storage->save(new TaskExecution('task.2', TaskStatus::Ran, new \DateTimeImmutable()));

        $this->storage->removeAll('task.1');

        self::assertFalse($this->storage->has('task.1'));
        self::assertFalse($this->storage->has('task.1', 'predeploy'));
        self::assertFalse($this->storage->has('task.1', 'postdeploy'));
        self::assertTrue($this->storage->has('task.2'));
    }

    public function testAllEmptyWhenDirectoryDoesNotExist(): void
    {
        self::assertSame([], $this->storage->all());
    }

    public function testReset(): void
    {
        $this->storage->save(new TaskExecution('task.1', TaskStatus::Ran, new \DateTimeImmutable()));
        $this->storage->save(new TaskExecution('task.2', TaskStatus::Skipped, new \DateTimeImmutable()));

        $this->storage->reset();

        self::assertSame([], $this->storage->all());
        self::assertDirectoryExists($this->storagePath);
    }

    public function testResetWhenDirectoryDoesNotExist(): void
    {
        $this->storage->reset();

        self::assertSame([], $this->storage->all());
    }

    public function testFindByTaskIdReturnsEverySlot(): void
    {
        $default = new TaskExecution('task.1', TaskStatus::Ran, new \DateTimeImmutable('2026-04-12T14:30:00+00:00'));
        $pre = new TaskExecution('task.1', TaskStatus::Ran, new \DateTimeImmutable('2026-04-12T14:35:00+00:00'), null, 'predeploy');
        $post = new TaskExecution('task.1', TaskStatus::Skipped, new \DateTimeImmutable('2026-04-12T14:40:00+00:00'), null, 'postdeploy');
        $other = new TaskExecution('task.2', TaskStatus::Ran, new \DateTimeImmutable('2026-04-12T14:50:00+00:00'));

        $this->storage->save($default);
        $this->storage->save($pre);
        $this->storage->save($post);
        $this->storage->save($other);

        $matches = [...$this->storage->findByTaskId('task.1')];
        $ids = \array_map(static fn (TaskExecution $e): string => $e->id, $matches);
        $groups = \array_map(static fn (TaskExecution $e): ?string => $e->group, $matches);

        self::assertCount(3, $matches);
        self::assertSame(['task.1', 'task.1', 'task.1'], $ids);
        self::assertEqualsCanonicalizing([null, 'predeploy', 'postdeploy'], $groups);
    }

    public function testFindByTaskIdReturnsSingleSlotWhenOnlyDefaultStored(): void
    {
        $execution = new TaskExecution('task.1', TaskStatus::Ran, new \DateTimeImmutable('2026-04-12T14:30:00+00:00'));

        $this->storage->save($execution);

        $matches = [...$this->storage->findByTaskId('task.1')];

        self::assertCount(1, $matches);
        self::assertSame('task.1', $matches[0]->id);
        self::assertNull($matches[0]->group);
    }

    public function testFindByTaskIdUnknownIdReturnsEmpty(): void
    {
        $this->storage->save(new TaskExecution('task.1', TaskStatus::Ran, new \DateTimeImmutable()));

        self::assertSame([], [...$this->storage->findByTaskId('task.missing')]);
    }

    public function testFindByTaskIdValidatesTaskId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Invalid task ID/');

        [...$this->storage->findByTaskId('../../etc/passwd')];
    }

    public function testRejectsPathTraversalInTaskId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Invalid task ID/');

        $this->storage->has('../../etc/passwd');
    }

    public function testRejectsSlashInTaskId(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->storage->has('some/task');
    }

    public function testAcceptsValidTaskIdCharacters(): void
    {
        // Should not throw — dots, hyphens, underscores are allowed
        self::assertFalse($this->storage->has('task.seed_categories-v2'));
    }

    /**
     * @return iterable<string, array{0: string, 1: bool}>
     */
    public static function publicPathProvider(): iterable
    {
        yield 'mid-path public segment' => ['/var/www/html/public/deploy-tasks', true];
        yield 'public as last segment' => ['/var/public', true];
        yield 'public as final directory (no trailing slash)' => ['/srv/web/public', true];
        yield 'uppercase PUBLIC segment' => ['/PUBLIC/state', true];
        yield 'mixed-case Public segment' => ['/srv/Public/state', true];
        yield 'substring my-public is safe' => ['/var/my-public/state', false];
        yield 'substring public-static is safe' => ['/public-static/state', false];
        yield 'substring publication is safe' => ['/var/publications/state', false];
    }

    #[DataProvider('publicPathProvider')]
    public function testPublicPathRejection(string $path, bool $shouldThrow): void
    {
        if ($shouldThrow) {
            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessageMatches('/Refusing to use filesystem storage under a "public" path/');
        } else {
            $this->expectNotToPerformAssertions();
        }

        new FilesystemStorage($path);
    }

    public function testCorruptJsonThrowsJsonException(): void
    {
        \mkdir($this->storagePath, 0755, true);
        \file_put_contents($this->storagePath.'/task.corrupt.json', 'not-valid-json');

        $this->expectException(\JsonException::class);

        $this->storage->get('task.corrupt');
    }

    public function testInvalidDateThrowsStorageException(): void
    {
        \mkdir($this->storagePath, 0755, true);
        \file_put_contents(
            $this->storagePath.'/task.baddate.json',
            \json_encode(['id' => 'task.baddate', 'status' => 'ran', 'executed_at' => 'not-a-date', 'error' => null]),
        );

        $this->expectException(StorageException::class);
        $this->expectExceptionMessageMatches('/Invalid executed_at/');

        $this->storage->get('task.baddate');
    }

    public function testCorruptedStatusThrowsStorageException(): void
    {
        \mkdir($this->storagePath, 0755, true);
        \file_put_contents(
            $this->storagePath.'/task.badstatus.json',
            \json_encode([
                'id' => 'task.badstatus',
                'status' => 'bogus',
                'executed_at' => '2026-04-12T14:30:00+00:00',
                'error' => null,
            ]),
        );

        $this->expectException(StorageException::class);
        $this->expectExceptionMessageMatches('/Corrupted storage row.*bogus/');

        try {
            $this->storage->get('task.badstatus');
        } catch (StorageException $e) {
            self::assertInstanceOf(\ValueError::class, $e->getPrevious());

            throw $e;
        }
    }

    public function testGroupSlugCollisionDetected(): void
    {
        $this->storage->save(new TaskExecution('task.1', TaskStatus::Ran, new \DateTimeImmutable('2026-04-12T14:30:00+00:00'), null, 'a-b'));
        $this->storage->save(new TaskExecution('task.1', TaskStatus::Failed, new \DateTimeImmutable('2026-04-12T15:00:00+00:00'), null, 'a_b'));

        $first = $this->storage->get('task.1', 'a-b');
        $second = $this->storage->get('task.1', 'a_b');

        self::assertNotNull($first);
        self::assertNotNull($second);
        self::assertSame(TaskStatus::Ran, $first->status);
        self::assertSame(TaskStatus::Failed, $second->status);
    }

    public function testRemoveAllValidatesTaskId(): void
    {
        // Kills MethodCallRemoval on the `$this->validateTaskId()` call at line 88 —
        // removeAll must reject path-traversal ids before globbing.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Invalid task ID/');

        $this->storage->removeAll('../../etc/passwd');
    }

    public function testSaveRejectsGroupNamesContainingPathTraversal(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Invalid group name/');

        $this->storage->save(new TaskExecution(
            'task.1',
            TaskStatus::Ran,
            new \DateTimeImmutable('2026-04-12T14:30:00+00:00'),
            null,
            '../../../etc/passwd',
        ));
    }

    public function testGetRejectsGroupNamesContainingSlash(): void
    {
        // The pre-2.3 slugifier mapped both `a/b` and `a_b` to `a_b`, colliding at the
        // file layer. Groups containing `/` are now rejected at the storage boundary,
        // so collisions cannot happen by construction.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Invalid group name/');

        $this->storage->get('task.1', 'a/b');
    }

    public function testSlashAndUnderscoreGroupsNoLongerCollideBecauseSlashIsRejected(): void
    {
        $this->storage->save(new TaskExecution(
            'task.1',
            TaskStatus::Ran,
            new \DateTimeImmutable('2026-04-12T14:30:00+00:00'),
            null,
            'a_b',
        ));

        self::assertTrue($this->storage->has('task.1', 'a_b'));

        $this->expectException(\InvalidArgumentException::class);
        $this->storage->has('task.1', 'a/b');
    }
}
