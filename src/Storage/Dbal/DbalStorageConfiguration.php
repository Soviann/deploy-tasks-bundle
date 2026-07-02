<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Storage\Dbal;

/**
 * Configuration for DBAL-backed task storage.
 *
 * Mirrors Doctrine\Migrations\Metadata\Storage\TableMetadataStorageConfiguration.
 *
 * The constructor validates every table/column name against the plain-identifier
 * allowlist (also enforced by the bundle's config tree) and rejects duplicate
 * column names, so a directly constructed instance cannot feed hostile or
 * colliding identifiers into the generated DDL.
 *
 * @internal
 */
final readonly class DbalStorageConfiguration
{
    /**
     * Allowlist for table/column names. Single source for construct-time validation
     * here and config-time validation in the bundle's config tree.
     */
    public const SQL_IDENTIFIER_PATTERN = '/^[A-Za-z_][A-Za-z0-9_]*$/';

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
        $names = [
            'tableName' => $this->tableName,
            'idColumn' => $this->idColumn,
            'groupColumn' => $this->groupColumn,
            'statusColumn' => $this->statusColumn,
            'executedAtColumn' => $this->executedAtColumn,
            'errorColumn' => $this->errorColumn,
        ];

        foreach ($names as $option => $name) {
            if (1 !== \preg_match(self::SQL_IDENTIFIER_PATTERN, $name)) {
                throw new \InvalidArgumentException(\sprintf('%s "%s" is not a plain SQL identifier (must match %s).', $option, $name, self::SQL_IDENTIFIER_PATTERN));
            }
        }

        $columns = [
            $this->idColumn,
            $this->groupColumn,
            $this->statusColumn,
            $this->executedAtColumn,
            $this->errorColumn,
        ];

        if (\count($columns) !== \count(\array_unique($columns))) {
            throw new \InvalidArgumentException('Storage column names must be unique.');
        }
    }
}
