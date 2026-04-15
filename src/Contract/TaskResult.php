<?php

declare(strict_types=1);

namespace Soviann\DeployTasks\Contract;

/**
 * Return codes for {@see DeployTaskInterface::run()}.
 *
 * Maps to standard CLI exit codes (0 = success, non-zero = error).
 *
 * @see https://tldp.org/LDP/abs/html/exitcodes.html
 */
enum TaskResult: int
{
    case SUCCESS = 0;
    case FAILURE = 1;
    case SKIPPED = 2;
}
