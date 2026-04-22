# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Security

- `deploytasks:generate:container` and `deploytasks:generate:host` now reject `--dir` values outside an allowlist of letters, digits, slash, underscore, and dash. The guard runs before any path resolution or namespace derivation, blocking namespace-injection payloads (`<?php`, `;`, whitespace, dots) and path-traversal inputs that escape their starting point after canonicalisation (`../evil`). Rejected inputs surface as `Command::FAILURE` with an `Invalid --dir value` error.
- `bin/deploy-tasks-host.sh` now validates the resolved `APP_ENV` (from positional argument, environment, or the `dev` default) against the allowlist `^[a-zA-Z0-9_-]+$` immediately after resolution. Path-traversal values like `../../tmp/foo` that previously let `_load_env ".env.$APP_ENV"` source arbitrary files are rejected with a non-zero exit and an `Invalid APP_ENV value` message on stderr before any env loading happens.

### Fixed

- `all_or_nothing` runs no longer swallow failures silently. When the wrapping transaction rolls back, `TaskRunner::runAll()` now logs the failure at `error` level (with the original throwable in the log context) and rethrows it, so CLI callers and upstream handlers see the real cause instead of an opaque `RunResult(failed: 1)`.
- `FilesystemStorage` now wraps corrupted `status` values in a `StorageException` instead of letting the raw `\ValueError` from `TaskStatus::from()` escape, matching `DbalStorage`'s behaviour. The original `\ValueError` is preserved as `getPrevious()`.
- `deploytasks:generate:container` and `deploytasks:generate:host` now raise a `Symfony\Component\Filesystem\Exception\IOException` (extends `\RuntimeException`) when writing the generated file fails (e.g. non-writable target directory) instead of reporting success with a missing file.
- `deploytasks:generate:container` now resolves the target directory against the injected `projectDir` before checking for an existing file, creating the directory, and writing. Running the command from a working directory different from `projectDir` no longer misses pre-existing files, nor writes the generated class into the wrong location. The duplicate-file error now displays the absolute resolved path.

### Changed

- `symfony/filesystem` is now a runtime requirement (moved from `require-dev` to `require`). The generator commands and `FilesystemStorage` now use it internally. Task-result files are written atomically via `Filesystem::dumpFile()` (temp-file + rename), so readers no longer observe half-written JSON on concurrent writes. The `LOCK_EX` advisory lock on the task-result write site is preserved deliberately for writer-vs-writer serialisation when the optional `symfony/lock` dependency isn't installed.
- `DefaultTaskIdGenerator` now prefixes purely-numeric class-name remainders with `task_` (e.g. `DeployTask20260412143000` → `task_20260412143000`). Aligns the default generator output with the recommended `task_YYYYMMDDHHMMSS_…` naming convention. **Breaking** (pre-1.0, per bundle policy: MINOR bump). Existing tasks that relied on the non-prefixed numeric ID can preserve it via `#[AsDeployTask(id: …)]` or `TaskIdProviderInterface`.
- `getDescription()` now falls back to `#[AsDeployTask(description: …)]` when it returns an empty string, mirroring the `id` resolution rule (interface method wins when non-empty, attribute is the fallback).
- **Breaking (pre-1.0, per bundle policy: MINOR bump).** Renamed the task-sorting extension point and its namespace/directory for clarity — the component sorts tasks, it does not resolve anything. Moved `src/Ordering/` → `src/Sorting/` with namespace `Soviann\DeployTasksBundle\Sorting`. Renamed `TaskOrderResolverInterface` → `TaskSorterInterface`, `DefaultTaskOrderResolver` → `DefaultTaskSorter`, `OrderedTaskCollection` → `SortedTaskCollection`, method `resolve()` → `sort()`, config key `deploy_tasks.order_resolver` → `deploy_tasks.sorter`, service ID `deploy_tasks.order_resolver` → `deploy_tasks.sorter`. See `UPGRADE.md` for the full migration mapping.
- **Breaking (pre-1.0, per bundle policy: MINOR bump).** Renamed trait `RunsProcesses` → `ProcessRunnerTrait` (matches Symfony `*Trait` convention) and reshaped `runProcess()` to accept a caller-built `Process` instead of proxying its constructor arguments. Callers now pass `new Process([...], cwd: ..., timeout: ...)` and get full access to the `Process` API (PTY, `fromShellCommandline`, input streams). File renamed `src/RunsProcesses.php` → `src/ProcessRunnerTrait.php`. See `UPGRADE.md`.

### Added

- PSR-3 logging from the task runner — run/task lifecycle (start, success, skip, failure, timeout, lock) emitted at `info` / `warning` / `error` levels through the configured logger. `NullLogger` fallback when the application has no logger.
- Monolog channel `deploy_tasks` automatically registered when `symfony/monolog-bundle` is installed; no-op otherwise. Route it to a dedicated handler via standard `monolog.yaml`.
- New config option `deploy_tasks.logger` to point the runner at any PSR-3 service.
- Host-scope deploy tasks: shell files under `deploy/host-tasks/` executed by the `bin/deploy-tasks-host.sh` runner (installed manually from `bin/deploy-tasks-host.sh.dist` until a Flex recipe ships). Tracked in a separate append-only log (`.deploy-tasks-host.log`). Supports Symfony `.env` cascade + `deploy-tasks-host.local.sh` overrides.
- Console command `deploytasks:generate:host` scaffolds a new host task file.
- `deploytasks:generate` renamed to `deploytasks:generate:container` (old name kept as alias — no breaking change).
- Contract layer: `DeployTaskInterface`, `TaskIdProviderInterface`, `TaskIdGeneratorInterface`, `TaskSorterInterface`, `TaskStorageInterface`, `TransactionalStorageInterface`, value objects (`TaskExecution`, `TaskStatus`, `TaskResult`, `SortedTaskCollection`), and `#[AsDeployTask]` attribute
- `TaskResult` enum cases: `SUCCESS`, `FAILURE`, `SKIPPED`, `LOCKED`
- `TaskStorageInterface` methods: `has`, `get`, `save`, `remove`, `removeAll`, `all`, `reset` — all scoped by `(taskId, ?group)`
- Storage backends: `FilesystemStorage` (default, JSON files, per-slot files), `DbalStorage` (Doctrine DBAL, composite PK `(id, task_group)`), `InMemoryStorage` (testing)
- Task registry with duplicate ID detection, environment filtering, and group filtering
- Task runner with ordered execution, dry-run mode, optional event dispatching, lock support, timeout tracking, per-task transaction wrapping, and all-or-nothing full-run transaction mode
- Task groups: tasks can declare one or more groups via `#[AsDeployTask(groups: ...)]`; multi-group tasks record one storage row per `(task, group)` slot. `--group` flag added to `run`, `status`, `skip`, `reset`, `rollup`
- Event system: `BeforeTaskEvent`, `AfterTaskEvent`, `TaskFailedEvent`
- Default priority-based task sorter with date extraction from task IDs
- Console commands: `deploytasks:run`, `deploytasks:status`, `deploytasks:skip`, `deploytasks:reset`, `deploytasks:rollup`, `deploytasks:create-schema`
- Configurable `generate.directory` and `generate.template` (custom PHP template with placeholders)
- Exceptions: `DuplicateTaskIdException`, `TaskNotFoundException`, `StorageException`, `TaskGroupRequiredException`, `TaskGroupMismatchException`, `IncompatibleStorageException`
- Symfony bundle with full configuration tree, compiler pass for service validation (duplicate IDs, transactional custom storage aliasing, all-or-nothing compatibility check), and autoconfiguration
- Support for PHP 8.2+ and Symfony 6.4+/7.0+

[Unreleased]: https://github.com/soviann/deploy-tasks-bundle/compare/HEAD
