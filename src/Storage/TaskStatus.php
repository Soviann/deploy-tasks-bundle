<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Storage;

/**
 * Persisted status of a task execution in storage.
 */
enum TaskStatus: string
{
    case Ran = 'ran';
    case Failed = 'failed';
    case Skipped = 'skipped';
}
