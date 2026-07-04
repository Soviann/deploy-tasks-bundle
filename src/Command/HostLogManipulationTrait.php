<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Command;

use Soviann\DeployTasksBundle\Attribute\AsDeployTask;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Shared read/append/rewrite helpers for commands that manipulate the host runner's
 * append-only completion log (`bin/deploy-tasks-host.sh`'s `.deploy-tasks-host.log`).
 *
 * Mirrors the runner's own exact-line semantics (`grep -Fxq`): one task id per line,
 * newline-terminated, no CRLF. Writes issued from PHP must stay bit-compatible with
 * the runner's own `printf '%s\n' "$id" >> "$STORAGE"` so the bash side's `grep -Fxq`
 * check keeps working after a PHP-issued skip/reset/rollup.
 *
 * @internal
 */
trait HostLogManipulationTrait
{
    /**
     * Same charset as AsDeployTask::TASK_ID_PATTERN, anchored with \z instead of $
     * so a trailing newline cannot sneak past the check. Every accepted id is safe
     * as a log line (single line, no control bytes), a path segment (no slashes,
     * no traversal), and terminal output.
     */
    private function isValidHostTaskId(string $id): bool
    {
        return 1 === \preg_match('/^'.AsDeployTask::IDENTIFIER_CHAR.'+\z/', $id);
    }

    /**
     * @return list<string> ids currently present in the log (exact lines, no trailing newline)
     */
    private function readHostLog(string $logPath): array
    {
        if (!\is_file($logPath)) {
            return [];
        }

        $lines = \file($logPath, \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES);

        return false !== $lines ? $lines : [];
    }

    /**
     * Exact full-line match, mirroring the runner's `grep -Fxq`.
     */
    private function hostLogHas(string $logPath, string $id): bool
    {
        return \in_array($id, $this->readHostLog($logPath), true);
    }

    /**
     * Appends a single id, matching the runner's own `printf '%s\n' "$id" >> "$STORAGE"`.
     */
    private function appendToHostLog(string $logPath, string $id): void
    {
        $this->appendManyToHostLog($logPath, [$id]);
    }

    /**
     * @param list<string> $ids
     */
    private function appendManyToHostLog(string $logPath, array $ids): void
    {
        if ([] === $ids) {
            return;
        }

        $chunk = '';
        foreach ($ids as $id) {
            $chunk .= $id."\n";
        }

        // The native warning is suppressed because the return value is checked
        // right below; the failure surfaces as an IOException instead.
        $bytes = @\file_put_contents($logPath, $chunk, \FILE_APPEND | \LOCK_EX);
        if (\strlen($chunk) !== $bytes) {
            throw new IOException(\sprintf('Failed to append to host completion log "%s".', $logPath), path: $logPath);
        }
    }

    /**
     * Non-blocking take of the runner's own flock file so PHP-side log mutations
     * never interleave with a live bin/deploy-tasks-host.sh run.
     *
     * @return resource|null null when a host run currently holds the lock
     */
    private function acquireHostLock(string $lockPath)
    {
        $handle = @\fopen($lockPath, 'c');
        if (false === $handle) {
            throw new IOException(\sprintf('Cannot open host lock file "%s".', $lockPath), path: $lockPath);
        }
        if (!\flock($handle, \LOCK_EX | \LOCK_NB)) {
            \fclose($handle);

            return null;
        }

        return $handle;
    }

    /** @param resource $handle */
    private function releaseHostLock($handle): void
    {
        \flock($handle, \LOCK_UN);
        \fclose($handle);
    }

    /**
     * Removes every exact-match line for $id via a temp-file + rename (matching
     * FilesystemStorage's atomicity discipline), so concurrent readers never observe
     * a partially-written log.
     *
     * @return int number of lines removed
     */
    private function rewriteHostLogWithout(string $logPath, string $id): int
    {
        $lines = $this->readHostLog($logPath);
        $remaining = \array_values(\array_filter($lines, static fn (string $line): bool => $line !== $id));
        $removed = \count($lines) - \count($remaining);

        if ($removed > 0) {
            $content = [] === $remaining ? '' : \implode("\n", $remaining)."\n";
            (new Filesystem())->dumpFile($logPath, $content);
        }

        return $removed;
    }
}
