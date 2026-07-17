<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Command;

/**
 * Shared non-standard exit codes returned by the bundle's console commands.
 *
 * @internal
 */
final class ExitCodes
{
    /**
     * Exit code returned when the run lock is already held by another process.
     * Signals "temporary failure — retry recommended" (POSIX EX_TEMPFAIL, sysexits.h 75).
     */
    public const EX_TEMPFAIL = 75;

    /**
     * Exit code returned when --require-some is set but no task matched the provided filters.
     * Signals "command line usage error" (POSIX EX_USAGE, sysexits.h 64).
     */
    public const EX_USAGE = 64;
}
