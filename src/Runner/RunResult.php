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
        public int $skipped,
        public int $failed,
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
