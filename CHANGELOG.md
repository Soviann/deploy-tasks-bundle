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

### Fixed

- A deploy task running longer than the configured lock TTL no longer crashes the run with an uncaught lock error.
- A transactional task whose result fails to persist now rolls back its own side effects instead of silently re-running on the next deploy.
- A rollup interrupted by a storage failure on a non-transactional backend no longer wipes execution history, which would have silently re-run already-applied tasks on the next deploy.
- A filesystem write failure during a task save now reports through the same `StorageException` contract as the database backend, instead of leaking a raw filesystem exception.
- `#[AsDeployTask]` now rejects an `env` that would silently disable the task (empty array or non-string entries) at construction, instead of letting it match no environment and become a silent no-op.
- A repeated `--group` value on `deploytasks:run` (e.g. `--group=a --group=a`) no longer runs the same slot twice, inflating the counters and double-persisting its storage row. `#[AsDeployTask(groups: …)]` now also rejects a duplicate declared group name at construction instead of silently accepting it.
- A database error raised by a task's own queries inside a transaction now surfaces unchanged, instead of being relabeled as a generic "Transaction failed" storage error that hid the real cause.

[Unreleased]: https://github.com/Soviann/deploy-tasks-bundle/compare/fbba7bf...HEAD
