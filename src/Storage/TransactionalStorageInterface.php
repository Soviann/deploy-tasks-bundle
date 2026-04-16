<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Storage;

/**
 * A storage backend that supports wrapping operations in a transaction.
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
     */
    public function transactional(\Closure $callback): mixed;
}
