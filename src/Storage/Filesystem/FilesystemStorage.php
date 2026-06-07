<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Storage\Filesystem;

use Soviann\DeployTasksBundle\Attribute\AsDeployTask;
use Soviann\DeployTasksBundle\Exception\StorageException;
use Soviann\DeployTasksBundle\Storage\TaskExecution;
use Soviann\DeployTasksBundle\Storage\TaskStatus;
use Soviann\DeployTasksBundle\Storage\TaskStorageInterface;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;

/**
 * Filesystem-backed task storage — stores one JSON file per (task id, group) pair.
 *
 * Filename layout:
 * - Default slot (group = null): `<id>.json`
 * - Named group slot: `<id>@<group>.json`. Both task IDs and group names are
 *   constrained to `[a-zA-Z0-9._-]+` (see AsDeployTask::GROUP_NAME_PATTERN),
 *   so the `@` separator is unambiguous and no character escaping is needed.
 *
 * @internal
 */
final class FilesystemStorage implements TaskStorageInterface
{
    /**
     * Regex pattern for valid record filenames: `<task-id>.json` or `<task-id>@<group>.json`.
     * Task IDs and group names must match `[a-zA-Z0-9._-]+`, so the `@` separator is unambiguous.
     */
    private const RECORD_NAME_PATTERN = '/^[a-zA-Z0-9._-]+(@[a-zA-Z0-9._-]+)?\.json$/';

    private readonly Filesystem $fs;

    /**
     * @param string $storagePath Directory where task JSON files are stored
     *
     * @throws StorageException When the storage path is under a public web-root directory
     */
    public function __construct(
        private readonly string $storagePath,
    ) {
        $this->fs = new Filesystem();

        $normalized = \str_replace('\\', '/', $storagePath);

        if (1 === \preg_match('#(^|/)(public|public_html|web|htdocs)(/|$)#i', $normalized)) {
            throw new StorageException(\sprintf('Refusing to store deploy-task records under a public web-root path: "%s". Move storage.filesystem.path outside the web-served directory.', $storagePath));
        }
    }

    /**
     * @throws \InvalidArgumentException When the task id fails validation
     * @throws \InvalidArgumentException When the group name fails validation
     */
    public function has(string $taskId, ?string $group = null): bool
    {
        return $this->fs->exists($this->filePath($taskId, $group));
    }

    /**
     * @throws \InvalidArgumentException When the task id fails validation
     * @throws \InvalidArgumentException When the group name fails validation
     * @throws \JsonException            When the stored JSON file cannot be decoded
     * @throws StorageException          When the file cannot be read
     * @throws StorageException          When the file contains an invalid record
     */
    public function get(string $taskId, ?string $group = null): ?TaskExecution
    {
        $path = $this->filePath($taskId, $group);

        if (!$this->fs->exists($path)) {
            return null;
        }

        $contents = \file_get_contents($path);

        if (false === $contents) {
            throw new StorageException(\sprintf('Failed to read storage file "%s".', $path));
        }

        return $this->decode($contents, $path);
    }

    /**
     * @throws \InvalidArgumentException When the task id fails validation
     * @throws \InvalidArgumentException When the group name fails validation
     * @throws \JsonException            When the execution payload cannot be encoded
     * @throws StorageException          When the storage directory cannot be created
     * @throws StorageException          When the storage file cannot be written
     */
    public function save(TaskExecution $execution): void
    {
        $this->ensureDirectoryExists();

        $path = $this->filePath($execution->id, $execution->group);
        $json = \json_encode($this->toArray($execution), \JSON_THROW_ON_ERROR);

        // Sidecar lockfile serialises concurrent writers; Filesystem::dumpFile() provides
        // atomic visibility to readers via a temp-file + rename on POSIX.
        // Do NOT additionally LOCK_EX the destination — that defeats the rename atomicity.
        $lockPath = $path.'.lock';
        $lockHandle = @\fopen($lockPath, 'c');

        if (false === $lockHandle) {
            throw StorageException::lockUnavailable($lockPath);
        }

        try {
            if (!\flock($lockHandle, \LOCK_EX)) {
                throw StorageException::lockUnavailable($lockPath);
            }

            (new Filesystem())->dumpFile($path, $json);

            // Deploy-task payloads can carry error messages, DSN fragments, or other sensitive
            // context; restrict reads to the owning user so unrelated accounts on the host
            // can't inspect them.
            if (false === @\chmod($path, 0600)) {
                throw StorageException::chmodFailedOnRecord($path);
            }
        } finally {
            \flock($lockHandle, \LOCK_UN);
            \fclose($lockHandle);
        }
    }

    /**
     * @throws \InvalidArgumentException When the task id fails validation
     * @throws \InvalidArgumentException When the group name fails validation
     * @throws StorageException          When the storage file cannot be removed
     */
    public function remove(string $taskId, ?string $group = null): void
    {
        $path = $this->filePath($taskId, $group);

        if (!$this->fs->exists($path)) {
            return;
        }

        try {
            $this->fs->remove($path);
        } catch (IOException $e) {
            throw new StorageException(\sprintf('Failed to remove storage file "%s".', $path), 0, $e);
        }
    }

    /**
     * @throws \InvalidArgumentException When the task id fails validation
     * @throws StorageException          When files cannot be removed
     */
    public function removeAll(string $taskId): void
    {
        $this->validateTaskId($taskId);

        if (!\is_dir($this->storagePath)) {
            return;
        }

        $prefix = $taskId.'.json';
        $prefixAt = $taskId.'@';

        $finder = (new Finder())
            ->files()
            ->in($this->storagePath)
            ->depth(0)
            ->name(self::RECORD_NAME_PATTERN)
            ->filter(static function (\SplFileInfo $file) use ($prefix, $prefixAt): bool {
                $basename = $file->getBasename();

                return $basename === $prefix || \str_starts_with($basename, $prefixAt);
            });

        foreach ($finder as $file) {
            try {
                $this->fs->remove($file->getPathname());
            } catch (IOException $e) {
                throw new StorageException(\sprintf('Failed to remove storage file "%s".', $file->getPathname()), 0, $e);
            }
        }
    }

    /**
     * @return list<TaskExecution>
     *
     * @throws \InvalidArgumentException When the task id fails validation
     * @throws \JsonException            When a stored JSON file cannot be decoded
     * @throws StorageException          When a file cannot be read
     */
    public function findByTaskId(string $taskId): array
    {
        $this->validateTaskId($taskId);

        if (!\is_dir($this->storagePath)) {
            return [];
        }

        $prefix = $taskId.'.json';
        $prefixAt = $taskId.'@';

        $finder = (new Finder())
            ->files()
            ->in($this->storagePath)
            ->depth(0)
            ->name(self::RECORD_NAME_PATTERN)
            ->filter(static function (\SplFileInfo $file) use ($prefix, $prefixAt): bool {
                $basename = $file->getBasename();

                return $basename === $prefix || \str_starts_with($basename, $prefixAt);
            });

        $executions = [];

        foreach ($finder as $file) {
            $contents = \file_get_contents($file->getPathname());

            if (false === $contents) {
                throw new StorageException(\sprintf('Failed to read storage file "%s".', $file->getPathname()));
            }

            $executions[] = $this->decode($contents, $file->getPathname());
        }

        return $executions;
    }

    /**
     * @return list<TaskExecution>
     *
     * @throws \JsonException   When a stored JSON file cannot be decoded
     * @throws StorageException When a file cannot be read
     */
    public function all(): array
    {
        if (!\is_dir($this->storagePath)) {
            return [];
        }

        $finder = (new Finder())
            ->files()
            ->in($this->storagePath)
            ->depth(0)
            ->name(self::RECORD_NAME_PATTERN);

        $executions = [];

        foreach ($finder as $file) {
            $contents = \file_get_contents($file->getPathname());

            if (false === $contents) {
                throw new StorageException(\sprintf('Failed to read storage file "%s".', $file->getPathname()));
            }

            $executions[] = $this->decode($contents, $file->getPathname());
        }

        return $executions;
    }

    /**
     * @throws StorageException When a storage file cannot be removed
     */
    public function reset(): void
    {
        if (!\is_dir($this->storagePath)) {
            return;
        }

        $finder = (new Finder())
            ->files()
            ->in($this->storagePath)
            ->depth(0)
            ->name(self::RECORD_NAME_PATTERN);

        foreach ($finder as $file) {
            try {
                $this->fs->remove($file->getPathname());
            } catch (IOException $e) {
                throw new StorageException(\sprintf('Failed to remove storage file "%s".', $file->getPathname()), 0, $e);
            }
        }
    }

    /**
     * @throws \InvalidArgumentException
     */
    private function filePath(string $taskId, ?string $group): string
    {
        $this->validateTaskId($taskId);

        if (null === $group) {
            return $this->storagePath.'/'.$taskId.'.json';
        }

        $this->validateGroup($group);

        return $this->storagePath.'/'.$taskId.'@'.$group.'.json';
    }

    /**
     * @throws \InvalidArgumentException
     */
    private function validateTaskId(string $taskId): void
    {
        if (1 !== \preg_match('/^[a-zA-Z0-9._-]+$/', $taskId)) {
            throw new \InvalidArgumentException(\sprintf('Invalid task ID "%s": must contain only alphanumeric characters, dots, hyphens, and underscores.', $taskId));
        }
    }

    /**
     * @throws \InvalidArgumentException
     */
    private function validateGroup(string $group): void
    {
        if (1 !== \preg_match(AsDeployTask::GROUP_NAME_PATTERN, $group)) {
            throw new \InvalidArgumentException(\sprintf('Invalid group name "%s": must match %s.', $group, AsDeployTask::GROUP_NAME_PATTERN));
        }
    }

    /**
     * @throws StorageException
     */
    private function ensureDirectoryExists(): void
    {
        try {
            $this->fs->mkdir($this->storagePath, 0700);
        } catch (IOException $e) {
            throw new StorageException(\sprintf('Failed to create storage directory "%s".', $this->storagePath), 0, $e);
        }

        // Normalize mode even for pre-existing dirs — mkdir is a no-op if the dir exists.
        if (false === @\chmod($this->storagePath, 0700)) {
            throw StorageException::chmodFailedOnDirectory($this->storagePath);
        }
    }

    /**
     * @return array{id: string, status: string, executed_at: string, error: string|null, group: string|null}
     */
    private function toArray(TaskExecution $execution): array
    {
        return [
            'error' => $execution->error,
            'executed_at' => $execution->executedAt->format(\DateTimeInterface::ATOM),
            'group' => $execution->group,
            'id' => $execution->id,
            'status' => $execution->status->value,
        ];
    }

    /**
     * @param array<string, mixed> $decoded
     *
     * @throws StorageException
     */
    private function assertRecordShape(array $decoded, string $sourceFile): void
    {
        foreach (['id', 'status', 'executed_at'] as $key) {
            if (!\array_key_exists($key, $decoded)) {
                throw new StorageException(\sprintf('Storage record "%s" is missing required key "%s".', $sourceFile, $key));
            }

            if (!\is_string($decoded[$key])) {
                throw new StorageException(\sprintf('Storage record "%s" key "%s" must be a string, got %s.', $sourceFile, $key, \get_debug_type($decoded[$key])));
            }
        }
    }

    /**
     * @throws \JsonException
     * @throws StorageException
     */
    private function decode(string $json, string $sourceFile): TaskExecution
    {
        /** @var array<string, mixed> $data */
        $data = \json_decode($json, true, 512, \JSON_THROW_ON_ERROR);

        $this->assertRecordShape($data, $sourceFile);

        /** @var array{id: string, status: string, executed_at: string, error: string|null, group?: string|null} $data */
        $executedAt = \DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $data['executed_at']);

        if (false === $executedAt) {
            throw new StorageException(\sprintf('Invalid executed_at value "%s" in storage file.', $data['executed_at']));
        }

        $group = $data['group'] ?? null;

        try {
            $status = TaskStatus::from($data['status']);
        } catch (\ValueError $e) {
            throw StorageException::corruptedRow($data['id'], $group, $data['status'], $e);
        }

        return new TaskExecution(
            id: $data['id'],
            status: $status,
            executedAt: $executedAt,
            error: $data['error'],
            group: $group,
        );
    }
}
