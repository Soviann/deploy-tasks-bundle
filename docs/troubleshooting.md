# Troubleshooting / FAQ

Symptom-first reference for the errors and surprises contributors most often hit. Each entry names the exception or behaviour, explains *why* it happens, and points at the fix.

## My task is not picked up by `deploytasks:run`

Most common causes, in order:

1. **The class is not in a service-autoconfigured directory.** Tasks rely on Symfony's `autoconfigure: true` to receive the `soviann_deploy_tasks.task` tag automatically. Make sure the namespace is covered by `App\:` (or your own) `resource:` line in `config/services.yaml`.
2. **The task declares an `env` that does not match.** `#[AsDeployTask(env: 'prod')]` is silently skipped on `dev`. Compare the task's declared `env` against your current environment.
3. **You narrowed with `--group` and the task doesn't declare that group.** `--group=<name>` (repeatable) restricts the run to tasks declaring one of the listed groups — this excludes the default (ungrouped) slot too. Omit `--group` entirely to run every slot (the default slot and every declared group). See [creating-tasks.md → Group filtering](creating-tasks.md#group-filtering).
4. **The task already ran in this slot.** Use `deploytasks:show <id>` to see every recorded slot. `deploytasks:reset <id>` clears one execution record.

## `DuplicateTaskIdException: Two tasks resolve to the same id "..."`

Two registered task services produced the same task ID. Detection happens at two layers:

- **Compile time** — caught by the `RegisterTasksCompilerPass`, which checks the attribute `id` or, absent one, the ID derived from the class name. Tasks implementing `TaskIdProviderInterface` are skipped at compile time — their real ID only exists at runtime.
- **Runtime** — caught by `TaskRegistry` on boot. This catches duplicates that escape compile-time detection (tasks implementing `TaskIdProviderInterface`).

Fix it by setting an explicit `#[AsDeployTask(id: '...')]` on at least one of the two tasks. The recommended naming convention `task_YYYYMMDDHHMMSS_<snake_case>` makes accidental collisions almost impossible.

## `TaskNotFoundException: No task is registered with the id "..."`

Thrown by `deploytasks:run --id=<id>`, `deploytasks:show <id>`, `deploytasks:skip <id>`, `deploytasks:reset <id>` when the requested ID is not in the registry. Run `deploytasks:status` to see the resolved IDs as the bundle sees them — the printed value is the one you must pass on the CLI.

## `deploytasks:run --id=<id>` exits `2` (`Command::INVALID`) for a task excluded by its `env`

`--id=<id>` targeting a task whose `#[AsDeployTask(env: ...)]` excludes the runner's current environment throws `TaskEnvironmentMismatchException`, which the command reports as `Command::INVALID` (`2`) — the same code used for an `--id`/`--group` mismatch. Under `--require-some`, this case is treated as "no task matched the filters" instead, and exits `64` (`EX_USAGE`) — see [`deploytasks:run --require-some` exits 64](#deploytasksrun---require-some-exits-64-ex_usage) below.

## `IncompatibleStorageException` when setting `transaction_mode: per_task` or `all_or_nothing`

The active storage backend does not implement `TransactionalStorageInterface`. The filesystem backend never does (no transactions on disk). The only built-in backend that supports transactions is `DbalStorage`. For a custom backend, implement `TransactionalStorageInterface` and the bundle will detect it automatically. The check runs twice: at container build when the storage class is known at compile time, and again by `TaskRunner`'s constructor against the real instance, for a storage resolvable only at runtime.

The same exception is raised at container build when any task declares `#[AsDeployTask(transactional: true)]` while the active storage is non-transactional — the message names the task class. Remove the per-task flag or switch to a transactional storage. It is also raised for a mode/attribute mismatch: `transactional: true` under `transaction_mode: none`, or `transactional: false` under `transaction_mode: all_or_nothing` — see [`docs/storage.md` → Transaction mode](storage.md#transaction-mode).

## `AllOrNothingFailureException`

Raised when `transaction_mode: all_or_nothing` is set and any task fails — the runner rolls back the wrapping transaction and reports the failing task. This is a feature: it guarantees a partial deploy never leaves a half-applied state in storage. Switch to `per_task` or `none` if you want failed tasks to remain failed but successful ones to remain recorded.

## Tasks re-run after every container deploy (Docker, Kubernetes)

The default filesystem backend writes under `%kernel.project_dir%/var/deploy-tasks/`, which sits on an overlay filesystem on container platforms. The directory disappears on every pod restart or image rebuild, so the bundle thinks every task is pending again.

Pick one:

- Mount a volume at `%kernel.project_dir%/var/deploy-tasks/` (a `PersistentVolumeClaim` on Kubernetes, a named volume on Docker Compose).
- Switch to the database backend — `storage.type: database` writes to a durable SQL table.

Covered in [`storage.md` → Ephemeral filesystems](storage.md#ephemeral-filesystems-docker-kubernetes).

## `deploytasks:run` exits 75 (`EX_TEMPFAIL`)

Another `deploytasks:run` is currently holding the run lock. The bundle uses `symfony/lock` to prevent concurrent execution — only one process can run pending tasks at a time. The exit code 75 (`EX_TEMPFAIL`) is the standard sysexits.h code for *try again later* and is safe to interpret as "retry" in your CI / deploy scripts.

If the lock is stuck after a crashed run, inspect your lock store (file, Redis, …) — `symfony/lock`'s default file store keeps lock files under `sys_get_temp_dir()`.

## `deploytasks:run --require-some` exits 64 (`EX_USAGE`)

You combined `--id`, `--group`, or both, but no task in the registry matched the filter — this also covers an `--id` that isn't registered at all, and an `--id` that resolves to a task excluded by its declared `env`. `EX_USAGE` (`64`) signals a usage error so CI scripts can fail loudly instead of treating "0 tasks ran" as success. Drop `--require-some` if "no match" is acceptable.

## `deploytasks:status --filter-status=...` rejects my value

`--filter-status` accepts a case-insensitive comma-separated list of `RAN`, `FAILED`, `SKIPPED`, `PENDING`. Combining `--filter-status` with `--no-state` exits `Command::INVALID` because the two options ask for contradictory views. Use one or the other.

## Filesystem storage refuses to write — `StorageException: Refusing to store deploy-task records under a public web-root path`

The filesystem backend rejects any storage path whose normalised form contains a `public`, `public_html`, `web`, `html`, `htdocs`, `wwwroot`, or `httpdocs` segment (the regex lives in `FilesystemStorage::__construct()`). This is a defence-in-depth check: deploy task records embed timestamps and error messages, and writing them under a publicly served document root would expose them over HTTP. Move `storage.filesystem.path` outside the web-served directory — `%kernel.project_dir%/var/deploy-tasks` is the right place. The check is lexical — it does not resolve symlinks, so a path that reaches a docroot only via symlink is not detected.

## Group name validation — `\InvalidArgumentException` on `#[AsDeployTask(groups: ...)]`

Group names must match `AsDeployTask::GROUP_NAME_PATTERN` (`^[a-zA-Z0-9._-]+$`). Slashes, whitespace, accented characters, etc. are rejected. The constraint exists because group names are used verbatim as filename suffixes (`<id>@<group>.json`) and as DBAL primary-key column values; allowing arbitrary input there would invite filesystem path injection and broken queries. Pick a name in the allowed alphabet (`predeploy`, `post-deploy`, `db.warm`, …).

## `deploytasks:create-schema` is not registered

The command is registered only when `storage.type` is `database`. The filesystem backend has no schema, and custom backends are not wired to it even when they implement `SchemaManageable` — provision custom schemas yourself.

## My `getDescription()` is empty in `deploytasks:status`

`TaskDescriptionResolver` resolves descriptions in this order:

1. Non-empty return value of `DeployTaskInterface::getDescription()`.
2. The `description:` parameter on `#[AsDeployTask]`.
3. Empty string.

If both your method and the attribute are unset (or empty), you get an empty cell. Set one of them.

## My `TaskIdProviderInterface::getTaskId()` returns the wrong value at compile time

`getTaskId()` is an instance method — it is *not* called during the compiler pass, which only knows attribute `id`s and IDs derived from class names. `TaskIdProviderInterface` resolution happens at runtime via `TaskIdResolver`. If you need a deterministic compile-time ID, use `#[AsDeployTask(id: '...')]` instead of (or in addition to) `TaskIdProviderInterface`.

If both `getTaskId()` and `#[AsDeployTask(id: '...')]` return non-empty *different* values, the bundle triggers a `E_USER_WARNING` and `getTaskId()` wins.
