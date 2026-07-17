# Console Commands

Two generator commands scaffold deploy tasks: [`deploytasks:generate:container`](#deploytasksgeneratecontainer) for PHP classes running inside the Symfony container, and [`deploytasks:host:generate`](#deploytaskshostgenerate) for bash scripts running on the host outside the container.

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
| `--group=<name>` | Restrict execution to the given group slot; repeatable for a union (e.g. `--group=a --group=b`). Without this flag, every slot runs — the default slot and every declared group. |
| `--require-some` | Exit `64` (`EX_USAGE`) when no task matches the provided filters, distinguishing an empty selection from a successful noop. |

**Exit codes:** `0` on success; `1` if at least one task failed; `2` (`Command::INVALID`) when `--id` targets an unregistered task ID, when `--id` targets a task whose group declaration is incompatible with the supplied `--group` values, or when `--id` targets a task whose declared `env` excludes the current environment (without `--require-some`); `64` (`EX_USAGE`) when `--require-some` is set and no task matched the filters — this includes an unknown `--id` and an `--id` excluded by its declared `env`, both of which count as "no match" under `--require-some`; `75` (`EX_TEMPFAIL`) when the run lock is already held — CI pipelines should treat this as "retry recommended" rather than a genuine failure.

**Group semantics:**

- No `--group` → runs every slot: the default slot of ungrouped tasks and every declared group of grouped tasks.
- `--group=X` → narrows to tasks declaring `X`; one storage row per `(task, X)` slot. The default slot and other groups are excluded.
- Multi-group tasks run once per targeted slot in the same invocation — every declared group on a bare invocation, or the requested groups under `--group` (e.g. `--group=predeploy --group=postdeploy` executes the task twice, storing two rows).
- `--rerun-all` with `--group` only re-runs the targeted slots; other slots remain untouched.
- `--id` without `--group` targets every slot the task declares (same expansion as a bare run); add `--group` to narrow which slot(s) are recorded. `--id` with a `--group` value the task does not declare exits `2` (`Command::INVALID`).

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
| `--group=<name>` | Only display rows for the given group slot(s); repeatable. Without this flag, every slot's rows are shown — the default slot and every declared group. |
| `--filter-status=<list>` | Comma-separated statuses to display (`RAN`, `FAILED`, `SKIPPED`, `PENDING` — case-insensitive). Rejected when combined with `--no-state`. Failed slots are retried on the next run — use `PENDING,FAILED` to list everything the next `deploytasks:run` will execute (or `deploytasks:run --dry-run` for the authoritative preview). |

Multi-group tasks are displayed once per declared slot. The `Group` column shows the slot name; the default slot is rendered as `—`.

**Status values:**

| Value | Meaning |
|---|---|
| `pending` | Not yet executed |
| `ran` | Executed successfully |
| `failed` | Execution failed; will be retried on the next `deploytasks:run` |
| `skipped` | Manually marked as skipped via `deploytasks:skip` (a task returning `TaskResult::SKIPPED` records nothing — its slot stays `pending`) |

**Host tasks:** when the `host.directory` config path exists and contains at least one `*.sh` script, a separate "Host tasks" section is appended listing each script as `done` or `pending`. This is a read-only view onto [host-scope tasks](host-tasks.md) — `done` means the script's basename appears as a full line in the host runner's completion log (`host.log_path`), mirroring `bin/deploy-tasks-host.sh`'s own `grep -Fxq` check. The section is omitted entirely when the host directory doesn't exist. See [host-tasks.md](host-tasks.md#status-visibility) for the env-override caveat.

The host section obeys the display flags:

- `--no-state` suppresses it — its done/pending content *is* execution state.
- `--group` (any value) suppresses it — host tasks have no group concept.
- `--filter-status=PENDING` (alone) keeps it, restricted to pending rows.
- Any other `--filter-status` value (including lists like `PENDING,FAILED`) suppresses it — host tasks are only ever done or pending, so no other status can match.

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

**Exit codes:** `0` on success; `2` (`Command::INVALID`) when the task ID is not registered.

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
| `--group=<name>` | Target a specific group slot. Without this flag, every declared slot is skipped (the default slot for an ungrouped task, or every declared group for a grouped one). |

Use `deploytasks:reset` to re-enable a skipped task.

You are prompted for confirmation before proceeding (same convention as `deploytasks:host:skip`: reversible via `deploytasks:reset`, so it proceeds under `--no-interaction` without requiring `--force`). When a bare invocation resolves to more than one slot, a single confirmation names every targeted slot, with one overwrite warning per slot that already holds a record (a `ran` record calls out the erased execution history); declining or a bare Enter leaves every slot untouched.

**Exit codes:** `0` on success; `2` (`Command::INVALID`) when the task ID is not registered, or when `--group` names a group the task does not declare; `1` when the confirmation is declined.

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
| `--force` | Confirm the destructive action under `--no-interaction` |
| `--no-interaction` | Run without prompting; **requires** `--force`, otherwise the command refuses to run |
| `--group=<name>` | Reset only the given group slot; without this flag every slot recorded for the task is cleared |

If the task has no execution record, the command reports it is already pending and exits successfully without error. When a bare invocation would clear more than one recorded slot, a single confirmation names every slot about to be cleared; declining or a bare Enter leaves every slot untouched.

**Exit codes:** `0` on success (including the already-pending no-op); `2` (`Command::INVALID`) when the task ID is not registered, or when a non-interactive run omits `--force`; `1` when the confirmation is declined.

---

## deploytasks:host:skip

Host-scope equivalent of [`deploytasks:skip`](#deploytasksskip): marks a host task as done in the completion log without running its script. See [`docs/host-tasks.md`](host-tasks.md#managing-host-task-state) for the full contract.

```bash
bin/console deploytasks:host:skip deploy_task_20260418_143022
```

**Arguments:**

| Argument | Description |
|---|---|
| `id` | The host task ID to skip — the script's basename without `.sh` (required) |

You are prompted for confirmation before proceeding (same convention as `deploytasks:skip`: reversible via `deploytasks:host:reset`, so it proceeds under `--no-interaction` without requiring `--force`).

**Exit codes:** `0` on success (including the already-done no-op); `2` (`Command::INVALID`) when the host tasks directory or the `<id>.sh` script does not exist; `1` when the confirmation is declined; `75` (`EX_TEMPFAIL`) when a running `bin/deploy-tasks-host.sh` holds the host lock — retry once it finishes.

---

## deploytasks:host:reset

Host-scope equivalent of [`deploytasks:reset`](#deploytasksreset): removes a host task's completion-log entry so it is treated as pending and runs again on the next `bin/deploy-tasks-host.sh`. See [`docs/host-tasks.md`](host-tasks.md#managing-host-task-state).

```bash
bin/console deploytasks:host:reset deploy_task_20260418_143022
bin/console deploytasks:host:reset deploy_task_20260418_143022 --no-interaction --force
```

**Arguments:**

| Argument | Description |
|---|---|
| `id` | The host task ID to reset (required) |

**Options:**

| Option | Description |
|---|---|
| `--force` | Confirm the destructive action under `--no-interaction` |
| `--no-interaction` | Run without prompting; **requires** `--force`, otherwise the command refuses to run |

If the script exists but has no completion-log entry, the command reports it is already pending and exits successfully without error. An id matching neither a `<id>.sh` script nor a completion-log entry is rejected as unknown; a completion-log entry whose script has been deleted is removed anyway, with a warning.

**Exit codes:** `0` on success (including the already-pending no-op); `2` (`Command::INVALID`) when the host tasks directory doesn't exist, when the id matches neither a `<id>.sh` script nor a completion-log entry, or when a non-interactive run omits `--force`; `1` when the confirmation is declined; `75` (`EX_TEMPFAIL`) when a running `bin/deploy-tasks-host.sh` holds the host lock — retry once it finishes.

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
| `--namespace` | derived from `--dir` | Override the derived namespace entirely |

The generated class name is always `DeployTask<YYYYMMDDHHmmss>` (e.g. `DeployTask20260412143000`) — the command takes no positional argument. The task ID is auto-derived from the class name: the `DeployTask` prefix is stripped and the purely-numeric remainder prefixed with `task_` (e.g. `task_20260412143000`).

The generated file implements `DeployTaskInterface`, includes the `#[AsDeployTask]` attribute, and provides a stub `run()` method. Rename the class after generation if you want a more descriptive name — the ID derivation also handles `SeedCategoriesTask` and similar CamelCase names.

The namespace is built by applying `ucfirst` to each path segment of the target directory; use CamelCase directory names (e.g. `src/DeployTasks/Task/`) to produce a CamelCase namespace (e.g. `App\DeployTasks\Task`). Lowercase segments remain lowercase apart from their first letter. When the directory starts with `src/`, the leading segment is rewritten to the configured root namespace — `soviann_deploy_tasks.generate.root_namespace` (default `App`, mirroring [symfony/maker-bundle](https://symfony.com/bundles/SymfonyMakerBundle/current/index.html#root-namespace)); set it to your `composer.json` PSR-4 root if that is not `App`. Pass `--namespace` to override the derived namespace entirely.

---

## deploytasks:host:generate

Generate a new host-scope deploy task script. Host scripts are plain bash files executed outside the container by `bin/deploy-tasks-host.sh`; see [`docs/host-tasks.md`](host-tasks.md) for installation details.

```bash
bin/console deploytasks:host:generate
bin/console deploytasks:host:generate --dir=deploy/host-tasks/
```

**Options:**

| Option | Default | Description |
|---|---|---|
| `--dir` | `host.directory` config (default `deploy/host-tasks/`) | Target directory for the generated script |

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
| `--force` | Confirm the destructive action under `--no-interaction` |
| `--no-interaction` | Run without prompting; **requires** `--force`, otherwise the command refuses to run |
| `--group=<name>` | Roll up only the given group slot(s); repeatable. Preserves records for other slots. Without this flag, every slot is rolled up and the whole table is reset. |

You are prompted for confirmation before proceeding. In CI, pass `--no-interaction --force` (a non-interactive run is refused without `--force`).

If the storage backend implements `TransactionalStorageInterface`, the reset and re-mark operations are wrapped in a single transaction.

**Exit codes:** `0` on success (including the nothing-to-roll-up no-ops); `2` (`Command::INVALID`) when a non-interactive run omits `--force`; `1` when the confirmation is declined.

---

## deploytasks:host:rollup

Host-scope equivalent of [`deploytasks:rollup`](#deploytasksrollup): appends every pending host task's id to the completion log, marking them all as done without running their scripts. See [`docs/host-tasks.md`](host-tasks.md#managing-host-task-state).

```bash
bin/console deploytasks:host:rollup
bin/console deploytasks:host:rollup --no-interaction --force
```

**Options:**

| Option | Description |
|---|---|
| `--force` | Confirm the destructive action under `--no-interaction` |
| `--no-interaction` | Run without prompting; **requires** `--force`, otherwise the command refuses to run |

An empty host tasks directory, or a directory where every script is already marked done, produces a warning/note and exits successfully without prompting.

**Exit codes:** `0` on success (including the nothing-to-roll-up no-ops); `2` (`Command::INVALID`) when the host tasks directory doesn't exist, or when a non-interactive run omits `--force`; `1` when the confirmation is declined; `75` (`EX_TEMPFAIL`) when a running `bin/deploy-tasks-host.sh` holds the host lock — retry once it finishes.

---

## deploytasks:host:config

Render (or write) the host runner's `DEPLOY_TASKS_HOST_*` environment exports derived from `soviann_deploy_tasks.host.*`, so the bash runner (`bin/deploy-tasks-host.sh`) and the PHP-side host commands ([`deploytasks:status`](#deploytasksstatus), [`deploytasks:host:skip`](#deploytaskshostskip), etc.) always agree on the host tasks directory, completion log, and lock file.

```bash
bin/console deploytasks:host:config
bin/console deploytasks:host:config --write
```

**Options:**

| Option | Description |
|---|---|
| `--write` | Write the exports to `deploy-tasks-host.local.sh` at the project root (sourced by `bin/deploy-tasks-host.sh` on every run) instead of printing them |

Without `--write`, the command prints the `export DEPLOY_TASKS_HOST_DIR=…` / `DEPLOY_TASKS_HOST_STORAGE=…` / `DEPLOY_TASKS_HOST_LOCK=…` lines to stdout. Paths under the project directory are emitted project-relative, so they stay correct even when the PHP container mounts the project at a different absolute path than the host; an absolute path outside the project directory triggers a warning that it must still be valid on the host.

With `--write`, the command refuses to overwrite a `deploy-tasks-host.local.sh` that wasn't generated by this command (no `# Generated by deploytasks:host:config` marker) — it reports an error and prints the exports so they can be merged in by hand instead of clobbering a hand-written file. Re-run `--write` after changing any `soviann_deploy_tasks.host.*` value; `deploytasks:status` warns when the written file has drifted from the current config.

**Exit codes:** `0` on success; `1` (`Command::FAILURE`) when a value cannot be represented as a single-quoted shell literal, when `--write` is used but the project directory cannot be located, or when the target file already exists and was not generated by this command.

See [`docs/host-tasks.md`](host-tasks.md#keeping-the-runner-and-the-php-config-in-sync) ("Keeping the runner and the PHP config in sync") for the full workflow.

---

## deploytasks:create-schema

Provision the schema of the configured storage backend — for the built-in database storage, the table used by the DBAL backend. Registered whenever the configured storage implements `SchemaManageableInterface`: `storage.type: database` always qualifies, and a custom backend qualifies by implementing the interface (see [`docs/storage.md`](storage.md#custom)).

```bash
bin/console deploytasks:create-schema
bin/console deploytasks:create-schema --dump-sql
```

**Options:**

| Option | Description |
|---|---|
| `--dump-sql` | Output the SQL statement instead of executing it (e.g. for use in a Doctrine migration) |

Re-running the command is safe: `SchemaManageableInterface` implementations are idempotent — for the database storage, the table is created with `CREATE TABLE IF NOT EXISTS` and the SQL output uses platform-aware identifier quoting.
