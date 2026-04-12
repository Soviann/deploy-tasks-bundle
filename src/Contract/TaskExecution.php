<?php

declare(strict_types=1);

namespace Soviann\DeployTasks\Contract;

/**
 * Immutable record of a task execution (ran, failed, or skipped).
 */
final readonly class TaskExecution
{
    public function __construct(
        public string $id,
        public TaskStatus $status,
        public \DateTimeImmutable $executedAt,
        public ?string $error = null,
    ) {
    }
}
