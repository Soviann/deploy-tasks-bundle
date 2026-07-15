<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Exception;

use Soviann\DeployTasksBundle\Runner\TransactionMode;

/**
 * Thrown at boot when the configured storage cannot honour the requested transactional semantics.
 */
final class IncompatibleStorageException extends \LogicException implements DeployTasksExceptionInterface
{
    public static function modeRequiresTransactional(TransactionMode $mode, string $storageClass): self
    {
        return new self(\sprintf(
            'Configuration "transaction_mode: %s" requires a storage backend that supports transactions. Configured storage ("%s") does not. Use "storage.type: database" or a custom backend implementing transactional support.',
            $mode->value,
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

    public static function taskOptInConflictsWithModeNone(string $taskClass): self
    {
        return new self(\sprintf(
            'Deploy task "%s" declares #[AsDeployTask(transactional: true)] but "transaction_mode" is "none", which disables transaction wrapping — the demand would be silently ignored. Set "transaction_mode: per_task" or remove the per-task transactional flag.',
            $taskClass,
        ));
    }

    public static function taskOptOutConflictsWithAllOrNothing(string $taskClass): self
    {
        return new self(\sprintf(
            'Deploy task "%s" declares #[AsDeployTask(transactional: false)] but "transaction_mode" is "all_or_nothing", which wraps the entire run in a single transaction — the opt-out cannot be honored. Set "transaction_mode: per_task" or remove the per-task transactional flag.',
            $taskClass,
        ));
    }
}
