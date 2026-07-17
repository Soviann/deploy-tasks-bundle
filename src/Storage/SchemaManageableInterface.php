<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Storage;

use Soviann\DeployTasksBundle\Exception\StorageException;

/**
 * Capability opt-in for storage backends that can provision their own persistence
 * layer — typically a relational table, but anything the `deploytasks:create-schema`
 * console command can either execute or dump.
 *
 * Implemented by backends that can provision their own schema. Backends that
 * have nothing to set up (e.g. filesystem storage) simply don't implement it.
 * The create-schema command is registered automatically for any configured
 * storage implementing this interface, custom backends included.
 *
 * Exception contract: implementations MUST wrap backend failures in StorageException
 * (see TaskStorageInterface).
 */
interface SchemaManageableInterface
{
    /**
     * Returns the raw DDL the backend would execute to bootstrap its storage.
     *
     * Useful for `--dump-sql` so operators can fold the statement into a
     * Doctrine migration (or an equivalent migration tool) instead of running
     * the command against the live connection.
     *
     * @throws StorageException When the DDL cannot be generated
     */
    public function getCreateTableSql(): string;

    /**
     * Executes the schema bootstrap directly against the configured backend.
     *
     * Implementations must be idempotent (e.g. `CREATE TABLE IF NOT EXISTS`)
     * so repeated runs don't fail on an already-provisioned storage.
     *
     * @throws StorageException When the schema bootstrap fails
     */
    public function createSchema(): void;
}
