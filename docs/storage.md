# Storage Backends

The storage backend records which tasks have been executed, their status, and when they ran. The bundle ships with two backends and an in-memory implementation for tests.

## Filesystem (default)

Stores one JSON file per task execution under a configurable directory. Requires no additional packages.

```yaml
deploy_tasks:
    storage:
        type: filesystem
        filesystem:
            path: '%kernel.project_dir%/var/deploy-tasks'
```

The directory is created automatically on the first write. Add it to `.gitignore`:

```
/var/deploy-tasks/
```

Each file is named `<task-id>.json` and contains the task ID, status, execution timestamp, and any error message.

## Database

Stores execution records in a database table. Requires `doctrine/dbal`:

```bash
composer require doctrine/dbal
```

```yaml
deploy_tasks:
    storage:
        type: database
        database:
            connection: default              # Doctrine DBAL connection name
            table: deploy_task_executions    # Table name
            auto_create_table: true          # Create table on first use
```

### Creating the table

By default, the table is created automatically on first use (`auto_create_table: true`). To create it manually:

**Option 1 — via command:**

```bash
# Create the table directly
bin/console deploytasks:create-schema

# Output SQL only (e.g. for a Doctrine migration)
bin/console deploytasks:create-schema --dump-sql
```

The SQL output uses platform-aware identifier quoting (backticks for MySQL, double quotes for PostgreSQL/SQLite).

**Option 2 — raw SQL:**

```sql
CREATE TABLE IF NOT EXISTS deploy_task_executions (
    id          VARCHAR(255) NOT NULL,
    status      VARCHAR(16)  NOT NULL,
    executed_at VARCHAR(32)  NOT NULL,
    error       TEXT         DEFAULT NULL,
    PRIMARY KEY (id)
);
```

### Transactional tasks

Transaction wrapping is configured via two **top-level** keys (not under `storage`):

```yaml
deploy_tasks:
    transactional: true       # default — wrap each task in a DB transaction
    all_or_nothing: false     # default — wrap the entire run in a single transaction
```

- **`transactional: true`** (default): each task's `run()` and storage `save()` are wrapped in a database transaction. Individual tasks can override this via `#[AsDeployTask(transactional: false)]`. When `transactional` is `null` on the attribute, the global setting applies.
- **`all_or_nothing: true`**: the entire run is wrapped in a single transaction — any failure rolls back all tasks. Per-task wrapping is skipped when this is enabled.

Both require a storage backend implementing `TransactionalStorageInterface` (the built-in `DbalStorage` supports this). With `FilesystemStorage`, transactional flags are silently ignored.

## InMemoryStorage

`InMemoryStorage` is provided for use in tests only. It is not registered as a bundle service and must be instantiated directly:

```php
use Soviann\DeployTasks\Storage\InMemoryStorage;

$storage = new InMemoryStorage();
```

## Custom storage

Implement `TaskStorageInterface` to use a custom backend:

```php
use Soviann\DeployTasks\Contract\TaskStorageInterface;

final class RedisStorage implements TaskStorageInterface
{
    // ...
}
```

If your backend supports transactions, implement `TransactionalStorageInterface` instead (which extends `TaskStorageInterface`).

Register the service and alias it:

```yaml
services:
    App\Storage\RedisStorage:
        arguments: ['@redis']

    Soviann\DeployTasks\Contract\TaskStorageInterface: '@App\Storage\RedisStorage'
    deploy_tasks.storage: '@App\Storage\RedisStorage'
```
