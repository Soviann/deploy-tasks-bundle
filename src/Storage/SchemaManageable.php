<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Storage;

/**
 * Capability opt-in for storage backends that can provision their own persistence
 * layer — typically a relational table, but anything the `deploytasks:create-schema`
 * console command can either execute or dump.
 *
 * Backends that have nothing to set up (e.g. filesystem storage) simply don't
 * implement it; the create-schema command is only registered when the active
 * backend advertises this contract.
 */
interface SchemaManageable
{
    /**
     * Returns the raw DDL the backend would execute to bootstrap its storage.
     *
     * Useful for `--dump-sql` so operators can fold the statement into a
     * Doctrine migration (or an equivalent migration tool) instead of running
     * the command against the live connection.
     */
    public function getCreateTableSql(): string;

    /**
     * Executes the schema bootstrap directly against the configured backend.
     *
     * Implementations must be idempotent (e.g. `CREATE TABLE IF NOT EXISTS`)
     * so repeated runs don't fail on an already-provisioned storage.
     */
    public function createSchema(): void;
}
