<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Runner;

/**
 * Result summary of a deploy task run.
 */
final readonly class RunResult
{
    public function __construct(
        /** Number of slots executed. When $dryRun is true, $ran counts the slots that WOULD run. */
        public int $ran,
        /** Slots not executed because they already hold a record — they will not run again. */
        public int $skipped,
        public int $failed,
        /**
         * Slots whose task returned {@see \Soviann\DeployTasksBundle\TaskResult::SKIPPED} this run.
         * Nothing is recorded for them: the slot stays pending and the task is retried on the
         * next run. Always 0 in dry runs — the runner cannot know a task would skip itself
         * without executing it.
         */
        public int $deferred = 0,
        public bool $locked = false,
        /** Whether this result describes a dry run: nothing was executed or persisted. */
        public bool $dryRun = false,
    ) {
    }

    /**
     * Whether all tasks completed without failure and the run was not locked.
     */
    public function isSuccessful(): bool
    {
        return 0 === $this->failed && !$this->locked;
    }
}
