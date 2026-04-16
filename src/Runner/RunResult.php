<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Runner;

/**
 * Result summary of a deploy task run.
 */
final readonly class RunResult
{
    public function __construct(
        /** Number of tasks executed. In dry-run mode, holds the number of pending tasks instead. */
        public int $ran,
        public int $skipped,
        public int $failed,
        public bool $locked = false,
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
