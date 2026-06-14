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
}
