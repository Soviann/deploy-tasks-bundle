<?php

declare(strict_types=1);

namespace Soviann\DeployTasks;

use Soviann\DeployTasks\Contract\TaskResult;
use Soviann\DeployTasks\Contract\TaskStatus;

/**
 * Internal result of a single task execution, used by the runner to produce
 * one storage row per matching group slot.
 *
 * @internal
 */
final readonly class TaskOutcome
{
    public function __construct(
        public TaskResult $result,
        public TaskStatus $status,
        public \DateTimeImmutable $executedAt,
        public ?string $error = null,
    ) {
    }
}
