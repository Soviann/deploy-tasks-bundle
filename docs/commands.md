# Console Commands

## deploytasks:run

Execute all pending deploy tasks in priority order.

```bash
bin/console deploytasks:run
bin/console deploytasks:run --dry-run
bin/console deploytasks:run --force=task_20260412143000_seed_categories
```

**Options:**

| Option | Description |
|---|---|
| `--dry-run` | List pending tasks without executing them |
| `--force=<id>` | Force re-execution of a single task regardless of its current state |

**Exit codes:** `0` on success; `1` if at least one task failed or if the run was locked (another process is already executing).

When `symfony/lock` is installed and lock is enabled, a lock is acquired before execution begins. If the lock cannot be acquired, the command exits with a warning and code `1`.

---

## deploytasks:status

Display all registered tasks and their execution state.

```bash
bin/console deploytasks:status
bin/console deploytasks:status --no-state
```

**Options:**

| Option | Description |
|---|---|
| `--no-state` | Show only task IDs and descriptions; omit execution state (useful for scripting) |

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
```

**Arguments:**

| Argument | Description |
|---|---|
| `id` | The task ID to skip (required) |

Use `deploytasks:reset` to re-enable a skipped task.

---

## deploytasks:reset

Remove a task's execution record so it is treated as pending and will run again on the next `deploytasks:run`.

```bash
bin/console deploytasks:reset task_20260412143000_seed_categories
bin/console deploytasks:reset task_20260412143000_seed_categories --no-interaction
```

**Arguments:**

| Argument | Description |
|---|---|
| `id` | The task ID to reset (required) |

**Options:**

| Option | Description |
|---|---|
| `--no-interaction` | Skip the confirmation prompt; useful in CI scripts |

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
