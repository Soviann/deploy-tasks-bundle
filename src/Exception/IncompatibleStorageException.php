<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Exception;

use Soviann\DeployTasksBundle\Storage\TransactionalStorageInterface;

/**
 * Thrown at boot when the configured storage cannot honour the requested transactional semantics.
 */
final class IncompatibleStorageException extends \LogicException
{
    public static function allOrNothingRequiresTransactional(string $storageClass): self
    {
        return new self(\sprintf(
            'Configuration "all_or_nothing: true" requires a storage implementing %s; "%s" does not.',
            TransactionalStorageInterface::class,
            $storageClass,
        ));
    }
}
