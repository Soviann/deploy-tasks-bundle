<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Exception;

use Soviann\DeployTasksBundle\Runner\RunResult;

final class AllOrNothingFailureException extends \RuntimeException
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
