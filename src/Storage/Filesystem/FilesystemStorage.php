<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Storage\Filesystem;

use Soviann\DeployTasksBundle\Exception\StorageException;
use Soviann\DeployTasksBundle\Storage\TaskExecution;
use Soviann\DeployTasksBundle\Storage\TaskStatus;
use Soviann\DeployTasksBundle\Storage\TaskStorageInterface;

/**
 * Filesystem-backed task storage — stores one JSON file per (task id, group) pair.
 *
 * Filename layout:
 * - Default slot (group = null): `<id>.json`
 * - Named group slot: `<id>@<slug>.json` where `<slug>` is the group name with
 *   non-filesystem-safe characters replaced by `_`. The `@` separator is
 *   unambiguous because task IDs are restricted to `[a-zA-Z0-9._-]`.
 *
 * @internal
 */
final class FilesystemStorage implements TaskStorageInterface
{
    /**
     * @param string $storagePath Directory where task JSON files are stored
     */
    public function __construct(
        private readonly string $storagePath,
    ) {
        if (\str_contains($storagePath, '/public/')) {
            \trigger_error(
                \sprintf('Storage path "%s" contains a /public/ segment, which is unsafe.', $storagePath),
                \E_USER_WARNING,
            );
        }
    }

    public function has(string $taskId, ?string $group = null): bool
    {
        return \file_exists($this->filePath($taskId, $group));
    }

    public function get(string $taskId, ?string $group = null): ?TaskExecution
    {
        $path = $this->filePath($taskId, $group);

        if (!\file_exists($path)) {
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

        if (false === \file_put_contents($path, $json, \LOCK_EX)) {
            throw new StorageException(\sprintf('Failed to write storage file "%s".', $path));
        }
    }

    public function remove(string $taskId, ?string $group = null): void
    {
        $path = $this->filePath($taskId, $group);

        if (!\file_exists($path)) {
            return;
        }

        if (!\unlink($path)) {
            throw new StorageException(\sprintf('Failed to remove storage file "%s".', $path));
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
                if (!\unlink($file)) {
                    throw new StorageException(\sprintf('Failed to remove storage file "%s".', $file));
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
            if (!\unlink($file)) {
                throw new StorageException(\sprintf('Failed to remove storage file "%s".', $file));
            }
        }
    }

    private function filePath(string $taskId, ?string $group): string
    {
        $this->validateTaskId($taskId);

        if (null === $group) {
            return $this->storagePath.'/'.$taskId.'.json';
        }

        return $this->storagePath.'/'.$taskId.'@'.self::slugifyGroup($group).'.json';
    }

    private function validateTaskId(string $taskId): void
    {
        if (1 !== \preg_match('/^[a-zA-Z0-9._-]+$/', $taskId)) {
            throw new \InvalidArgumentException(\sprintf('Invalid task ID "%s": must contain only alphanumeric characters, dots, hyphens, and underscores.', $taskId));
        }
    }

    private static function slugifyGroup(string $group): string
    {
        return (string) \preg_replace('/[^a-z0-9._-]+/i', '_', $group);
    }

    private function ensureDirectoryExists(): void
    {
        if (\is_dir($this->storagePath)) {
            return;
        }

        if (!\mkdir($this->storagePath, 0755, true) && !\is_dir($this->storagePath)) {
            throw new StorageException(\sprintf('Failed to create storage directory "%s".', $this->storagePath));
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

        return new TaskExecution(
            id: $data['id'],
            status: TaskStatus::from($data['status']),
            executedAt: $executedAt,
            error: $data['error'],
            group: $data['group'] ?? null,
        );
    }
}
