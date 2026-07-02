<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Storage;

use Soviann\DeployTasksBundle\Exception\StorageException;

/**
 * Persisted status of a task execution in storage.
 */
enum TaskStatus: string
{
    case Ran = 'ran';
    case Failed = 'failed';
    case Skipped = 'skipped';

    /**
     * Parses a status read back from storage, mapping an unknown value to the
     * storage-corruption exception every backend must raise for it.
     *
     * @throws StorageException When $value is not a valid persisted status
     */
    public static function fromStored(string $value, string $taskId, ?string $group): self
    {
        try {
            return self::from($value);
        } catch (\ValueError $e) {
            throw StorageException::corruptedRow($taskId, $group, $value, $e);
        }
    }
}
