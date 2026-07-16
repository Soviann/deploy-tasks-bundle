<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Storage;

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
     * @param ?int               $durationMs How long the task ran, in milliseconds; null when the record
     *                                       does not come from an actual run (manual skip, rollup baseline)
     */
    public function __construct(
        public string $id,
        public TaskStatus $status,
        public \DateTimeImmutable $executedAt,
        public ?string $error = null,
        public ?string $group = null,
        public ?int $durationMs = null,
    ) {
    }

    /**
     * Composite array key identifying a (task id, group) slot. Uses the NUL byte as
     * separator — IDs and group names are user-facing identifiers that never contain NUL.
     */
    public static function slotKey(string $taskId, ?string $group): string
    {
        return $taskId."\0".($group ?? '');
    }
}
