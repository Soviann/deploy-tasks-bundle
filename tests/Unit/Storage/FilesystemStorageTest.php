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
use Soviann\DeployTasksBundle\Tests\Support\FilesystemTestHelper;

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
        FilesystemTestHelper::cleanup($this->storagePath);
    }

    public function testDirectoryCreatedWithOwnerOnlyPermissions(): void
    {
        if ('/' !== \DIRECTORY_SEPARATOR) {
            self::markTestSkipped('POSIX file permissions not enforced on non-Unix systems.');
        }

        $this->storage->save(new TaskExecution('task.1', TaskStatus::Ran, new \DateTimeImmutable()));

        FilesystemTestHelper::assertPermissions($this->storagePath, 0o700);
    }

    public function testStateFilePersistedWithOwnerOnlyPermissions(): void
    {
        if ('/' !== \DIRECTORY_SEPARATOR) {
            self::markTestSkipped('POSIX file permissions not enforced on non-Unix systems.');
        }

        $this->storage->save(new TaskExecution('task.1', TaskStatus::Ran, new \DateTimeImmutable()));

        FilesystemTestHelper::assertPermissions($this->storagePath.'/task.1.json', 0o600);
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
            $this->expectException(StorageException::class);
            $this->expectExceptionMessageMatches('/Refusing to store deploy-task records under a public web-root path/');
        } else {
            $this->expectNotToPerformAssertions();
        }

        new FilesystemStorage($path);
    }

    public function testDecodeRaisesOnMissingKey(): void
    {
        \mkdir($this->storagePath, 0755, true);
        $filePath = $this->storagePath.'/task.empty.json';
        \file_put_contents($filePath, '{}');

        $this->expectException(StorageException::class);
        $this->expectExceptionMessageMatches('/missing required key "id"/');
        $this->expectExceptionMessageMatches('/task\.empty\.json/');

        $this->storage->get('task.empty');
    }

    public function testDecodeRaisesOnNonStringKey(): void
    {
        \mkdir($this->storagePath, 0755, true);
        $filePath = $this->storagePath.'/task.intid.json';
        \file_put_contents(
            $filePath,
            \json_encode(['id' => 123, 'status' => 'ok', 'executed_at' => '2026-01-01T00:00:00+00:00']),
        );

        $this->expectException(StorageException::class);
        $this->expectExceptionMessageMatches('/"id"/');
        $this->expectExceptionMessageMatches('/int/');

        $this->storage->get('task.intid');
    }

    public function testWindowsPublicPathRejectedAtConstruction(): void
    {
        $this->expectException(StorageException::class);
        $this->expectExceptionMessageMatches('/Refusing to store deploy-task records under a public web-root path/');

        new FilesystemStorage('C:\\app\\public\\deploy-tasks');
    }

    public function testPublicHtmlAndWebSegmentsRejected(): void
    {
        $paths = [
            '/srv/site/public_html/var',
            '/srv/site/web/var',
            '/srv/site/htdocs/var',
        ];

        foreach ($paths as $path) {
            try {
                new FilesystemStorage($path);
                self::fail(\sprintf('Expected StorageException for path "%s", but none was thrown.', $path));
            } catch (StorageException $e) {
                self::assertMatchesRegularExpression(
                    '/Refusing to store deploy-task records under a public web-root path/',
                    $e->getMessage(),
                    \sprintf('Wrong exception message for path "%s".', $path),
                );
            }
        }
    }

    public function testNonWebRootPathAccepted(): void
    {
        // Neither path contains a bare web-root segment; "republican" contains "public" as a substring
        // but must NOT be rejected because the anchored regex requires segment boundaries.
        $this->expectNotToPerformAssertions();

        new FilesystemStorage('/var/lib/deploy-tasks');
        new FilesystemStorage('/srv/republican-data/storage');
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

    /**
     * Spawns two writers and a parallel reader via pcntl_fork; asserts no zero-byte file or
     * truncated (non-decodable JSON) content is ever observed across at least 200 reader iterations.
     *
     * Skipped on non-POSIX systems (Windows) and when pcntl is unavailable (e.g. some CI runners).
     */
    public function testConcurrentWritesNeverYieldZeroBytes(): void
    {
        if (!\function_exists('pcntl_fork')) {
            self::markTestSkipped('pcntl required for the concurrency probe.');
        }

        if ('/' !== \DIRECTORY_SEPARATOR) {
            self::markTestSkipped('POSIX fork not available on non-Unix systems.');
        }

        // Seed a file so readers always have something to open.
        $this->storage->save(new TaskExecution('task.probe', TaskStatus::Ran, new \DateTimeImmutable()));

        $pids = [];

        for ($i = 0; $i < 2; ++$i) {
            $pid = \pcntl_fork();

            if (-1 === $pid) {
                self::fail('pcntl_fork failed — cannot run concurrency probe.');
            }

            if (0 === $pid) {
                // Child: writer loop — runs until SIGTERM from the parent.
                $status = 0 === $i % 2 ? TaskStatus::Ran : TaskStatus::Failed;

                // @phpstan-ignore-next-line
                while (true) {
                    $this->storage->save(new TaskExecution('task.probe', $status, new \DateTimeImmutable()));
                }
            }

            $pids[] = $pid;
        }

        // Parent: reader loop for ~200 ms.
        $deadline = \microtime(true) + 0.2;
        $iterations = 0;
        $zeroByteObserved = false;

        while (\microtime(true) < $deadline) {
            $path = $this->storagePath.'/task.probe.json';

            if (\file_exists($path)) {
                $contents = \file_get_contents($path);

                if (false === $contents) {
                    ++$iterations;

                    continue;
                }

                if ('' === $contents) {
                    $zeroByteObserved = true;

                    break;
                }

                // Try to decode — a half-written file would fail JSON parsing.
                try {
                    \json_decode($contents, true, 512, \JSON_THROW_ON_ERROR);
                } catch (\JsonException) {
                    // Truncated JSON = non-atomic write observed.
                    $zeroByteObserved = true;

                    break;
                }
            }

            ++$iterations;
        }

        // Kill writers.
        foreach ($pids as $pid) {
            \posix_kill($pid, \SIGTERM);
            \pcntl_waitpid($pid, $status);
        }

        self::assertGreaterThanOrEqual(200, $iterations, 'Reader completed fewer than 200 iterations in 200 ms.');
        self::assertFalse($zeroByteObserved, 'Concurrent reader observed a zero-byte or truncated JSON file.');
    }

    public function testStorageInitNormalizesPreExistingDirMode(): void
    {
        if ('/' !== \DIRECTORY_SEPARATOR) {
            self::markTestSkipped('POSIX file permissions not enforced on non-Unix systems.');
        }

        \mkdir($this->storagePath, 0755, true);

        $this->storage->save(new TaskExecution('task.1', TaskStatus::Ran, new \DateTimeImmutable()));

        FilesystemTestHelper::assertPermissions($this->storagePath, 0o700);
    }

    public function testForeignFilesAreSkippedByAll(): void
    {
        // Write a valid record
        $this->storage->save(new TaskExecution('task-a', TaskStatus::Ran, new \DateTimeImmutable('2026-04-12T14:30:00+00:00')));

        // Create foreign files that don't match the record pattern (wrong filename format)
        // Files without the task-id@group.json or task-id.json format should be skipped
        \file_put_contents($this->storagePath.'/notes.txt', '{"note": "handwritten"}');
        \file_put_contents($this->storagePath.'/.gitkeep', '');
        \file_put_contents($this->storagePath.'/README.md', '# Storage');

        // all() should only return the valid task, ignoring foreign files
        $all = $this->storage->all();

        self::assertCount(1, $all);
        self::assertSame('task-a', $all[0]->id);
    }

    public function testResetLeavesForeignFilesAlone(): void
    {
        // Write a valid record
        $this->storage->save(new TaskExecution('task-a', TaskStatus::Ran, new \DateTimeImmutable('2026-04-12T14:30:00+00:00')));

        // Create foreign files that don't match the record pattern
        \file_put_contents($this->storagePath.'/notes.txt', '{"note": "handwritten"}');
        \file_put_contents($this->storagePath.'/.gitkeep', '');

        // Reset should only delete records matching the pattern
        $this->storage->reset();

        // Valid record should be gone
        self::assertSame([], $this->storage->all());

        // Foreign files should still exist
        self::assertFileExists($this->storagePath.'/notes.txt');
        self::assertFileExists($this->storagePath.'/.gitkeep');
    }

    public function testStoragePathWithBracketsWorks(): void
    {
        // Create a storage path with glob metacharacters (brackets)
        $bracketPath = $this->storagePath.'/[staging]';
        $bracketStorage = new FilesystemStorage($bracketPath);

        // Save two records
        $bracketStorage->save(new TaskExecution('deploy-1', TaskStatus::Ran, new \DateTimeImmutable('2026-04-12T14:30:00+00:00')));
        $bracketStorage->save(new TaskExecution('deploy-2', TaskStatus::Skipped, new \DateTimeImmutable('2026-04-12T15:00:00+00:00')));

        // all() should return both records (glob() would silently return empty due to bracket matching)
        $all = $bracketStorage->all();

        self::assertCount(2, $all);
        $ids = \array_map(static fn (TaskExecution $e): string => $e->id, $all);
        \sort($ids);
        self::assertSame(['deploy-1', 'deploy-2'], $ids);

        // Cleanup the bracket path
        FilesystemTestHelper::cleanup($bracketPath);
    }

    /**
     * Kills ConcatOperandRemoval mutants on line 104 that drop either the $path prefix
     * or the '.lock' suffix of the lock-file name.
     *
     * Mutant A: `$lockPath = '.lock'`  → lock file is named '.lock' with no path prefix,
     *           landing in CWD — NOT in storagePath.
     * Mutant B: `$lockPath = $path`    → lock file IS the JSON file itself (no '.lock' suffix).
     *
     * After a successful save the lock sidecar must exist at exactly "$jsonPath.lock" — i.e.,
     * inside storagePath, named "<taskId>.json.lock".  Both mutants produce a different filename,
     * so asserting the exact expected path kills them.
     */
    public function testLockFileIsCoLocatedWithJsonFileAndHasLockSuffix(): void
    {
        $this->storage->save(new TaskExecution('task.lock-probe', TaskStatus::Ran, new \DateTimeImmutable()));

        $expectedJsonPath = $this->storagePath.'/task.lock-probe.json';
        $expectedLockPath = $expectedJsonPath.'.lock';

        // The lock sidecar is left on disk after save.
        self::assertFileExists($expectedLockPath, 'Lock file must be co-located with the JSON record at "<json>.lock".');

        // Mutant A creates a bare ".lock" file in CWD; assert no stray lock exists at the
        // storage-dir level (which is distinct from the per-record sidecar).
        self::assertFileDoesNotExist($this->storagePath.'/.lock', 'A stray bare ".lock" inside storagePath must not exist; the sidecar must include the full record filename.');
    }

    /**
     * Kills the ConcatOperandRemoval mutant on line 163 inside removeAll():
     * mutation replaces `$taskId.'@'` with `$taskId`, making str_starts_with()
     * match task IDs that merely start with $taskId (e.g. 'task.1' matches 'task.10').
     *
     * removeAll('task.1') must remove only task.1 slots and leave task.10 untouched.
     */
    public function testRemoveAllDoesNotMatchTaskIdPrefixOfAnother(): void
    {
        $this->storage->save(new TaskExecution('task.1', TaskStatus::Ran, new \DateTimeImmutable()));
        $this->storage->save(new TaskExecution('task.1', TaskStatus::Ran, new \DateTimeImmutable(), null, 'predeploy'));
        $this->storage->save(new TaskExecution('task.10', TaskStatus::Ran, new \DateTimeImmutable()));
        $this->storage->save(new TaskExecution('task.10', TaskStatus::Ran, new \DateTimeImmutable(), null, 'predeploy'));

        $this->storage->removeAll('task.1');

        self::assertFalse($this->storage->has('task.1'), 'task.1 default slot must be removed.');
        self::assertFalse($this->storage->has('task.1', 'predeploy'), 'task.1 predeploy slot must be removed.');
        self::assertTrue($this->storage->has('task.10'), 'task.10 default slot must NOT be removed by removeAll(task.1).');
        self::assertTrue($this->storage->has('task.10', 'predeploy'), 'task.10 predeploy slot must NOT be removed by removeAll(task.1).');
    }

    /**
     * Kills the ConcatOperandRemoval mutant on line 201 inside findByTaskId():
     * mutation replaces `$taskId.'@'` with `$taskId`, making str_starts_with()
     * also match files whose name merely starts with $taskId (e.g. 'task.10').
     *
     * findByTaskId('task.1') must return only task.1 records, not task.10.
     */
    public function testFindByTaskIdDoesNotMatchTaskIdPrefixOfAnother(): void
    {
        $e1 = new TaskExecution('task.1', TaskStatus::Ran, new \DateTimeImmutable('2026-04-12T14:30:00+00:00'));
        $e1pre = new TaskExecution('task.1', TaskStatus::Ran, new \DateTimeImmutable('2026-04-12T14:35:00+00:00'), null, 'predeploy');
        $e10 = new TaskExecution('task.10', TaskStatus::Ran, new \DateTimeImmutable('2026-04-12T14:40:00+00:00'));
        $e10pre = new TaskExecution('task.10', TaskStatus::Ran, new \DateTimeImmutable('2026-04-12T14:45:00+00:00'), null, 'predeploy');

        $this->storage->save($e1);
        $this->storage->save($e1pre);
        $this->storage->save($e10);
        $this->storage->save($e10pre);

        $matches = $this->storage->findByTaskId('task.1');

        self::assertCount(2, $matches, 'findByTaskId(task.1) must return exactly the task.1 slots.');

        $ids = \array_map(static fn (TaskExecution $e): string => $e->id, $matches);
        self::assertNotContains('task.10', $ids, 'findByTaskId(task.1) must not return task.10 records.');
        self::assertContains('task.1', $ids);
    }
}
