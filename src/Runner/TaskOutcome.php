<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Runner;

use Soviann\DeployTasksBundle\Storage\TaskStatus;
use Soviann\DeployTasksBundle\TaskResult;

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
        public float $durationSeconds = 0.0,
        public ?string $error = null,
    ) {
    }
}
