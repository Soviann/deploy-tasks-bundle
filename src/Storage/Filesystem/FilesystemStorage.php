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
use Symfony\Component\Filesystem\Path;
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

    /**
     * Directory names conventionally served as a public web root, matched per
     * path segment (case-insensitive). Checked only at/below the project dir
     * when one is known: segments above it (e.g. the "html" in
     * "/var/www/html/<app>") name the server's deploy root, not the app's
     * docroot.
     */
    private const PUBLIC_ROOT_SEGMENTS = ['htdocs', 'html', 'httpdocs', 'pub', 'public', 'public_html', 'web', 'wwwroot'];

    private readonly Filesystem $fs;

    /**
     * @param string      $storagePath Directory where task JSON files are stored
     * @param string|null $projectDir  Host project directory (kernel.project_dir); scopes the
     *                                 web-root guard to the storage path portion inside it
     *
     * @throws StorageException When the storage path is under a public web-root directory
     */
    public function __construct(
        private readonly string $storagePath,
        ?string $projectDir = null,
    ) {
        $this->fs = new Filesystem();

        if ($this->isUnderPublicWebRoot($storagePath, $projectDir)) {
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

            try {
                $this->fs->dumpFile($path, $json);
            } catch (IOException $e) {
                throw new StorageException(\sprintf('Failed to write storage file "%s".', $path), 0, $e);
            }

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
     * Records come back in directory-listing order, which is filesystem-dependent —
     * effectively unordered, matching the interface's no-ordering guarantee.
     *
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
     * Web-root guard — a defense-in-depth layer over the primary protection
     * (the 0700 directory / 0600 record modes applied on every save).
     *
     * Both paths are canonicalized with symlinks resolved, so a storage path
     * that only reaches the docroot through a symlink is still caught. When the
     * storage dir sits inside the project dir, only the project-relative
     * segments are inspected: segments above the project dir describe the
     * server's deploy layout (e.g. "/var/www/html"), not the app's public dir.
     * Without a known project dir, or for a storage dir outside it, every
     * segment of the resolved path is checked — conservative, since the guard
     * cannot tell a deploy root from a docroot there.
     */
    private function isUnderPublicWebRoot(string $storagePath, ?string $projectDir): bool
    {
        $resolvedStorage = $this->canonicalizeResolvingSymlinks($storagePath);
        $scope = $resolvedStorage;

        if (null !== $projectDir) {
            $resolvedProject = $this->canonicalizeResolvingSymlinks($projectDir);

            if (Path::isBasePath($resolvedProject, $resolvedStorage)) {
                $scope = Path::makeRelative($resolvedStorage, $resolvedProject);
            }
        }

        foreach (\explode('/', $scope) as $segment) {
            if (\in_array(\strtolower($segment), self::PUBLIC_ROOT_SEGMENTS, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Canonicalizes a path lexically, then resolves symlinks. realpath() fails
     * on paths that do not exist yet (the storage dir is only created on first
     * save), so symlink resolution applies to the deepest existing ancestor and
     * the non-existing remainder is re-appended verbatim — a not-yet-created
     * tail cannot contain symlinks. When nothing along the path exists, the
     * lexical canonical form is returned unchanged.
     */
    private function canonicalizeResolvingSymlinks(string $path): string
    {
        // Path::canonicalize() only rewrites backslashes on Windows; normalize
        // them on every OS so a Windows-style path stays checkable from tests
        // and tooling running on POSIX.
        $path = Path::canonicalize(\str_replace('\\', '/', $path));
        $remainder = '';
        $probe = $path;

        while (true) {
            $existingAncestor = \realpath($probe);

            if (false !== $existingAncestor) {
                return \rtrim(Path::canonicalize($existingAncestor), '/').$remainder;
            }

            $parent = Path::getDirectory($probe);

            if ('' === $parent || $parent === $probe) {
                return $path;
            }

            $remainder = '/'.\basename($probe).$remainder;
            $probe = $parent;
        }
    }

    /**
     * Lists the record files in the storage directory — all of them, or only
     * those belonging to $taskId (any slot). Empty when the directory does not
     * exist yet.
     *
     * Materialized eagerly (iterator_to_array()) rather than returned as a live
     * Finder/directory iterator: reset()/removeAll() unlink each file as they
     * consume this list, and deleting from a directory while it's still being
     * iterated is filesystem-dependent and can skip entries the iterator hasn't
     * visited yet.
     *
     * @return list<\SplFileInfo>
     */
    private function records(?string $taskId = null): array
    {
        if (!\is_dir($this->storagePath)) {
            return [];
        }

        $finder = (new Finder())
            ->files()
            ->in($this->storagePath)
            ->depth(0)
            ->name(self::RECORD_NAME_PATTERN);

        if (null !== $taskId) {
            $defaultSlotName = $taskId.'.json';
            $groupSlotPrefix = $taskId.'@';

            $finder = $finder->filter(
                static function (\SplFileInfo $file) use ($defaultSlotName, $groupSlotPrefix): bool {
                    $basename = $file->getBasename();

                    return $basename === $defaultSlotName || \str_starts_with($basename, $groupSlotPrefix);
                },
            );
        }

        return \iterator_to_array($finder, false);
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
        // Checked before the pattern (which also rejects '') so the empty string —
        // almost always a caller meaning "default slot" — gets the same actionable
        // message on every backend instead of a puzzling regex mismatch.
        if ('' === $group) {
            throw new \InvalidArgumentException('Group name must not be the empty string; use null to target the default group slot.');
        }

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

        foreach (['error', 'group'] as $key) {
            if (\array_key_exists($key, $decoded) && null !== $decoded[$key] && !\is_string($decoded[$key])) {
                throw new StorageException(\sprintf('Storage record "%s" key "%s" must be a string or null, got %s.', $sourceFile, $key, \get_debug_type($decoded[$key])));
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
         * @var array{id: string, status: string, executed_at: string, error?: string|null, group?: string|null} $data
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
            error: $data['error'] ?? null,
            group: $group,
        );
    }
}
