<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Exception;

/**
 * Thrown at boot when the configured storage cannot honour the requested transactional semantics.
 */
final class IncompatibleStorageException extends \LogicException implements DeployTasksExceptionInterface
{
    public static function allOrNothingRequiresTransactional(string $storageClass): self
    {
        return new self(\sprintf(
            'Configuration "all_or_nothing: true" requires a storage backend that supports transactions. Configured storage ("%s") does not. Use "storage.type: database" or a custom backend implementing transactional support.',
            $storageClass,
        ));
    }

    public static function taskRequiresTransactional(string $taskClass, string $storageClass): self
    {
        return new self(\sprintf(
            'Deploy task "%s" declares #[AsDeployTask(transactional: true)] but the configured storage ("%s") does not support transactions. Use "storage.type: database" (or a custom transactional backend), or remove the per-task transactional flag.',
            $taskClass,
            $storageClass,
        ));
    }
}
