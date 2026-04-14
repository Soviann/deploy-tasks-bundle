<?php

declare(strict_types=1);

namespace Soviann\DeployTasks\Storage;

use Soviann\DeployTasks\Contract\TaskExecution;
use Soviann\DeployTasks\Contract\TaskStatus;
use Soviann\DeployTasks\Contract\TaskStorageInterface;
use Soviann\DeployTasks\Exception\StorageException;

/**
 * Filesystem-backed task storage — stores one JSON file per task execution.
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

    /**
     * Whether an execution record exists for the given task ID.
     */
    public function has(string $taskId): bool
    {
        return \file_exists($this->filePath($taskId));
    }

    /**
     * Returns the execution record for the given task ID, or null if not found.
     */
    public function get(string $taskId): ?TaskExecution
    {
        $path = $this->filePath($taskId);

        if (!\file_exists($path)) {
            return null;
        }

        $contents = \file_get_contents($path);

        if (false === $contents) {
            throw new StorageException(\sprintf('Failed to read storage file "%s".', $path));
        }

        return $this->decode($contents);
    }

    /**
     * Saves or updates an execution record.
     */
    public function save(TaskExecution $execution): void
    {
        $this->ensureDirectoryExists();

        $path = $this->filePath($execution->id);
        $json = \json_encode($this->toArray($execution), \JSON_THROW_ON_ERROR);

        if (false === \file_put_contents($path, $json, \LOCK_EX)) {
            throw new StorageException(\sprintf('Failed to write storage file "%s".', $path));
        }
    }

    /**
     * Removes the execution record for the given task ID.
     */
    public function remove(string $taskId): void
    {
        $path = $this->filePath($taskId);

        if (!\file_exists($path)) {
            return;
        }

        if (!\unlink($path)) {
            throw new StorageException(\sprintf('Failed to remove storage file "%s".', $path));
        }
    }

    /**
     * Returns all stored execution records, keyed by task ID.
     *
     * @return array<string, TaskExecution>
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

            $execution = $this->decode($contents);
            $executions[$execution->id] = $execution;
        }

        return $executions;
    }

    private function filePath(string $taskId): string
    {
        return $this->storagePath.'/'.$taskId.'.json';
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
     * @return array{id: string, status: string, executed_at: string, error: string|null}
     */
    private function toArray(TaskExecution $execution): array
    {
        return [
            'error' => $execution->error,
            'executed_at' => $execution->executedAt->format(\DateTimeInterface::ATOM),
            'id' => $execution->id,
            'status' => $execution->status->value,
        ];
    }

    private function decode(string $json): TaskExecution
    {
        /** @var array{id: string, status: string, executed_at: string, error: string|null} $data */
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
        );
    }
}
