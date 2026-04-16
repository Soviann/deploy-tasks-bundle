# Console Commands

## deploytasks:run

Execute all pending deploy tasks in priority order.

```bash
bin/console deploytasks:run
bin/console deploytasks:run --dry-run
bin/console deploytasks:run --force
bin/console deploytasks:run --id=task_20260412143000_seed_categories
bin/console deploytasks:run --force --id=task_20260412143000_seed_categories
bin/console deploytasks:run --group=predeploy
bin/console deploytasks:run --group=predeploy --group=postdeploy
bin/console deploytasks:run --id=task_20260412143000_seed_categories --group=predeploy
```

**Options:**

| Option | Description |
|---|---|
| `--dry-run` | List pending tasks without executing them |
| `--force`, `-f` | Force re-execution of all tasks regardless of their current state |
| `--id=<id>` | Target a single task by its ID |
| `--group=<name>` | Restrict execution to the given group slot; repeatable for a union (e.g. `--group=a --group=b`). Without this flag, only default-slot tasks run. |

**Exit codes:** `0` on success; `1` if at least one task failed or if the run was locked; `2` (`Command::INVALID`) when `--id` targets a task whose group declaration is incompatible with the supplied `--group` values.

**Group semantics:**

- No `--group` → runs only tasks without declared groups (the default slot).
- `--group=X` → runs only tasks declaring `X`; one storage row per `(task, X)` slot.
- Multi-group tasks run once per requested slot in the same invocation (e.g. `--group=predeploy --group=postdeploy` executes the task twice, storing two rows).
- `--force` with `--group` only re-runs the targeted slots; other slots remain untouched.

When `symfony/lock` is installed and lock is enabled, a lock is acquired before execution begins. If the lock cannot be acquired, the command exits with a warning and code `1`.

---

## deploytasks:status

Display all registered tasks and their execution state.

```bash
bin/console deploytasks:status
bin/console deploytasks:status --no-state
bin/console deploytasks:status --group=predeploy
bin/console deploytasks:status --group=predeploy --group=postdeploy
```

**Options:**

| Option | Description |
|---|---|
| `--no-state` | Show only task IDs and descriptions; omit execution state (useful for scripting) |
| `--group=<name>` | Only display rows for the given group slot(s); repeatable. |

Multi-group tasks are displayed once per declared slot. The `Group` column shows the slot name; the default slot is rendered as `—`.

**Status values:**

| Value | Meaning |
|---|---|
| `pending` | Not yet executed |
| `ran` | Executed successfully |
| `failed` | Execution failed; will be retried on the next `deploytasks:run` |
| `skipped` | Manually marked as skipped via `deploytasks:skip` |

---

## deploytasks:skip

Mark a task as skipped without executing it. The task will not be executed on subsequent runs.

```bash
bin/console deploytasks:skip task_20260412143000_seed_categories
bin/console deploytasks:skip task_20260412143000_seed_categories --group=predeploy
```

**Arguments:**

| Argument | Description |
|---|---|
| `id` | The task ID to skip (required) |

**Options:**

| Option | Description |
|---|---|
| `--group=<name>` | Target a specific group slot. Required when the task declares groups; forbidden otherwise. |

Use `deploytasks:reset` to re-enable a skipped task.

---

## deploytasks:reset

Remove a task's execution record so it is treated as pending and will run again on the next `deploytasks:run`.

```bash
bin/console deploytasks:reset task_20260412143000_seed_categories
bin/console deploytasks:reset task_20260412143000_seed_categories --no-interaction
bin/console deploytasks:reset task_20260412143000_seed_categories --group=predeploy
```

**Arguments:**

| Argument | Description |
|---|---|
| `id` | The task ID to reset (required) |

**Options:**

| Option | Description |
|---|---|
| `--no-interaction` | Skip the confirmation prompt; useful in CI scripts |
| `--group=<name>` | Reset only the given group slot; without this flag every slot recorded for the task is cleared |

If the task has no execution record, the command reports it is already pending and exits successfully without error.

---

## deploytasks:generate

Generate a new deploy task class with a timestamp-based ID.

```bash
bin/console deploytasks:generate
bin/console deploytasks:generate SeedCategories
bin/console deploytasks:generate SeedCategories --dir=src/Task/
```

**Arguments:**

| Argument | Description |
|---|---|
| `name` | Optional descriptive suffix for the class name (e.g. `SeedCategories`) |

**Options:**

| Option | Default | Description |
|---|---|---|
| `--dir` | `src/DeployTasks/Task/` | Target directory for the generated file |

The generated class name is `Task<YYYYMMDDHHmmss>[Name].php` and the task ID follows the convention `task_<YYYYMMDDHHmmss>[_name]`. The namespace is derived from the target directory by converting path segments to `PascalCase` (e.g. `src/DeployTasks/Task/` becomes `App\DeployTasks\Task`).

The generated file implements `DeployTaskInterface`, includes the `#[AsDeployTask]` attribute pre-configured with the generated ID, and provides a stub `run()` method.

---

## deploytasks:rollup

Clear all execution records and mark every registered task as executed. Useful for fresh environments where the current state already incorporates all task effects, or for cleaning up stale history after old tasks have been removed.

```bash
bin/console deploytasks:rollup
bin/console deploytasks:rollup --no-interaction
bin/console deploytasks:rollup --group=predeploy
bin/console deploytasks:rollup --group=predeploy --group=postdeploy
```

**Options:**

| Option | Description |
|---|---|
| `--no-interaction` | Skip the confirmation prompt; useful in CI scripts |
| `--group=<name>` | Roll up only the given group slot(s); repeatable. Preserves records for other slots. Without this flag, every slot is rolled up and the whole table is reset. |

You are prompted for confirmation before proceeding. Use `--no-interaction` to skip the prompt (e.g. in CI).

If the storage backend implements `TransactionalStorageInterface`, the reset and re-mark operations are wrapped in a single transaction.

---

## deploytasks:create-schema

Create the database table used by the DBAL storage backend. Only available when `storage.type: database` is configured.

```bash
bin/console deploytasks:create-schema
bin/console deploytasks:create-schema --dump-sql
```

**Options:**

| Option | Description |
|---|---|
| `--dump-sql` | Output the SQL statement instead of executing it (e.g. for use in a Doctrine migration) |

If the table already exists, this command is a no-op (`CREATE TABLE IF NOT EXISTS`). The SQL output uses platform-aware identifier quoting.
