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
```

Filesystem storage does not implement `TransactionalStorageInterface` and is inherently non-transactional — there is no `transaction_mode` key under `storage.filesystem`. Setting one fails at container build with Symfony's standard "Unrecognized option" error. Use `storage.type: database` (or a custom transactional backend) if you need transaction wrapping.

The directory is created automatically on the first write. Add it to `.gitignore`:

```
/var/deploy-tasks/
```

Each file is named `<task-id>.json` for the default slot, or `<task-id>@<group>.json` for a group slot (e.g. `task_foo@predeploy.json`). Group names are constrained to `AsDeployTask::GROUP_NAME_PATTERN` (`^[a-zA-Z0-9._-]+$`); names containing slashes, whitespace, or other characters are rejected with `\InvalidArgumentException` at `#[AsDeployTask]` construct time and again at the storage boundary. The file contains the task ID, status, execution timestamp, group, and any error message.

Writes are made atomic and safe for concurrent writers through two separate mechanisms: a sidecar lock file (`<slot>.json.lock`) is `flock(LOCK_EX)`-held for the duration of the write to serialise concurrent writers, while the destination file itself is written via `Filesystem::dumpFile()` (temp-file-then-rename) and is deliberately **not** additionally `flock`-ed — locking the destination directly would defeat the rename's atomic-visibility guarantee for readers. The destination file is created at mode `0600`; the storage directory is created at mode `0700` on first use. Per-file permissions are re-applied on every `save()`, so existing slot files tighten themselves on the next write.

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
            transaction_mode: all_or_nothing # default — none | per_task | all_or_nothing
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
    duration_ms INTEGER      DEFAULT NULL,
    PRIMARY KEY (id, task_group)
);
```

The `task_group` column stores the empty string for the default slot (SQL forbids `NULL` in a primary key), and the declared group name for grouped slots. The composite primary key `(id, task_group)` allows one row per `(task, group)` slot — a task declared in multiple groups therefore records one row per slot.

The `duration_ms` column stores how long the task ran, in milliseconds. It is `NULL` for records that do not come from an actual run — a manual `deploytasks:skip` or a `deploytasks:rollup` baseline. If your table was created before the column existed, re-run `bin/console deploytasks:create-schema` against a fresh table, or add the column by hand (`duration_ms INTEGER DEFAULT NULL`) — the command only creates missing tables, it does not alter existing ones.

These are the default column names and widths; adjust them to match your configuration if you've overridden them.

### Reusing an existing table (custom column names)

When deploying the bundle into an application that already has a tracking table with different column names or sizes, all seven column identifiers and both `VARCHAR` widths are configurable:

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
            duration_column: took_ms            # default: duration_ms
            group_column: slot                  # default: task_group
            group_column_length: 64             # default: 128
```

`group_column` and `group_column_length` control the column that stores the task group slot. Override them when your existing table uses a different column name or a shorter/longer `VARCHAR` definition. The column must allow an empty-string default (not `NULL`) because the bundle stores `''` for the default (ungrouped) slot — SQL forbids `NULL` in a composite primary key.

## Ephemeral filesystems (Docker, Kubernetes)

Filesystem storage writes task-execution records under `%kernel.project_dir%` by default. On container platforms this directory sits on an overlay filesystem that resets with every pod restart or image rebuild, so execution records silently disappear and one-shot tasks run again.

For containerised deployments, prefer one of:

- a dedicated bind mount (Docker) or `PersistentVolumeClaim` (Kubernetes) mapped at `%kernel.project_dir%/var/deploy-tasks/`;
- the database backend, which records executions in a durable SQL table and is not affected by overlay-FS resets.

### Transaction mode

Transaction wrapping is configured per storage backend via `storage.<type>.transaction_mode`: `none`, `per_task`, or `all_or_nothing`.

```yaml
soviann_deploy_tasks:
    storage:
        type: database
        database:
            transaction_mode: all_or_nothing   # default for database; custom defaults to none
```

- **`none`** — no transaction wrapping.
- **`per_task`** — each task's `run()` and its storage `save()` are wrapped together in one transaction. A task can opt out via `#[AsDeployTask(transactional: false)]`; the attribute is only consulted in this mode.
- **`all_or_nothing`** — the entire run is wrapped in a single transaction — any failure rolls back every task the run has already executed.

Any mode other than `none` requires a storage backend implementing `TransactionalStorageInterface` (the built-in `DbalStorage` supports this). The pairing is validated twice: at container build (`IncompatibleStorageException`) whenever the storage class is known at compile time, and again by `TaskRunner`'s constructor against the real storage instance — closing the gap for a storage resolvable only at runtime (a factory-built or synthetic service), which the compiler pass skips rather than guesses about.

`#[AsDeployTask(transactional:)]` only has an effect under `transaction_mode: per_task`: unset or `null` wraps the task like every other one, `false` opts it out. Declaring `transactional: true` under `transaction_mode: none`, or `transactional: false` under `transaction_mode: all_or_nothing`, fails the container build instead of being silently ignored — see [`docs/advanced.md` → Transaction Wrapping](advanced.md#transaction-wrapping).

## InMemoryStorage

`InMemoryStorage` is provided for use in tests only. It is not registered as a bundle service and must be instantiated directly:

```php
use Soviann\DeployTasksBundle\Storage\InMemory\InMemoryStorage;

$storage = new InMemoryStorage();
```

`InMemoryStorage` is **not transactional** on its own — it does not implement `TransactionalStorageInterface`, so building a `TaskRunner` against it with `transaction_mode: per_task` or `all_or_nothing` fails at construction (`IncompatibleStorageException`). Tests that need transactional behaviour should pair it with `TransactionalInMemoryStorageFixture` (see `tests/Fixtures/`), which wraps `InMemoryStorage` to implement the transactional interface.

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
            'duration_ms' => $execution->durationMs ?? '',
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
            durationMs: '' === $payload['duration_ms'] ? null : (int) $payload['duration_ms'],
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
            transaction_mode: none               # default; per_task/all_or_nothing require TransactionalStorageInterface
```

That is enough. The bundle:

- Aliases your service to `TaskStorageInterface` so the runner picks it up.
- Detects the interface at compile time; if you also implement `TransactionalStorageInterface`, the runner honors `transaction_mode: per_task` / `all_or_nothing` against it.
- Registers `deploytasks:create-schema` for your backend when it also implements `SchemaManageableInterface` (`getCreateTableSql()` + `createSchema()`) — the same capability detection the built-in database storage uses. A custom backend gets a generic success message instead of the DBAL table/connection details, and `--dump-sql` prints whatever your `getCreateTableSql()` returns. Backends without the interface must be provisioned by hand.

`transaction_mode: per_task` or `all_or_nothing` is rejected at container build (`IncompatibleStorageException`) unless the custom service implements `TransactionalStorageInterface`. If your backend has no transaction primitive (Redis, S3, plain HTTP API…), keep `transaction_mode: none` (the default for `storage.custom`).

### Testing your custom storage

The simplest harness is the runner with `InMemoryStorage` swapped for your implementation against a disposable backend (Redis on a test container, an in-memory fake, …). The fixtures under `tests/Fixtures/` (e.g. `TransactionalInMemoryStorageFixture.php`) show how to compose a transactional decorator around a non-transactional store if you need that pattern.
