# Console Commands

Two generator commands scaffold deploy tasks: [`deploytasks:generate:container`](#deploytasksgeneratecontainer) for PHP classes running inside the Symfony container, and [`deploytasks:generate:host`](#deploytasksgeneratehost) for bash scripts running on the host outside the container.

## deploytasks:run

Execute all pending deploy tasks in priority order.

```bash
bin/console deploytasks:run
bin/console deploytasks:run --dry-run
bin/console deploytasks:run --rerun-all
bin/console deploytasks:run --id=task_20260412143000_seed_categories
bin/console deploytasks:run --rerun-all --id=task_20260412143000_seed_categories
bin/console deploytasks:run --group=predeploy
bin/console deploytasks:run --group=predeploy --group=postdeploy
bin/console deploytasks:run --id=task_20260412143000_seed_categories --group=predeploy
bin/console deploytasks:run --require-some --group=predeploy
```

**Options:**

| Option | Description |
|---|---|
| `--dry-run` | List pending tasks without executing them |
| `--rerun-all` | Re-execute all tasks regardless of their current state |
| `--id=<id>` | Target a single task by its ID |
| `--group=<name>` | Restrict execution to the given group slot; repeatable for a union (e.g. `--group=a --group=b`). Without this flag, only default-slot tasks run. |
| `--require-some` | Exit `64` (`EX_USAGE`) when no task matches the provided filters, distinguishing an empty selection from a successful noop. |

**Exit codes:** `0` on success; `1` if at least one task failed; `2` (`Command::INVALID`) when `--id` targets a task whose group declaration is incompatible with the supplied `--group` values; `64` (`EX_USAGE`) when `--require-some` is set and no task matched the filters; `75` (`EX_TEMPFAIL`) when the run lock is already held — CI pipelines should treat this as "retry recommended" rather than a genuine failure.

**Group semantics:**

- No `--group` → runs only tasks without declared groups (the default slot).
- `--group=X` → runs only tasks declaring `X`; one storage row per `(task, X)` slot.
- Multi-group tasks run once per requested slot in the same invocation (e.g. `--group=predeploy --group=postdeploy` executes the task twice, storing two rows).
- `--rerun-all` with `--group` only re-runs the targeted slots; other slots remain untouched.

When `symfony/lock` is installed and lock is enabled, a lock is acquired before execution begins. If the lock cannot be acquired, the command exits with code `75` (`EX_TEMPFAIL`).

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
| `--filter-status=<list>` | Comma-separated statuses to display (`RAN`, `FAILED`, `SKIPPED`, `PENDING` — case-insensitive). Rejected when combined with `--no-state`. Failed slots are retried on the next run — use `PENDING,FAILED` to list everything the next `deploytasks:run` will execute (or `deploytasks:run --dry-run` for the authoritative preview). |

Multi-group tasks are displayed once per declared slot. The `Group` column shows the slot name; the default slot is rendered as `—`.

**Status values:**

| Value | Meaning |
|---|---|
| `pending` | Not yet executed |
| `ran` | Executed successfully |
| `failed` | Execution failed; will be retried on the next `deploytasks:run` |
| `skipped` | Manually marked as skipped via `deploytasks:skip`, or returned by a task as `TaskResult::SKIPPED` |

---

## deploytasks:show

Inspect a single deploy task by ID — metadata, declared groups, and every stored execution record (full error text, no truncation).

```bash
bin/console deploytasks:show task_20260412143000_seed_categories
```

**Arguments:**

| Argument | Description |
|---|---|
| `id` (required) | The deploy task ID to inspect |

**Output sections:**

- `ID`, `Class` (FQCN), `Description`, `Declared groups` (or "default slot only")
- `Execution records` — one block per stored slot with `Group`, `Status`, `Executed at`, and `Error` (full text, only when the slot failed)
- `Related commands` hints — `deploytasks:reset <id>` and `deploytasks:run --id=<id>`

**Exit codes:** `0` on success; `1` when the task ID is not registered.

Use this command after `deploytasks:status` surfaces a `failed` row and you need the complete error payload (the status table truncates errors to 60 chars).

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
bin/console deploytasks:reset task_20260412143000_seed_categories --no-interaction --force
bin/console deploytasks:reset task_20260412143000_seed_categories --group=predeploy
```

**Arguments:**

| Argument | Description |
|---|---|
| `id` | The task ID to reset (required) |

**Options:**

| Option | Description |
|---|---|
| `--force` | Confirm the destructive action under `--no-interaction`. Alias: `--yes` |
| `--no-interaction` | Run without prompting; **requires** `--force` (or `--yes`), otherwise the command refuses to run |
| `--group=<name>` | Reset only the given group slot; without this flag every slot recorded for the task is cleared |

If the task has no execution record, the command reports it is already pending and exits successfully without error.

---

## deploytasks:generate:container

Generate a new container-scope deploy task class with a timestamp-based ID.

```bash
bin/console deploytasks:generate:container
bin/console deploytasks:generate:container --dir=src/Task/
```

**Options:**

| Option | Default | Description |
|---|---|---|
| `--dir` | `src/DeployTasks/Task/` | Target directory for the generated file |

The generated class name is always `DeployTask<YYYYMMDDHHmmss>` (e.g. `DeployTask20260412143000`) — the command takes no positional argument. The task ID is auto-derived from the class name by the configured `TaskIdGeneratorInterface`: the default generator strips the `DeployTask` prefix and prefixes the purely-numeric remainder with `task_` (e.g. `task_20260412143000`).

The generated file implements `DeployTaskInterface`, includes the `#[AsDeployTask]` attribute, and provides a stub `run()` method. Rename the class after generation if you want a more descriptive name — the default ID generator also handles `SeedCategoriesTask` and similar CamelCase names.

The namespace is built by applying `ucfirst` to each path segment of the target directory; use CamelCase directory names (e.g. `src/DeployTasks/Task/`) to produce a CamelCase namespace (e.g. `App\DeployTasks\Task`). Lowercase segments remain lowercase apart from their first letter. When the directory starts with `src/`, the leading segment is rewritten to the configured root namespace — `soviann_deploy_tasks.generate.root_namespace` (default `App`, mirroring [symfony/maker-bundle](https://symfony.com/bundles/SymfonyMakerBundle/current/index.html#root-namespace)); set it to your `composer.json` PSR-4 root if that is not `App`. Pass `--namespace` to override the derived namespace entirely.

---

## deploytasks:generate:host

Generate a new host-scope deploy task script. Host scripts are plain bash files executed outside the container by `bin/deploy-tasks-host.sh`; see the [README host-runner setup](../README.md#host-scope-tasks) for installation details.

```bash
bin/console deploytasks:generate:host
bin/console deploytasks:generate:host --dir=deploy/host-tasks/
```

**Options:**

| Option | Default | Description |
|---|---|---|
| `--dir` | `deploy/host-tasks/` | Target directory for the generated script |

The generated filename follows the pattern `deploy_task_<YYYYMMDD>_<HHMMSS>.sh` (e.g. `deploy_task_20260418_143022.sh`). The file is executable (`0750`) and contains a bash stub with `set -euo pipefail` and a `# TODO: implement` marker. Lexicographic filename ordering on disk drives execution order.

---

## deploytasks:rollup

Clear all execution records and mark every registered task as executed. Useful for fresh environments where the current state already incorporates all task effects, or for cleaning up stale history after old tasks have been removed.

```bash
bin/console deploytasks:rollup
bin/console deploytasks:rollup --no-interaction --force
bin/console deploytasks:rollup --group=predeploy
bin/console deploytasks:rollup --group=predeploy --group=postdeploy
```

**Options:**

| Option | Description |
|---|---|
| `--force` | Confirm the destructive action under `--no-interaction`. Alias: `--yes` |
| `--no-interaction` | Run without prompting; **requires** `--force` (or `--yes`), otherwise the command refuses to run |
| `--group=<name>` | Roll up only the given group slot(s); repeatable. Preserves records for other slots. Without this flag, every slot is rolled up and the whole table is reset. |

You are prompted for confirmation before proceeding. In CI, pass `--no-interaction --force` (a non-interactive run is refused without `--force`/`--yes`).

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
