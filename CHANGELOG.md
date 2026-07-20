# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Changed

- `deploytasks:rollup` now words an empty `--group` selection the same way as `deploytasks:run` ("No tasks matched the requested group(s)." instead of "No task slots matched the requested group(s)."), and `deploytasks:skip` deduplicates a repeated `--group` value in its error messages like the other commands already did.

### Fixed

- A run that lost its lock lease mid-run no longer reports "Run skipped: another process is already running." — which hid the tasks that did execute. It now reports the early stop and how many tasks ran before it (`RunResult` gained a `leaseLost` flag alongside `locked`).

### Security

- `deploytasks:reset` now strips terminal control bytes from a rejected `--group` value before echoing it back, so a malicious group name can no longer inject escape sequences into the operator's console.
- Filesystem storage no longer recognizes record files whose name ends in a newline — the record-name pattern is anchored to the absolute end of the filename (`\z`), matching the trailing-newline hardening already applied to task ids and group names.

## [0.3.0] - 2026-07-19

### Changed

- Renamed the container-scope generator command from `deploytasks:generate:container` to `deploytasks:generate`. The bare `deploytasks:<verb>` form now consistently means the container scope (matching `run`, `reset`, `rollup`, `skip`, …), while the host scope keeps its `deploytasks:host:<verb>` prefix. The old name no longer exists — update any deploy scripts, Makefiles, or CI that call it. See [UPGRADE.md](UPGRADE.md#upgrade-to-030).

## [0.2.0] - 2026-07-17

### Added

- Symfony 8 support — the bundle now installs on Symfony 8.x (requires PHP 8.4+ there); Symfony 6.4 LTS and 7.x remain supported unchanged.

## [0.1.0] - 2026-07-17

First release of DeployTasksBundle — a Symfony bundle for running one-time deploy
tasks (data migrations, cache warmups, seed scripts) from the console, tracking each
so it executes exactly once per environment.

### Added

- **Task contract** — implement `DeployTaskInterface` (`getDescription()`, `run(OutputInterface): TaskResult`) and declare the task with `#[AsDeployTask]` (id, priority, env, timeout, slowTaskThreshold, transactional, description, groups); tasks are discovered and tagged automatically. `run()` returns `SUCCESS`, `FAILURE`, or `SKIPPED`.
- **Task runner** — `deploytasks:run` executes pending tasks in a deterministic order (priority, then the date embedded in the task id, then registration order), with dry-run, single-task (`--id`), re-run-all, and group targeting, plus a live `[i/N]` progress line and per-task durations. A task returning `SKIPPED` (preconditions not met) is deferred: its slot stays pending and it is retried on the next deploy.
- **Storage backends** — filesystem (default; one JSON record per slot), Doctrine DBAL (SQLite/MySQL/MariaDB/PostgreSQL, composite `(id, group)` key, optional table auto-creation), and in-memory (testing). Plug in any backend through `TaskStorageInterface` / `TransactionalStorageInterface`; opt into DDL provisioning with `SchemaManageableInterface`.
- **Transactions** — a per-backend `transaction_mode` (`none`, `per_task`, `all_or_nothing`) wraps task execution in database transactions, up to an all-or-nothing whole-run transaction that rolls back every side effect on failure; individual tasks opt out of `per_task` wrapping via `#[AsDeployTask(transactional: false)]`.
- **Execution durations** — every run records how long the task took (milliseconds), shown by `deploytasks:status` and `deploytasks:show`.
- **Concurrency protection** — optional run lock via `symfony/lock`, refreshed between tasks so long deploys keep the lease; degrades to a no-op when the component is absent.
- **Task groups** — tasks declare one or more groups via `#[AsDeployTask(groups: …)]` to stage a deploy (e.g. `predeploy` / `postdeploy`); a multi-group task records one slot per group, and every command accepts a repeatable `--group` filter.
- **Lifecycle events** — `BeforeTaskEvent`, `AfterTaskEvent`, `TaskFailedEvent` (optional; requires `symfony/event-dispatcher`).
- **PSR-3 logging** — run- and task-level lifecycle logged through the application logger, auto-routed to a dedicated `soviann_deploy_tasks` Monolog channel when available and falling back to a `NullLogger` otherwise.
- **Console commands** — fourteen `deploytasks:*` commands covering execution (`run`), inspection (`status`, `show`), state operations (`skip`, `reset`, `rollup`), scaffolding (`generate:container`), schema management (`create-schema`), and the host-scope suite (`host:install`, `host:generate`, `host:skip`, `host:reset`, `host:rollup`, `host:config`).
- **Host-scope tasks** — a self-contained `bin/deploy-tasks-host.sh` runner executes tracked `*.sh` tasks on the host, outside the container, with a Symfony-compatible `.env` cascade and flock-based concurrency; `deploytasks:host:install` scaffolds the runner, the task directory, and the `.gitignore` block in one idempotent step.
- **Process helper** — `ProcessRunnerTrait` wraps `symfony/process` for tasks that shell out, streaming sanitized child output and enforcing a per-call timeout.
- **Extension points** — per-instance task ids (`TaskIdProviderInterface`) and custom ordering (`TaskSorterInterface`).
- **Configuration** — the `soviann_deploy_tasks` config tree covers storage, events, lock, generation, and host settings, with scalar shorthands (`storage: database`, `events: false`, `lock: false`) and a tunable slow-task warning threshold (global `slow_task_threshold` plus a per-task attribute override).
- **Requirements** — PHP 8.2+, Symfony 6.4 LTS or 7.x.

### Security

- **Storage path guards** — filesystem storage refuses to store execution records under a web-served document root, and generated-file paths (`deploytasks:generate:container`, `deploytasks:host:generate`, `--dir`) are canonicalized with symlinks resolved and validated against path traversal before anything is written.
- **Private, atomic records** — filesystem records are written atomically with `0700` directories and `0600` files, so a crashed save cannot leave a partial record and other local users cannot read deploy history.
- **Console output sanitization** — untrusted text rendered by the commands (task and process error output) is scrubbed of ANSI/C0/C1 control sequences, Unicode bidi and format characters (Trojan-Source-style reordering), and console formatter tags through a single `ConsoleSanitizer` pipeline; `ProcessRunnerTrait` applies the same scrub to streamed child output.
- **Destructive-command gates** — `deploytasks:reset`, `deploytasks:rollup`, and their host-scope equivalents prompt for confirmation and require an explicit `--force` when run non-interactively; `deploytasks:skip` warns before overwriting an existing execution record.
- **Strict identifier validation** — task ids and group names are validated against a strict allowlist (exact-line anchoring, no trailing-newline smuggling), rejected when they exceed the configured database column length (preventing silent truncation and task re-runs), and checked for case-insensitive collisions at container build or boot.
- **Credential-safe logging** — DBAL failure context is scrubbed of full exception objects before it reaches any log handler, so database credentials cannot leak into shared log sinks.
- **Lock hardening** — the run lock's lease is refreshed between tasks, a mid-run lock failure aborts the run cleanly instead of crashing it, and the TTL semantics are documented so operators size it against the longest single task.

[Unreleased]: https://github.com/Soviann/deploy-tasks-bundle/compare/v0.3.0...HEAD
[0.3.0]: https://github.com/Soviann/deploy-tasks-bundle/compare/v0.2.0...v0.3.0
[0.2.0]: https://github.com/Soviann/deploy-tasks-bundle/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/Soviann/deploy-tasks-bundle/releases/tag/v0.1.0
