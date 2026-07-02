<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Runner;

/**
 * Options controlling a {@see TaskRunner} run.
 */
final readonly class RunOptions
{
    public function __construct(
        /** Lists pending slots without executing tasks or writing to storage. */
        public bool $dryRun = false,
        /**
         * Re-executes every matching slot regardless of its stored state.
         *
         * Exposed on the CLI as `--rerun-all` — deliberately not named "force",
         * which the destructive commands use for an unrelated confirmation flag.
         */
        public bool $rerunAll = false,
        /**
         * When empty, only default-slot tasks run. When it lists one or more group
         * names, only tasks declaring any of those groups run, and a multi-group
         * task executes once per invocation writing one storage row per matching slot.
         *
         * @var list<string>
         */
        public array $groups = [],
    ) {
    }
}
