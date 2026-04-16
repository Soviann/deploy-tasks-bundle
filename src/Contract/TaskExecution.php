<?php

declare(strict_types=1);

namespace Soviann\DeployTasks\Contract;

/**
 * Immutable record of a task execution (ran, failed, or skipped).
 */
final readonly class TaskExecution
{
    /**
     * @param string             $id         Task identifier
     * @param TaskStatus         $status     Outcome of the execution
     * @param \DateTimeImmutable $executedAt When the execution happened
     * @param ?string            $error      Error message when status is Failed, null otherwise
     * @param ?string            $group      Group slot this execution is recorded under; null = default slot
     */
    public function __construct(
        public string $id,
        public TaskStatus $status,
        public \DateTimeImmutable $executedAt,
        public ?string $error = null,
        public ?string $group = null,
    ) {
    }
}
