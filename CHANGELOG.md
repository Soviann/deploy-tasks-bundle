# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

First release of DeployTasksBundle — a Symfony bundle for running one-time deploy
tasks (data migrations, cache warmups, seed scripts) from the console, tracking each
so it executes exactly once per environment.

### Added

- **Task contract** — `DeployTaskInterface` (`getDescription()`, `run(OutputInterface): TaskResult`) with the `#[AsDeployTask]` attribute (id, priority, env, timeout, transactional, description, groups) and automatic service tagging. `TaskResult` return enum: `SUCCESS`, `FAILURE`, `SKIPPED`, `LOCKED`.
- **Task runner** — executes pending tasks in a deterministic order (priority, then the date embedded in the task id, then registration order), with dry-run, single-task (`--id`), re-run-all, and per-run group targeting, plus a live `[i/N]` progress line and per-task duration.
- **Storage backends** — filesystem (default; one JSON record per slot, `0700`/`0600` permissions, atomic writes, refusal to store under a web-served path), Doctrine DBAL (SQLite/MySQL/MariaDB/PostgreSQL, composite `(id, group)` key, optional table auto-creation), and in-memory (testing). Plug in any backend through `TaskStorageInterface` / `TransactionalStorageInterface`; opt into DDL provisioning with `SchemaManageable`.
- **Transactions** — optional per-task database transactions and an all-or-nothing whole-run transaction that rolls back every side effect on failure.
- **Concurrency protection** — optional run lock via `symfony/lock`, refreshed between tasks so long deploys keep the lease; degrades to a no-op when the component is absent.
- **Task groups** — tasks declare one or more groups via `#[AsDeployTask(groups: …)]` to stage a deploy; a multi-group task records one slot per group.
- **Lifecycle events** — `BeforeTaskEvent`, `AfterTaskEvent`, `TaskFailedEvent` (optional; requires `symfony/event-dispatcher`).
- **PSR-3 logging** — run- and task-level lifecycle logged through the application logger, auto-routed to a dedicated `soviann_deploy_tasks` Monolog channel when available and falling back to a `NullLogger` otherwise. DBAL exception context is scrubbed of connection credentials before it reaches any log handler.
- **Console commands** — `deploytasks:run`, `:status`, `:show`, `:skip`, `:reset`, `:rollup`, `:generate:container`, and `:create-schema` (registered only for database storage).
- **Host-scope tasks** — a self-contained `bin/deploy-tasks-host.sh` runner executes tracked `*.sh` tasks on the host, outside the container, with a Symfony-compatible `.env` cascade and flock-based concurrency. Managed from the console via `deploytasks:generate:host`, `:skip:host`, `:reset:host`, `:rollup:host`, and `:host:config`.
- **Extension points** — custom task-id generation (`TaskIdGeneratorInterface`), per-instance ids (`TaskIdProviderInterface`), and custom ordering (`TaskSorterInterface`).
- **Process helper** — `ProcessRunnerTrait` wraps `symfony/process` for tasks that shell out, streaming sanitized child output and enforcing a timeout.
- **Configuration** — the `soviann_deploy_tasks` config tree covers storage, events, lock, generate, and host settings, with scalar shorthands (`storage: database`, `events: false`, `lock: false`). Task ids and group names are validated against a strict allowlist, and untrusted text is stripped of terminal control sequences before display.
- **Requirements** — PHP 8.2+, Symfony 6.4 LTS or 7.x.

### Changed

- **Breaking (pre-1.0):** `deploytasks:run` (and `status`/`skip`/`reset`/`run --id`) now operate on all slots when no `--group` is given — ungrouped and every grouped slot — matching `deploytasks:rollup`. `--group` narrows as before. `TaskGroupRequiredException` is removed.

### Fixed

- A deploy task running longer than the configured lock TTL no longer crashes the run with an uncaught lock error.
- A transactional task whose result fails to persist now rolls back its own side effects instead of silently re-running on the next deploy.
- A rollup interrupted by a storage failure on a non-transactional backend no longer wipes execution history, which would have silently re-run already-applied tasks on the next deploy.
- A filesystem write failure during a task save now reports through the same `StorageException` contract as the database backend, instead of leaking a raw filesystem exception.
- `#[AsDeployTask]` now rejects an `env` that would silently disable the task (empty array or non-string entries) at construction, instead of letting it match no environment and become a silent no-op.
- A repeated `--group` value on `deploytasks:run` (e.g. `--group=a --group=a`) no longer runs the same slot twice, inflating the counters and double-persisting its storage row. `#[AsDeployTask(groups: …)]` now also rejects a duplicate declared group name at construction instead of silently accepting it.
- A database error raised by a task's own queries inside a transaction now surfaces unchanged, instead of being relabeled as a generic "Transaction failed" storage error that hid the real cause.
- Resetting filesystem storage, or clearing all slots for one task, no longer risks leaving a stray record behind: the record set is now captured before any file is deleted, instead of deleting while still walking the live directory listing.
- `deploytasks:skip` no longer silently erases an existing execution record (especially a `Ran` one) when re-skipping a slot: the confirmation prompt now warns and requires an explicit "yes" before overwriting; `--no-interaction` still proceeds since skip remains reversible via `deploytasks:reset`.
- A custom id generator whose class cannot be resolved at compile time (e.g. a factory-built service) no longer fails the container build with false "Duplicate deploy task ID" errors: compile-time id validation now skips the tasks that depend on the generator instead of checking ids the default generator would have produced.
- `deploytasks:skip:host` and `deploytasks:rollup:host` no longer corrupt a hand-edited host completion log whose final line lost its newline: the append now heals the missing terminator instead of merging the last recorded id and the first appended one into a single line the host runner could never match again.
- `deploytasks:rollup:host` no longer double-marks a task completed concurrently (by `deploytasks:skip:host` or a finishing host run) while its confirmation prompt was open: the pending set is now recomputed under the host lock right before the append, so the completion log gets no duplicate line and the reported count only covers tasks the rollup actually marked.
- `deploytasks:status` no longer reports a false "config drifted" warning for a `deploy-tasks-host.local.sh` saved with CRLF line endings (e.g. edited on Windows): the generated-file parser now tolerates the trailing `\r`.
- Database storage now rejects a task id or group name longer than its configured column (e.g. a runtime id from `TaskIdProviderInterface`, which compile-time validation cannot see) before the query runs, instead of letting a lenient database (e.g. MySQL in non-strict mode) silently truncate the stored key — which made the pending check miss it and re-run the task on every deploy.
- Two tasks whose ids differ only by letter case (e.g. `Seed_Users` vs `seed_users`) now fail fast at container build or boot, instead of silently sharing one execution record on a case-insensitive storage backend (MySQL `*_ci` collations, APFS/NTFS file names) — which made one of the tasks never run. `#[AsDeployTask(groups: …)]` likewise rejects two declared groups on the same task that differ only by case.
- Two tasks generated on the same day (ids carrying a `YYYYMMDDHHIISS` timestamp) now order by their full timestamp instead of falling back to registration order, which could run them out of the order they were created in.
- `deploytasks:run` no longer reports "No deploy tasks registered." when tasks exist but every one of them is restricted to a different environment (e.g. all `prod`-only tasks under `APP_ENV=dev`): it now names the count and the environment, so an operator can tell a filtered-out run apart from a broken discovery.
- A `transaction_mode` of `per_task` or `all_or_nothing` on a storage backend that cannot roll back is now refused before any task runs, instead of running the whole deploy unwrapped behind a single log warning — which left a failed run's earlier tasks applied with no way to undo them. The container build already rejected this pairing whenever the storage class was resolvable; a storage whose class only exists at runtime (a synthetic service, a child definition) now hits the same refusal, with the same message, from the runner itself.
- Storage backends no longer disagree on edge-case input: an empty-string group name is now rejected by all three backends with the same clear error (the database and in-memory backends used to silently alias it to the default slot), and database storage now truncates an over-long task error message (multibyte-safe, marked `[truncated]`) instead of risking losing the whole execution record to a strict database. The `findByTaskId()` ordering contract is now documented (unordered at the interface level; each backend documents its own natural order), and the lock TTL description no longer overstates safety: the lease is refreshed between tasks, so the TTL must outlast the longest single task, not the whole deploy.

### Security

- The web-root storage guard no longer refuses valid paths under `/var/www/html` and now recognizes additional public roots.
- `#[AsDeployTask]` no longer accepts a task id or group name with a trailing newline (e.g. `"abc\n"`): `TASK_ID_PATTERN` and `GROUP_NAME_PATTERN` now anchor with `\z` instead of `$`, which PCRE would otherwise match just before a trailing `\n` — unifying with the host-log path, which already treats ids as exact lines.
- A hostile task or process error message can no longer smuggle console formatter tags (e.g. an `<href=…>` terminal-hyperlink or color spoof) into `deploytasks:run`, `:status`, or `:show` output: every untrusted text rendered in a formatter-interpreting context is now both stripped of control bytes and tag-escaped through a single `ConsoleSanitizer::sanitizeForFormatter()` helper, so the two halves of the protection can no longer be applied separately.
- A hostile task or process error message can no longer use Unicode bidi overrides (e.g. U+202E) or isolates (U+2066-U+2069) to visually reorder `deploytasks:run`/`:status`/`:show` terminal output Trojan-Source-style: `ConsoleSanitizer` now also strips Unicode format characters (`\p{Cf}`), the U+2028/U+2029 line/paragraph separators, and C1 control characters (e.g. U+0085), on top of the existing C0/DEL stripping.

[Unreleased]: https://github.com/Soviann/deploy-tasks-bundle/compare/fbba7bf...HEAD
