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
            transactional: false    # default — filesystem has no transaction support
            all_or_nothing: false   # default — ignored for filesystem
```

Filesystem storage does not implement `TransactionalStorageInterface`, so `transactional` and `all_or_nothing` have no effect. The keys are accepted for config-tree uniformity across backends.

The directory is created automatically on the first write. Add it to `.gitignore`:

```
/var/deploy-tasks/
```

Each file is named `<task-id>.json` for the default slot, or `<task-id>@<group>.json` for a group slot (e.g. `task_foo@predeploy.json`). Group names with characters outside `[a-zA-Z0-9._-]` are slugified to `_`. The file contains the task ID, status, execution timestamp, group, and any error message.

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
            transactional: true              # default — wrap each task in a DB transaction
            all_or_nothing: true             # default — wrap the entire run in a single transaction
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
    task_group  VARCHAR(128) NOT NULL DEFAULT '',
    status      VARCHAR(16)  NOT NULL,
    executed_at VARCHAR(32)  NOT NULL,
    error       TEXT         DEFAULT NULL,
    PRIMARY KEY (id, task_group)
);
```

The `task_group` column stores the empty string for the default slot (SQL forbids `NULL` in a primary key), and the declared group name for grouped slots. The composite primary key `(id, task_group)` allows one row per `(task, group)` slot — a task declared in multiple groups therefore records one row per slot.

These are the default column names and widths; adjust them to match your configuration if you've overridden them.

### Reusing an existing table (custom column names)

When deploying the bundle into an application that already has a tracking table with different column names or sizes, all six column identifiers and both `VARCHAR` widths are configurable:

```yaml
deploy_tasks:
    storage:
        type: database
        database:
            table: app_deploy_history           # existing table name
            id_column: task_name                # default: id
            id_column_length: 128               # default: 255
            status_column: state                # default: status
            executed_at_column: ran_at          # default: executed_at
            error_column: failure_message       # default: error
            group_column: slot                  # default: task_group
            group_column_length: 64             # default: 128
```

`group_column` and `group_column_length` control the column that stores the task group slot. Override them when your existing table uses a different column name or a shorter/longer `VARCHAR` definition. The column must allow an empty-string default (not `NULL`) because the bundle stores `''` for the default (ungrouped) slot — SQL forbids `NULL` in a composite primary key.

## Ephemeral filesystems (Docker, Kubernetes)

Filesystem storage writes task-execution records under `%kernel.project_dir%` by default. On container platforms this directory sits on an overlay filesystem that resets with every pod restart or image rebuild, so execution records silently disappear and one-shot tasks run again.

For containerised deployments, prefer one of:

- a dedicated bind mount (Docker) or `PersistentVolumeClaim` (Kubernetes) mapped at `%kernel.project_dir%/var/deploy-tasks/`;
- the database backend, which records executions in a durable SQL table and is not affected by overlay-FS resets.

### Transactional tasks

Transaction wrapping is configured per storage backend under `storage.<type>`:

```yaml
deploy_tasks:
    storage:
        type: database
        database:
            transactional: true       # default for database — wrap each task in a DB transaction
            all_or_nothing: true      # default for database — wrap the entire run in a single transaction
```

- **`transactional: true`** (database default): each task's `run()` and storage `save()` are wrapped in a database transaction. Individual tasks can override this via `#[AsDeployTask(transactional: false)]`. When `transactional` is `null` on the attribute, the storage setting applies.
- **`all_or_nothing: true`** (database default): the entire run is wrapped in a single transaction — any failure rolls back all tasks. Per-task wrapping is skipped when this is enabled.

Both require a storage backend implementing `TransactionalStorageInterface` (the built-in `DbalStorage` supports this). For `FilesystemStorage` these keys exist for uniformity but have no effect.

## InMemoryStorage

`InMemoryStorage` is provided for use in tests only. It is not registered as a bundle service and must be instantiated directly:

```php
use Soviann\DeployTasksBundle\Storage\InMemory\InMemoryStorage;

$storage = new InMemoryStorage();
```

`InMemoryStorage` is **not transactional** on its own — it does not implement `TransactionalStorageInterface`, so runner config keys `transactional` / `all_or_nothing` have no effect against it. Tests that need transactional behaviour should pair it with `TransactionalInMemoryStorageFixture` (see `tests/Fixtures/`), which wraps `InMemoryStorage` to implement the transactional interface.

## Custom

Use `type: custom` to plug in any `TaskStorageInterface` implementation. Implement the interface and register your class as a service:

```php
use Soviann\DeployTasksBundle\Storage\TaskStorageInterface;

final class RedisStorage implements TaskStorageInterface
{
    // ...
}
```

If your backend supports transactions, implement `TransactionalStorageInterface` (which extends `TaskStorageInterface`) — the bundle detects this automatically and exposes your storage as both interfaces. Detection happens in a compiler pass that inspects the registered service's class after extension loading, so the alias is wired without any extra configuration on your side.

```yaml
# config/services.yaml
services:
    App\Storage\RedisStorage:
        arguments: ['@redis']
```

```yaml
# config/packages/deploy_tasks.yaml
deploy_tasks:
    storage:
        type: custom
        custom:
            service: App\Storage\RedisStorage    # service ID of your TaskStorageInterface implementation
            transactional: false                 # requires TransactionalStorageInterface
            all_or_nothing: false                # requires TransactionalStorageInterface
```

`transactional` and `all_or_nothing` have no effect unless the custom service implements `TransactionalStorageInterface`.
