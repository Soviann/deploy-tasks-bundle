# Storage Backends

The storage backend records which tasks have been executed, their status, and when they ran. The bundle ships with two backends and an in-memory implementation for tests.

## Filesystem (default)

Stores one JSON file per task execution under a configurable directory. Requires no additional packages.

```yaml
soviann_deploy_tasks:
    storage:
        type: filesystem
        filesystem:
            path: '%kernel.project_dir%/var/deploy-tasks'
            transactional: false    # must stay false — true is rejected at container build
            all_or_nothing: false   # must stay false — true is rejected at container build
```

Filesystem storage does not implement `TransactionalStorageInterface`. Setting `transactional: true` is rejected when the configuration is processed, and `all_or_nothing: true` fails the container build with `IncompatibleStorageException`. The keys exist for config-tree uniformity and must stay `false`.

The directory is created automatically on the first write. Add it to `.gitignore`:

```
/var/deploy-tasks/
```

Each file is named `<task-id>.json` for the default slot, or `<task-id>@<group>.json` for a group slot (e.g. `task_foo@predeploy.json`). Group names are constrained to `AsDeployTask::GROUP_NAME_PATTERN` (`^[a-zA-Z0-9._-]+$`); names containing slashes, whitespace, or other characters are rejected with `\InvalidArgumentException` at `#[AsDeployTask]` construct time and again at the storage boundary. The file contains the task ID, status, execution timestamp, group, and any error message.

Files are written atomically (`Filesystem::dumpFile()` + `LOCK_EX`) at mode `0600`; the storage directory is created at mode `0700` on first use. Per-file permissions are re-applied on every `save()`, so existing slot files tighten themselves on the next write.

## Database

Stores execution records in a database table. Requires `doctrine/dbal`:

```bash
composer require doctrine/dbal
```

```yaml
soviann_deploy_tasks:
    storage:
        type: database
        database:
            connection: default              # Doctrine DBAL connection name
            table: deploy_task_executions    # Table name
            auto_create_table: true          # Create table on first use
            group_column: task_group         # Column for the group slot key
            group_column_length: 128
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
soviann_deploy_tasks:
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
soviann_deploy_tasks:
    storage:
        type: database
        database:
            transactional: true       # default for database — wrap each task in a DB transaction
            all_or_nothing: true      # default for database — wrap the entire run in a single transaction
```

- **`transactional: true`** (database default): each task's `run()` and storage `save()` are wrapped in a database transaction. Individual tasks can override this via `#[AsDeployTask(transactional: false)]`. When `transactional` is `null` on the attribute, the storage setting applies.
- **`all_or_nothing: true`** (database default): the entire run is wrapped in a single transaction — any failure rolls back all tasks. Per-task wrapping is skipped when this is enabled.

Both require a storage backend implementing `TransactionalStorageInterface` (the built-in `DbalStorage` supports this). For `FilesystemStorage` they must stay `false` — `true` is rejected at container build.

## InMemoryStorage

`InMemoryStorage` is provided for use in tests only. It is not registered as a bundle service and must be instantiated directly:

```php
use Soviann\DeployTasksBundle\Storage\InMemory\InMemoryStorage;

$storage = new InMemoryStorage();
```

`InMemoryStorage` is **not transactional** on its own — it does not implement `TransactionalStorageInterface`, so runner config keys `transactional` / `all_or_nothing` have no effect against it. Tests that need transactional behaviour should pair it with `TransactionalInMemoryStorageFixture` (see `tests/Fixtures/`), which wraps `InMemoryStorage` to implement the transactional interface.

## Custom

Use `type: custom` to plug in any `TaskStorageInterface` implementation. The bundle ships only the filesystem and DBAL backends — anything else (Redis, DynamoDB, an existing application table accessed through a Repository, an in-cluster KV store…) goes through this extension point.

### The `TaskStorageInterface` contract

```php
namespace Soviann\DeployTasksBundle\Storage;

interface TaskStorageInterface
{
    public function has(string $taskId, ?string $group = null): bool;
    public function get(string $taskId, ?string $group = null): ?TaskExecution;
    public function save(TaskExecution $execution): void;
    public function remove(string $taskId, ?string $group = null): void;
    public function removeAll(string $taskId): void;

    /** @return list<TaskExecution> */
    public function findByTaskId(string $taskId): array;

    /** @return list<TaskExecution> */
    public function all(): array;

    public function reset(): void;
}
```

All read/write methods are scoped by `(taskId, ?group)` — `null` is the default (ungrouped) slot. `findByTaskId()` returns every slot recorded for one task, used by `deploytasks:show`.

### End-to-end Redis example

The example below stores execution records as Redis hashes keyed `soviann_deploy_tasks:<id>:<group>`. It uses [`predis/predis`](https://github.com/predis/predis), but `phpredis` works the same way — only the client method names differ.

```php
namespace App\Storage;

use Predis\ClientInterface;
use Soviann\DeployTasksBundle\Storage\TaskExecution;
use Soviann\DeployTasksBundle\Storage\TaskStatus;
use Soviann\DeployTasksBundle\Storage\TaskStorageInterface;

final class RedisStorage implements TaskStorageInterface
{
    private const KEY_PREFIX = 'soviann_deploy_tasks:';
    private const INDEX_KEY = 'soviann_deploy_tasks:index';

    public function __construct(private readonly ClientInterface $redis)
    {
    }

    public function has(string $taskId, ?string $group = null): bool
    {
        return 1 === $this->redis->exists($this->key($taskId, $group));
    }

    public function get(string $taskId, ?string $group = null): ?TaskExecution
    {
        $payload = $this->redis->hgetall($this->key($taskId, $group));

        return [] === $payload ? null : $this->hydrate($payload);
    }

    public function save(TaskExecution $execution): void
    {
        $key = $this->key($execution->id, $execution->group);

        $this->redis->hmset($key, [
            'id' => $execution->id,
            'task_group' => $execution->group ?? '',
            'status' => $execution->status->value,
            'executed_at' => $execution->executedAt->format(\DATE_ATOM),
            'error' => $execution->error ?? '',
        ]);
        $this->redis->sadd(self::INDEX_KEY, [$key]);
    }

    public function remove(string $taskId, ?string $group = null): void
    {
        $key = $this->key($taskId, $group);

        $this->redis->del([$key]);
        $this->redis->srem(self::INDEX_KEY, $key);
    }

    public function removeAll(string $taskId): void
    {
        foreach ($this->redis->keys(self::KEY_PREFIX.$taskId.':*') as $key) {
            $this->redis->del([$key]);
            $this->redis->srem(self::INDEX_KEY, $key);
        }
    }

    public function findByTaskId(string $taskId): array
    {
        $executions = [];

        foreach ($this->redis->keys(self::KEY_PREFIX.$taskId.':*') as $key) {
            $payload = $this->redis->hgetall($key);

            if ([] !== $payload) {
                $executions[] = $this->hydrate($payload);
            }
        }

        return $executions;
    }

    public function all(): array
    {
        $executions = [];

        foreach ($this->redis->smembers(self::INDEX_KEY) as $key) {
            $payload = $this->redis->hgetall($key);

            if ([] !== $payload) {
                $executions[] = $this->hydrate($payload);
            }
        }

        return $executions;
    }

    public function reset(): void
    {
        $keys = $this->redis->smembers(self::INDEX_KEY);

        if ([] !== $keys) {
            $this->redis->del($keys);
        }
        $this->redis->del([self::INDEX_KEY]);
    }

    private function key(string $taskId, ?string $group): string
    {
        return self::KEY_PREFIX.$taskId.':'.($group ?? '');
    }

    /**
     * @param array<string, string> $payload
     */
    private function hydrate(array $payload): TaskExecution
    {
        return new TaskExecution(
            id: $payload['id'],
            status: TaskStatus::from($payload['status']),
            executedAt: new \DateTimeImmutable($payload['executed_at']),
            error: '' === $payload['error'] ? null : $payload['error'],
            group: '' === $payload['task_group'] ? null : $payload['task_group'],
        );
    }
}
```

Wire it as a regular Symfony service and point the bundle at it:

```yaml
# config/services.yaml
services:
    App\Storage\RedisStorage:
        arguments: ['@Predis\ClientInterface']
```

```yaml
# config/packages/soviann_deploy_tasks.yaml
soviann_deploy_tasks:
    storage:
        type: custom
        custom:
            service: App\Storage\RedisStorage    # service ID of your TaskStorageInterface implementation
            transactional: false                 # requires TransactionalStorageInterface
            all_or_nothing: false                # requires TransactionalStorageInterface
```

That is enough. The bundle:

- Aliases your service to `TaskStorageInterface` so the runner picks it up.
- Detects the interface at compile time; if you also implement `TransactionalStorageInterface`, the runner uses transaction wrapping when `transactional` / `all_or_nothing` are enabled.
- `deploytasks:create-schema` is only registered for the built-in database storage. It is not wired for custom backends, even those implementing `SchemaManageable` — provision custom schemas yourself.

`transactional` and `all_or_nothing` are rejected at container build (`IncompatibleStorageException`) unless the custom service implements `TransactionalStorageInterface`. If your backend has no transaction primitive (Redis, S3, plain HTTP API…), keep both `false`.

### Testing your custom storage

The simplest harness is the runner with `InMemoryStorage` swapped for your implementation against a disposable backend (Redis on a test container, an in-memory fake, …). The fixtures under `tests/Fixtures/` (e.g. `TransactionalInMemoryStorageFixture.php`) show how to compose a transactional decorator around a non-transactional store if you need that pattern.
