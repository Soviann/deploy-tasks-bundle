<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Exception;

use Soviann\DeployTasksBundle\Runner\RunResult;

/**
 * Thrown when a task fails during an `all_or_nothing` run: the run-wide transaction
 * is rolled back (nothing was persisted) and this exception escapes runAll().
 *
 * $partialResult carries the counts accumulated before the failure — presentation
 * only, since the rollback voided the corresponding writes.
 */
final class AllOrNothingFailureException extends \RuntimeException implements DeployTasksExceptionInterface
{
    public function __construct(
        public readonly RunResult $partialResult,
        public readonly string $failedTaskId,
        \Throwable $previous,
    ) {
        parent::__construct(
            \sprintf('Deploy aborted at task "%s" under all_or_nothing.', $failedTaskId),
            0,
            $previous,
        );
    }
}
