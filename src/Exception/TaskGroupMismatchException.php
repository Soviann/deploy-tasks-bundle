<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Exception;

/**
 * Thrown when a --group value targets a task that does not declare that group.
 */
final class TaskGroupMismatchException extends \RuntimeException
{
    /**
     * @param list<string> $requested
     * @param list<string> $declared
     */
    public static function create(string $taskId, array $requested, array $declared): self
    {
        if ([] === $declared) {
            return new self(\sprintf(
                'Task "%s" has no groups declared; cannot target --group=[%s].',
                $taskId,
                \implode(', ', $requested),
            ));
        }

        return new self(\sprintf(
            'Groups [%s] are not declared on task "%s" (declared: %s).',
            \implode(', ', $requested),
            $taskId,
            \implode(', ', $declared),
        ));
    }
}
