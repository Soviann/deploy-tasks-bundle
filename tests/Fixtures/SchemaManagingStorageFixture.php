<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Tests\Fixtures;

use Soviann\DeployTasksBundle\Storage\InMemory\InMemoryStorage;
use Soviann\DeployTasksBundle\Storage\SchemaManageableInterface;
use Soviann\DeployTasksBundle\Storage\TaskExecution;
use Soviann\DeployTasksBundle\Storage\TaskStorageInterface;

/**
 * In-memory storage that opts into DDL provisioning — proves that
 * `deploytasks:create-schema` is offered to any custom backend implementing
 * SchemaManageableInterface, not only the built-in database storage.
 */
final class SchemaManagingStorageFixture implements SchemaManageableInterface, TaskStorageInterface
{
    public const CREATE_SQL = 'CREATE TABLE custom_backend_records (id VARCHAR(191) NOT NULL)';

    private bool $schemaCreated = false;

    private readonly InMemoryStorage $inner;

    public function __construct()
    {
        $this->inner = new InMemoryStorage();
    }

    public function getCreateTableSql(): string
    {
        return self::CREATE_SQL;
    }

    public function createSchema(): void
    {
        $this->schemaCreated = true;
    }

    public function isSchemaCreated(): bool
    {
        return $this->schemaCreated;
    }

    public function has(string $taskId, ?string $group = null): bool
    {
        return $this->inner->has($taskId, $group);
    }

    public function get(string $taskId, ?string $group = null): ?TaskExecution
    {
        return $this->inner->get($taskId, $group);
    }

    public function save(TaskExecution $execution): void
    {
        $this->inner->save($execution);
    }

    public function remove(string $taskId, ?string $group = null): void
    {
        $this->inner->remove($taskId, $group);
    }

    public function removeAll(string $taskId): void
    {
        $this->inner->removeAll($taskId);
    }

    /**
     * @return list<TaskExecution>
     */
    public function findByTaskId(string $taskId): array
    {
        return $this->inner->findByTaskId($taskId);
    }

    /**
     * @return list<TaskExecution>
     */
    public function all(): array
    {
        return $this->inner->all();
    }

    public function reset(): void
    {
        $this->inner->reset();
    }
}
