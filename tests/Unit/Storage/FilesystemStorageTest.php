<?php

declare(strict_types=1);

namespace Soviann\DeployTasks\Tests\Unit\Storage;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Soviann\DeployTasks\Contract\TaskExecution;
use Soviann\DeployTasks\Contract\TaskStatus;
use Soviann\DeployTasks\Storage\FilesystemStorage;

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

    public function testAll(): void
    {
        $first = new TaskExecution('task.1', TaskStatus::Ran, new \DateTimeImmutable('2026-04-12T14:30:00+00:00'));
        $second = new TaskExecution('task.2', TaskStatus::Skipped, new \DateTimeImmutable('2026-04-12T15:00:00+00:00'));

        $this->storage->save($first);
        $this->storage->save($second);

        $all = $this->storage->all();

        self::assertCount(2, $all);
        self::assertArrayHasKey('task.1', $all);
        self::assertArrayHasKey('task.2', $all);
        self::assertSame(TaskStatus::Ran, $all['task.1']->status);
        self::assertSame(TaskStatus::Skipped, $all['task.2']->status);
    }

    public function testAllEmptyWhenDirectoryDoesNotExist(): void
    {
        self::assertSame([], $this->storage->all());
    }

    public function testPublicPathWarning(): void
    {
        $warningTriggered = false;
        \set_error_handler(
            static function (int $errno, string $errstr) use (&$warningTriggered): bool {
                if (\E_USER_WARNING === $errno && \str_contains($errstr, '/public/')) {
                    $warningTriggered = true;
                }

                return true;
            },
            \E_USER_WARNING,
        );

        try {
            new FilesystemStorage('/var/www/html/public/deploy-tasks');
        } finally {
            \restore_error_handler();
        }

        self::assertTrue($warningTriggered);
    }
}
