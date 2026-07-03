<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Command;

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

        \file_put_contents($logPath, $chunk, \FILE_APPEND | \LOCK_EX);
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
