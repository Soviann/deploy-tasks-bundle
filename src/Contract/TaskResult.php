<?php

declare(strict_types=1);

namespace Soviann\DeployTasks\Contract;

/**
 * Return codes for {@see DeployTaskInterface::run()}.
 *
 * Maps to standard CLI exit codes (0 = success, non-zero = error).
 */
final class TaskResult
{
    // see https://tldp.org/LDP/abs/html/exitcodes.html
    public const SUCCESS = 0;
    public const FAILURE = 1;
    public const SKIPPED = 2;
}
