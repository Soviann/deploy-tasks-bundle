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
    private readonly Filesystem $fs;

    /**
     * @param string $storagePath Directory where task JSON files are stored
     *
     * @throws \InvalidArgumentException When the storage path is under a "public" directory
     */
    public function __construct(
        private readonly string $storagePath,
    ) {
        $this->fs = new Filesystem();

        if (1 === \preg_match('#(^|/)public(/|$)#i', $storagePath)) {
            throw new \InvalidArgumentException(\sprintf('Refusing to use filesystem storage under a "public" path: "%s".', $storagePath));
        }
    }

    /**
     * @throws \InvalidArgumentException When the task id or group fails validation
     */
    public function has(string $taskId, ?string $group = null): bool
    {
        return $this->fs->exists($this->filePath($taskId, $group));
    }

    /**
     * @throws \InvalidArgumentException When the task id or group fails validation
     * @throws \JsonException            When the stored JSON file cannot be decoded
     * @throws StorageException          When the file cannot be read or contains an invalid record
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

        return $this->decode($contents);
    }

    /**
     * @throws \InvalidArgumentException When the task id or group fails validation
     * @throws \JsonException            When the execution payload cannot be encoded
     * @throws StorageException          When the storage directory or file cannot be written
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
            if (!@\chmod($path, 0600)) {
                throw StorageException::chmodFailed($path);
            }
        } finally {
            \flock($lockHandle, \LOCK_UN);
            \fclose($lockHandle);
        }
    }

    /**
     * @throws \InvalidArgumentException When the task id or group fails validation
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
     * @throws StorageException          When the storage path cannot be globbed or files cannot be removed
     */
    public function removeAll(string $taskId): void
    {
        $this->validateTaskId($taskId);

        $patterns = [
            $this->storagePath.'/'.$taskId.'.json',
            $this->storagePath.'/'.$taskId.'@*.json',
        ];

        foreach ($patterns as $pattern) {
            $files = \glob($pattern);

            if (false === $files) {
                throw new StorageException(\sprintf('Failed to glob storage path "%s".', $pattern));
            }

            foreach ($files as $file) {
                try {
                    $this->fs->remove($file);
                } catch (IOException $e) {
                    throw new StorageException(\sprintf('Failed to remove storage file "%s".', $file), 0, $e);
                }
            }
        }
    }

    /**
     * @return list<TaskExecution>
     *
     * @throws \InvalidArgumentException When the task id fails validation
     * @throws \JsonException            When a stored JSON file cannot be decoded
     * @throws StorageException          When the storage path cannot be globbed or a file cannot be read
     */
    public function findByTaskId(string $taskId): iterable
    {
        $this->validateTaskId($taskId);

        $patterns = [
            $this->storagePath.'/'.$taskId.'.json',
            $this->storagePath.'/'.$taskId.'@*.json',
        ];

        $executions = [];

        foreach ($patterns as $pattern) {
            $files = \glob($pattern);

            if (false === $files) {
                throw new StorageException(\sprintf('Failed to glob storage path "%s".', $pattern));
            }

            foreach ($files as $file) {
                $contents = \file_get_contents($file);

                if (false === $contents) {
                    throw new StorageException(\sprintf('Failed to read storage file "%s".', $file));
                }

                $executions[] = $this->decode($contents);
            }
        }

        return $executions;
    }

    /**
     * @return list<TaskExecution>
     *
     * @throws \JsonException   When a stored JSON file cannot be decoded
     * @throws StorageException When the storage path cannot be globbed or a file cannot be read
     */
    public function all(): array
    {
        $pattern = $this->storagePath.'/*.json';
        $files = \glob($pattern);

        if (false === $files) {
            throw new StorageException(\sprintf('Failed to glob storage path "%s".', $this->storagePath));
        }

        $executions = [];

        foreach ($files as $file) {
            $contents = \file_get_contents($file);

            if (false === $contents) {
                throw new StorageException(\sprintf('Failed to read storage file "%s".', $file));
            }

            $executions[] = $this->decode($contents);
        }

        return $executions;
    }

    /**
     * @throws StorageException When a storage file cannot be removed
     */
    public function reset(): void
    {
        $pattern = $this->storagePath.'/*.json';
        $files = \glob($pattern);

        if (false === $files || [] === $files) {
            return;
        }

        foreach ($files as $file) {
            try {
                $this->fs->remove($file);
            } catch (IOException $e) {
                throw new StorageException(\sprintf('Failed to remove storage file "%s".', $file), 0, $e);
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
     * @throws \JsonException
     * @throws StorageException
     */
    private function decode(string $json): TaskExecution
    {
        /** @var array{id: string, status: string, executed_at: string, error: string|null, group?: string|null} $data */
        $data = \json_decode($json, true, 512, \JSON_THROW_ON_ERROR);

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
