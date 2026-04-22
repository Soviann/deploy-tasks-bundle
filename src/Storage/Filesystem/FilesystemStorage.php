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
     */
    public function __construct(
        private readonly string $storagePath,
    ) {
        $this->fs = new Filesystem();

        if (\str_contains($storagePath, '/public/')) {
            \trigger_error(
                \sprintf('Storage path "%s" contains a /public/ segment, which is unsafe.', $storagePath),
                \E_USER_WARNING,
            );
        }
    }

    public function has(string $taskId, ?string $group = null): bool
    {
        return $this->fs->exists($this->filePath($taskId, $group));
    }

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

    public function save(TaskExecution $execution): void
    {
        $this->ensureDirectoryExists();

        $path = $this->filePath($execution->id, $execution->group);
        $json = \json_encode($this->toArray($execution), \JSON_THROW_ON_ERROR);

        // Native file_put_contents with LOCK_EX retained deliberately: advisory lock preserves
        // writer-vs-writer serialisation when callers don't install the optional symfony/lock
        // suggestion. Filesystem::dumpFile() is atomic but drops that guarantee.
        if (false === \file_put_contents($path, $json, \LOCK_EX)) {
            throw new StorageException(\sprintf('Failed to write storage file "%s".', $path));
        }
    }

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

    private function filePath(string $taskId, ?string $group): string
    {
        $this->validateTaskId($taskId);

        if (null === $group) {
            return $this->storagePath.'/'.$taskId.'.json';
        }

        $this->validateGroup($group);

        return $this->storagePath.'/'.$taskId.'@'.$group.'.json';
    }

    private function validateTaskId(string $taskId): void
    {
        if (1 !== \preg_match('/^[a-zA-Z0-9._-]+$/', $taskId)) {
            throw new \InvalidArgumentException(\sprintf('Invalid task ID "%s": must contain only alphanumeric characters, dots, hyphens, and underscores.', $taskId));
        }
    }

    private function validateGroup(string $group): void
    {
        if (1 !== \preg_match(AsDeployTask::GROUP_NAME_PATTERN, $group)) {
            throw new \InvalidArgumentException(\sprintf('Invalid group name "%s": must match %s.', $group, AsDeployTask::GROUP_NAME_PATTERN));
        }
    }

    private function ensureDirectoryExists(): void
    {
        try {
            $this->fs->mkdir($this->storagePath, 0755);
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
