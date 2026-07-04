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
     * Built from the shared identifier charset (AsDeployTask::TASK_ID_PATTERN /
     * GROUP_NAME_PATTERN) so widening the charset there cannot orphan existing records,
     * and the `@` separator stays unambiguous.
     */
    private const RECORD_NAME_PATTERN = '/^'.AsDeployTask::IDENTIFIER_CHAR.'+(@'.AsDeployTask::IDENTIFIER_CHAR.'+)?\.json$/';

    /**
     * ext4/APFS/NTFS cap file names at 255 bytes. Enforced in filePath() so an
     * over-long id fails during the pending check — before the task has run —
     * instead of in dumpFile() after its side effects were applied (which would
     * leave the task unrecorded and re-run it on the next deploy).
     */
    private const MAX_RECORD_FILENAME_BYTES = 255;

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

        if (1 === \preg_match('#(^|/)(public|public_html|web|html|htdocs|wwwroot|httpdocs)(/|$)#i', $normalized)) {
            throw new StorageException(\sprintf('Refusing to store deploy-task records under a public web-root path: "%s". Move storage.filesystem.path outside the web-served directory.', $storagePath));
        }
    }

    /**
     * @throws \InvalidArgumentException When the task id fails validation
     * @throws \InvalidArgumentException When the group name fails validation
     * @throws StorageException          When the record file name exceeds the filesystem limit
     */
    public function has(string $taskId, ?string $group = null): bool
    {
        return $this->fs->exists($this->filePath($taskId, $group));
    }

    /**
     * @throws \InvalidArgumentException When the task id fails validation
     * @throws \InvalidArgumentException When the group name fails validation
     * @throws StorageException          When the record file name exceeds the filesystem limit
     * @throws StorageException          When the file cannot be read
     * @throws StorageException          When the file contains an invalid or undecodable record
     */
    public function get(string $taskId, ?string $group = null): ?TaskExecution
    {
        $path = $this->filePath($taskId, $group);

        if (!$this->fs->exists($path)) {
            return null;
        }

        return $this->readRecord($path);
    }

    /**
     * @throws \InvalidArgumentException When the task id fails validation
     * @throws \InvalidArgumentException When the group name fails validation
     * @throws StorageException          When the record file name exceeds the filesystem limit
     * @throws StorageException          When the execution payload cannot be encoded
     * @throws StorageException          When the storage directory cannot be created
     * @throws StorageException          When the storage file cannot be written
     */
    public function save(TaskExecution $execution): void
    {
        $this->ensureDirectoryExists();

        $path = $this->filePath($execution->id, $execution->group);

        try {
            $json = \json_encode($this->toArray($execution), \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new StorageException(\sprintf('Failed to encode execution record for task "%s": %s', $execution->id, $e->getMessage()), 0, $e);
        }

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

            $this->fs->dumpFile($path, $json);

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
     * @throws StorageException          When the record file name exceeds the filesystem limit
     * @throws StorageException          When the storage file cannot be removed
     */
    public function remove(string $taskId, ?string $group = null): void
    {
        $path = $this->filePath($taskId, $group);

        if (!$this->fs->exists($path)) {
            return;
        }

        $this->removeRecordFile($path);
    }

    /**
     * @throws \InvalidArgumentException When the task id fails validation
     * @throws StorageException          When files cannot be removed
     */
    public function removeAll(string $taskId): void
    {
        $this->validateTaskId($taskId);

        foreach ($this->records($taskId) as $file) {
            $this->removeRecordFile($file->getPathname());
        }
    }

    /**
     * @return list<TaskExecution>
     *
     * @throws \InvalidArgumentException When the task id fails validation
     * @throws StorageException          When a file cannot be read or decoded
     */
    public function findByTaskId(string $taskId): array
    {
        $this->validateTaskId($taskId);

        $executions = [];

        foreach ($this->records($taskId) as $file) {
            $executions[] = $this->readRecord($file->getPathname());
        }

        return $executions;
    }

    /**
     * @return list<TaskExecution>
     *
     * @throws StorageException When a file cannot be read or decoded
     */
    public function all(): array
    {
        $executions = [];

        foreach ($this->records() as $file) {
            $executions[] = $this->readRecord($file->getPathname());
        }

        return $executions;
    }

    /**
     * @throws StorageException When a storage file cannot be removed
     */
    public function reset(): void
    {
        foreach ($this->records() as $file) {
            $this->removeRecordFile($file->getPathname());
        }
    }

    /**
     * Iterates the record files in the storage directory — all of them, or only
     * those belonging to $taskId (any slot). Yields nothing when the directory
     * does not exist yet.
     *
     * @return iterable<\SplFileInfo>
     */
    private function records(?string $taskId = null): iterable
    {
        if (!\is_dir($this->storagePath)) {
            return [];
        }

        $finder = (new Finder())
            ->files()
            ->in($this->storagePath)
            ->depth(0)
            ->name(self::RECORD_NAME_PATTERN);

        if (null === $taskId) {
            return $finder;
        }

        $defaultSlotName = $taskId.'.json';
        $groupSlotPrefix = $taskId.'@';

        return $finder->filter(
            static function (\SplFileInfo $file) use ($defaultSlotName, $groupSlotPrefix): bool {
                $basename = $file->getBasename();

                return $basename === $defaultSlotName || \str_starts_with($basename, $groupSlotPrefix);
            },
        );
    }

    /**
     * @throws StorageException When the file cannot be removed
     */
    private function removeRecordFile(string $path): void
    {
        try {
            // Also drop the save-time lock sidecar; Filesystem::remove() ignores missing paths.
            $this->fs->remove([$path, $path.'.lock']);
        } catch (IOException $e) {
            throw new StorageException(\sprintf('Failed to remove storage file "%s".', $path), 0, $e);
        }
    }

    /**
     * @throws StorageException When the file cannot be read, decoded, or contains an invalid record
     */
    private function readRecord(string $path): TaskExecution
    {
        $contents = \file_get_contents($path);

        if (false === $contents) {
            throw new StorageException(\sprintf('Failed to read storage file "%s".', $path));
        }

        return $this->decode($contents, $path);
    }

    /**
     * @throws \InvalidArgumentException When the task id or group name fails validation
     * @throws StorageException          When the record file name exceeds the filesystem limit
     */
    private function filePath(string $taskId, ?string $group): string
    {
        $this->validateTaskId($taskId);

        if (null === $group) {
            $fileName = $taskId.'.json';
        } else {
            $this->validateGroup($group);
            $fileName = $taskId.'@'.$group.'.json';
        }

        if (\strlen($fileName) > self::MAX_RECORD_FILENAME_BYTES) {
            throw new StorageException(\sprintf('Record file name "%s" is %d bytes, exceeding the %d-byte filesystem limit. Shorten the task id%s.', $fileName, \strlen($fileName), self::MAX_RECORD_FILENAME_BYTES, null === $group ? '' : ' or group name'));
        }

        return $this->storagePath.'/'.$fileName;
    }

    /**
     * @throws \InvalidArgumentException
     */
    private function validateTaskId(string $taskId): void
    {
        if (1 !== \preg_match(AsDeployTask::TASK_ID_PATTERN, $taskId)) {
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
     * @throws StorageException
     */
    private function decode(string $json, string $sourceFile): TaskExecution
    {
        try {
            /** @var array<string, mixed> $data */
            $data = \json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new StorageException(\sprintf('Failed to decode storage file "%s": %s', $sourceFile, $e->getMessage()), 0, $e);
        }

        $this->assertRecordShape($data, $sourceFile);

        /**
         * @var array{id: string, status: string, executed_at: string, error: string|null, group?: string|null} $data
         */
        $executedAt = \DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $data['executed_at']);

        if (false === $executedAt) {
            throw new StorageException(\sprintf('Invalid executed_at value "%s" in storage file.', $data['executed_at']));
        }

        $group = $data['group'] ?? null;

        return new TaskExecution(
            id: $data['id'],
            status: TaskStatus::fromStored($data['status'], $data['id'], $group),
            executedAt: $executedAt,
            error: $data['error'],
            group: $group,
        );
    }
}
