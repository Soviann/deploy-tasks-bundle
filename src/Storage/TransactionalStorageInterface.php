<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Storage;

use Soviann\DeployTasksBundle\Exception\StorageException;

/**
 * A storage backend that supports wrapping operations in a transaction.
 *
 * Exception contract: implementations MUST wrap backend transaction failures in
 * StorageException (see TaskStorageInterface). Exceptions thrown by the callback
 * itself propagate to the caller unchanged, after the transaction is rolled back.
 */
interface TransactionalStorageInterface extends TaskStorageInterface
{
    /**
     * Wraps a callable in a transaction.
     *
     * @template T
     *
     * @param \Closure(): T $callback
     *
     * @return T
     *
     * @throws StorageException When the transaction cannot be started, committed, or rolled back
     */
    public function transactional(\Closure $callback): mixed;
}
