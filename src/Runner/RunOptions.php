<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Runner;

/**
 * Options controlling a {@see TaskRunner} run.
 */
final readonly class RunOptions
{
    /**
     * When empty, only default-slot tasks run. When it lists one or more group
     * names, only tasks declaring any of those groups run, and a multi-group
     * task executes once per invocation writing one storage row per matching slot.
     *
     * Deduplicated at construction — this is the single point every group-slot
     * consumer (runAll, runOne, SlotResolver) reads from, so a repeated CLI
     * `--group` value cannot inflate counters or double-persist a slot.
     *
     * @var list<string>
     */
    public array $groups;

    /**
     * @param list<string> $groups see {@see self::$groups}
     */
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
        array $groups = [],
    ) {
        $this->groups = \array_values(\array_unique($groups));
    }
}
