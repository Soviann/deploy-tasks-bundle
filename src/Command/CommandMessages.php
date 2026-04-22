<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Command;

/**
 * Shared user-facing error strings for CLI commands.
 *
 * @internal
 */
final class CommandMessages
{
    public const UNKNOWN_TASK = 'Task "%s" is not registered. Run deploytasks:status to see available tasks.';
}
