<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Exception;

final class TaskEnvironmentMismatchException extends \LogicException
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
