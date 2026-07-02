<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Exception;

/**
 * Thrown by TaskRunner::runOne() when the targeted task declares an `env` constraint
 * that does not include the runner's environment. runAll() never throws this — it
 * silently filters env-mismatched tasks out (implicit selection vs explicit targeting).
 */
final class TaskEnvironmentMismatchException extends \RuntimeException implements DeployTasksExceptionInterface
{
    public function __construct(
        public readonly string $taskId,
        public readonly ?string $taskEnv,
        public readonly string $runnerEnv,
    ) {
        parent::__construct(\sprintf(
            'Task "%s" is restricted to env "%s" but runner is in "%s".',
            $taskId,
            $taskEnv ?? '(any)',
            $runnerEnv,
        ));
    }
}
