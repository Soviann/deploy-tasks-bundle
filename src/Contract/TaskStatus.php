<?php

declare(strict_types=1);

namespace Soviann\DeployTasks\Contract;

/**
 * Persisted status of a task execution in storage.
 */
enum TaskStatus: string
{
    case Ran = 'ran';
    case Failed = 'failed';
    case Skipped = 'skipped';
}
