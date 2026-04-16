<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Storage\Dbal;

/**
 * Configuration for DBAL-backed task storage.
 *
 * Mirrors Doctrine\Migrations\Metadata\Storage\TableMetadataStorageConfiguration.
 *
 * @internal
 */
final readonly class DbalStorageConfiguration
{
    public function __construct(
        public bool $autoCreateTable = true,
        public string $errorColumn = 'error',
        public string $executedAtColumn = 'executed_at',
        public string $groupColumn = 'task_group',
        public int $groupColumnLength = 128,
        public string $idColumn = 'id',
        public int $idColumnLength = 255,
        public string $statusColumn = 'status',
        public string $tableName = 'deploy_task_executions',
    ) {
    }
}
