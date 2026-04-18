# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Host-scope deploy tasks: shell files under `deploy/host-tasks/` executed by the `bin/deploy-tasks-host.sh` runner (installed manually from `bin/deploy-tasks-host.sh.dist` until a Flex recipe ships). Tracked in a separate append-only log (`.deploy-tasks-host.log`). Supports Symfony `.env` cascade + `deploy-tasks-host.local.sh` overrides.
- Console command `deploytasks:generate:host` scaffolds a new host task file.
- `deploytasks:generate` renamed to `deploytasks:generate:container` (old name kept as alias — no breaking change).
- Contract layer: `DeployTaskInterface`, `TaskIdProviderInterface`, `TaskIdGeneratorInterface`, `TaskOrderResolverInterface`, `TaskStorageInterface`, `TransactionalStorageInterface`, value objects (`TaskExecution`, `TaskStatus`, `TaskResult`, `OrderedTaskCollection`), and `#[AsDeployTask]` attribute
- `TaskResult` enum cases: `SUCCESS`, `FAILURE`, `SKIPPED`, `LOCKED`
- `TaskStorageInterface` methods: `has`, `get`, `save`, `remove`, `removeAll`, `all`, `reset` — all scoped by `(taskId, ?group)`
- Storage backends: `FilesystemStorage` (default, JSON files, per-slot files), `DbalStorage` (Doctrine DBAL, composite PK `(id, task_group)`), `InMemoryStorage` (testing)
- Task registry with duplicate ID detection, environment filtering, and group filtering
- Task runner with ordered execution, dry-run mode, optional event dispatching, lock support, timeout tracking, per-task transaction wrapping, and all-or-nothing full-run transaction mode
- Task groups: tasks can declare one or more groups via `#[AsDeployTask(groups: ...)]`; multi-group tasks record one storage row per `(task, group)` slot. `--group` flag added to `run`, `status`, `skip`, `reset`, `rollup`
- Event system: `BeforeTaskEvent`, `AfterTaskEvent`, `TaskFailedEvent`
- Default priority-based task order resolver with date extraction from task IDs
- Console commands: `deploytasks:run`, `deploytasks:status`, `deploytasks:skip`, `deploytasks:reset`, `deploytasks:generate` (with `--dir`), `deploytasks:rollup`, `deploytasks:create-schema`
- Configurable `generate.directory` and `generate.template` (custom PHP template with placeholders)
- Exceptions: `DuplicateTaskIdException`, `TaskNotFoundException`, `StorageException`, `TaskGroupRequiredException`, `TaskGroupMismatchException`, `IncompatibleStorageException`
- Symfony bundle with full configuration tree, compiler pass for service validation (duplicate IDs, transactional custom storage aliasing, all-or-nothing compatibility check), and autoconfiguration
- Support for PHP 8.2+ and Symfony 6.4+/7.0+

[Unreleased]: https://github.com/soviann/deploy-tasks-bundle/compare/HEAD
