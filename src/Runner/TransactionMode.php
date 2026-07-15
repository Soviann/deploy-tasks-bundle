<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Runner;

/**
 * How the runner wraps task execution in storage transactions.
 *
 * Configured per storage backend via `storage.<database|custom>.transaction_mode`;
 * filesystem storage is inherently non-transactional and always runs as {@see self::None}.
 */
enum TransactionMode: string
{
    /** No transaction wrapping at all. */
    case None = 'none';

    /**
     * One transaction per task (execution + its record commit together).
     * The only mode where `#[AsDeployTask(transactional: false)]` opts a task out.
     */
    case PerTask = 'per_task';

    /** One transaction around the whole run — any failure rolls back every task. */
    case AllOrNothing = 'all_or_nothing';

    /**
     * The backing values, for the config tree's enumNode.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return \array_column(self::cases(), 'value');
    }
}
