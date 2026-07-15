<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Command;

use Soviann\DeployTasksBundle\Attribute\AsDeployTask;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;

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
     * Must be called while holding the host runner's flock (see withHostLock()):
     * the trailing-newline probe below reads the log *before* the locked write,
     * so only the caller-held lock makes the probe+append pair race-free against
     * a concurrent bin/deploy-tasks-host.sh run or another ops command.
     *
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

        // A hand-edited log can lose its terminating newline; appending verbatim
        // would concatenate its last id with the first appended one, making both
        // unmatchable by the runner's `grep -Fxq`. Folding the heal into the
        // single write call only guarantees the "\n" and the ids cannot be torn
        // apart; the probe itself runs outside the write's LOCK_EX, so the
        // read+append race protection comes from the caller-held host flock
        // (see the docblock above).
        if ($this->hostLogEndsMidLine($logPath)) {
            $chunk = "\n".$chunk;
        }

        // The native warning is suppressed because the return value is checked
        // right below; the failure surfaces as an IOException instead.
        $bytes = @\file_put_contents($logPath, $chunk, \FILE_APPEND | \LOCK_EX);
        if (\strlen($chunk) !== $bytes) {
            throw new IOException(\sprintf('Failed to append to host completion log "%s".', $logPath), path: $logPath);
        }
    }

    /**
     * True when the log's last byte is not "\n" — i.e. a hand-edit left the final
     * line un-terminated. Any probe failure (filesize() or the last-byte read)
     * also returns true, so uncertainty always errs toward healing: the resulting
     * extra "\n" only produces an empty line, which both the runner's `grep -Fxq`
     * and readHostLog() (FILE_SKIP_EMPTY_LINES) ignore.
     */
    private function hostLogEndsMidLine(string $logPath): bool
    {
        if (!\is_file($logPath)) {
            return false;
        }

        $size = @\filesize($logPath);
        if (false === $size) {
            return true;
        }
        if (0 === $size) {
            return false;
        }

        return "\n" !== @\file_get_contents($logPath, offset: $size - 1, length: 1);
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
     * Runs $operation under the host runner's flock, mapping contention to the
     * warning + EX_TEMPFAIL exit shared by all host ops commands.
     *
     * @param \Closure(): int $operation returns the command exit code
     */
    private function withHostLock(string $lockPath, SymfonyStyle $io, \Closure $operation): int
    {
        $lock = $this->acquireHostLock($lockPath);

        if (null === $lock) {
            $io->warning(\sprintf(CommandMessages::HOST_LOCK_HELD, $lockPath));

            return DeployTasksRunCommand::EX_TEMPFAIL;
        }

        try {
            return $operation();
        } finally {
            $this->releaseHostLock($lock);
        }
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

    /**
     * Lists host task ids — `*.sh` basenames at depth 0, alphabetical.
     *
     * Finder instead of glob(): glob() treats [?* in the *directory path* as
     * pattern metacharacters, silently matching nothing for a project dir like
     * "app[blue]". sortByName() preserves the alphabetical listing.
     *
     * @return list<string>
     */
    private function listHostTaskIds(string $hostTasksDir): array
    {
        $ids = [];

        foreach ((new Finder())->files()->in($hostTasksDir)->name('*.sh')->depth(0)->sortByName() as $script) {
            $ids[] = $script->getBasename('.sh');
        }

        return $ids;
    }
}
