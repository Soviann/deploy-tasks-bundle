<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Command;

use Soviann\DeployTasksBundle\Storage\TaskStatus;

/**
 * Shared user-facing strings and presentation helpers for CLI commands.
 *
 * @internal
 */
final class CommandMessages
{
    public const UNKNOWN_TASK = 'Task "%s" is not registered. Run deploytasks:status to see available tasks.';

    /** Shared by the three host-scope ops commands (host:skip, host:reset, host:rollup). */
    public const HOST_DIR_MISSING = 'Host tasks directory "%s" not found. See vendor/soviann/deploy-tasks-bundle/docs/host-tasks.md to set it up.';

    /** Shared by the three host-scope ops commands (host:skip, host:reset, host:rollup). */
    public const HOST_LOCK_HELD = 'A host run holds the lock (%s) — retry when bin/deploy-tasks-host.sh finishes.';

    /** Shared by host:skip and host:reset — the sanitized rejected id fills the placeholder. */
    public const HOST_TASK_ID_INVALID = 'Invalid host task id "%s" — allowed: letters, digits, dot, underscore, hyphen.';

    /** Shared by run and rollup — a --group filter that selects nothing. */
    public const NO_TASKS_MATCHED_GROUPS = 'No tasks matched the requested group(s).';

    /**
     * Renders a task status as its colour-tagged console label.
     */
    public static function statusTag(TaskStatus $status): string
    {
        return match ($status) {
            TaskStatus::Ran => '<info>ran</info>',
            TaskStatus::Failed => '<error>failed</error>',
            TaskStatus::Skipped => '<comment>skipped</comment>',
        };
    }

    /**
     * Renders a stored execution duration human-readably: `123ms` under a
     * second, `1.2s` from one second up. Shared by `status` and `show`.
     */
    public static function formatDuration(int $durationMs): string
    {
        if ($durationMs < 1000) {
            return \sprintf('%dms', $durationMs);
        }

        return \sprintf('%.1fs', $durationMs / 1000);
    }
}
