# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

DeployTasksBundle is a Symfony bundle for running one-time deploy tasks (data migrations, cache warmups, seed scripts) via CLI. Tasks are tracked so they execute only once per environment. Filesystem storage by default; Doctrine DBAL or fully custom backends supported. Requires PHP 8.2+ and Symfony 6.4+/7.0+.

## Architecture

Single namespace `Soviann\DeployTasksBundle\` mapped to `src/`. Flat layout with role-based and domain-based folders.

### Root (`src/`)
Primary public surface — matches DoctrineFixturesBundle pattern.

- `DeployTaskInterface` — task contract: `getDescription(): string`, `run(OutputInterface): TaskResult`
- `TaskResult` — enum returned by `run()`: `SUCCESS`, `FAILURE`, `SKIPPED`, `LOCKED`
- `DeployTasksBundle` — `AbstractBundle`. `configure()` builds the config tree; `loadExtension()` registers services; `build()` autoconfigures `DeployTaskInterface` with tag `deploy_tasks.task`.

### Role-based folders

**`Attribute/`**
- `AsDeployTask` — task metadata (id, priority, env, timeout, transactional, description, groups). Static `AsDeployTask::of()` is the **single attribute reader**; `AsDeployTask::groupsOf()` returns the declared groups as `list<string>|null`.

**`Command/`** — 8 console commands (`Deploy*Command.php`).

**`DependencyInjection/Compiler/`**
- `RegisterTasksCompilerPass` — collects tagged tasks, performs compile-time duplicate-ID detection (skipped when the generator's static method returns null), and wires optional `event_dispatcher` and `lock.factory` into the runner.

**`Event/`**
- `BeforeTaskEvent`, `AfterTaskEvent`, `TaskFailedEvent` — all carry `string $taskId`.

**`Exception/`** — 6 `*Exception.php` classes.

### Domain-based folders

**`Identifier/`** — task ID handling
- `TaskIdGeneratorInterface` — service: `generate(class-string): string` plus static `generateStatic()` for compile time
- `TaskIdProviderInterface` — opt-in on tasks to supply a dynamic ID via `getTaskId(): string`
- `DefaultTaskIdGenerator` — `@internal`. FQCN → snake_case (strips `Task`/`DeployTask` prefix/suffix); prefixes a purely-numeric remainder with `task_` to match the recommended naming convention.
- `TaskIdResolver` — `@internal`. Resolution order: `TaskIdProviderInterface` > `AsDeployTask::$id` > generator

**`Ordering/`** — execution order
- `TaskOrderResolverInterface` — controls task execution order via `resolve(array): OrderedTaskCollection`
- `OrderedTaskCollection` — immutable, variadic-typed collection of `DeployTaskInterface`
- `DefaultTaskOrderResolver` — sort: priority DESC → date-from-id ASC → stable original order

**`Runner/`** — discovery and execution
- `TaskRegistry` — holds tagged tasks, env filtering, duplicate detection
- `TaskRunner` — orchestrates execution: ordering, storage tracking, optional events/locking/transactions
- `RunResult` — readonly: `$ran`, `$skipped`, `$failed`, `$locked`. `isSuccessful()`.
- `TaskOutcome` — per-task outcome value object

**`Storage/`** — persistence
- `TaskStorageInterface` — `has()`, `get()`, `save()`, `remove()`, `removeAll()`, `all()`, `reset()`. All lookups scoped by `(taskId, ?group)`.
- `TransactionalStorageInterface` — extends storage, adds `transactional(\Closure): mixed`
- `TaskExecution` — readonly value object: id, status, executedAt, error, group
- `TaskStatus` — enum: `Ran`, `Failed`, `Skipped`
- `Dbal\DbalStorage` — implements `TransactionalStorageInterface`. Instance `getCreateTableSql()`, `createSchema()`. Composite PK `(id, task_group)`. SQLite/MySQL/PostgreSQL.
- `Dbal\DbalStorageConfiguration` — table/column names DTO
- `Filesystem\FilesystemStorage` — JSON file per `(task, group)` slot with `LOCK_EX`. Default slot → `<id>.json`; grouped slot → `<id>@<slug>.json`. Warns if path traverses `/public/`.
- `InMemory\InMemoryStorage` — array-backed storage for tests

## Configuration

Root key `deploy_tasks:`.

```yaml
deploy_tasks:
    id_generator: ~                         # service ID or null (default: DefaultTaskIdGenerator)
    order_resolver: ~                       # service ID or null (default: DefaultTaskOrderResolver)
    default_timeout: 300                    # seconds (>= 0)
    storage:
        type: filesystem                    # filesystem | database | custom
        filesystem:
            path: '%kernel.project_dir%/var/deploy-tasks'
            transactional: false            # ignored — filesystem has no transactions
            all_or_nothing: false           # ignored — filesystem has no transactions
        database:
            connection: default             # DBAL connection name
            table: deploy_task_executions
            auto_create_table: true         # auto-init schema on first use
            id_column: id
            id_column_length: 255           # >= 1
            status_column: status
            executed_at_column: executed_at
            error_column: error
            transactional: true             # per-task transaction wrapper
            all_or_nothing: true            # single transaction around the whole run
        custom:
            service: ~                      # service ID, required when type=custom
            transactional: false            # requires TransactionalStorageInterface
            all_or_nothing: false           # requires TransactionalStorageInterface
    events:
        enabled: true
    lock:
        enabled: true
    generate:
        directory: src/DeployTasks/Task/    # default output directory for `deploytasks:generate`
        template: ~                         # path to a custom PHP template
```

Validation: `type: database` requires `doctrine/dbal` at compile time. `type: custom` requires `storage.custom.service`. Per-task `#[AsDeployTask(transactional: false)]` overrides the storage-level `transactional` flag.

## Service Registration

### Autoconfiguration
Any class implementing `DeployTaskInterface` is automatically tagged `deploy_tasks.task`.

### Attribute
`#[AsDeployTask(id, priority, env, timeout, transactional, description, groups)]` provides task metadata. `AsDeployTask::of($task)` is the only attribute reader in the codebase. The `description` attribute is the fallback when `getDescription()` returns an empty string — same pattern as `id` resolution.

### Service Aliases (autowirable)
- `TaskStorageInterface` → active storage backend
- `TransactionalStorageInterface` → active storage when it supports transactions
- `TaskIdGeneratorInterface` → configured or default generator
- `TaskOrderResolverInterface` → configured or default resolver
- `TaskRegistry`, `TaskRunner` → public

## Console Commands

| Command | Purpose |
|---|---|
| `deploytasks:run [--dry-run] [--force] [--id=<taskId>]` | Execute pending tasks. `--force` re-runs all already-executed tasks; `--id` targets one. |
| `deploytasks:status [--no-state]` | Table of registered tasks with their execution state. |
| `deploytasks:skip <id>` | Record a task as `Skipped` without running it. |
| `deploytasks:reset <id>` | Remove a task's execution record. Interactive unless `--no-interaction`. |
| `deploytasks:rollup` | Clear execution history and mark all registered tasks as `Ran`. |
| `deploytasks:generate:container [--dir=...]` (alias: `deploytasks:generate`) | Create a `DeployTask<YYYYMMDDHHIISS>.php` container-scope task stub. |
| `deploytasks:generate:host [--dir=...]` | Create a `deploy_task_<YYYYMMDD>_<HHIISS>.sh` host-scope task stub. |
| `deploytasks:create-schema` | Emit/execute the SQL to create the DBAL storage table. Registered only when `storage.type: database`. |

## Development Commands

```bash
vendor/bin/phpunit                        # all tests
vendor/bin/phpunit --testsuite Unit       # unit tests only
vendor/bin/phpunit --testsuite Functional # functional tests only
vendor/bin/phpstan analyse                # static analysis (level 9)
vendor/bin/php-cs-fixer fix               # auto-fix code style
vendor/bin/php-cs-fixer fix --dry-run     # check code style
```

## Coding Standards

- `@Symfony` CS Fixer ruleset, PHPStan level 9 — never lower
- All classes `final` unless designed for extension; properties `readonly` where possible
- Method order: `__construct` → public → protected → private (`setUp`/`tearDown` first in tests)

Additional stylistic rules (backslash-prefix native functions, Yoda conditions, etc.) are enforced by CS Fixer — see `CONTRIBUTING.md` for the contributor-facing list.

## Testing Patterns

- **Unit tests** (`tests/Unit/`): components in isolation. `InMemoryStorage` for runner; sample tasks live in `tests/Fixtures/`.
- **Functional tests** (`tests/Functional/`): boot a minimal `TestKernel` with the bundle. Verify command output, service wiring, config tree.
- **Fixtures** (`tests/Fixtures/`): shared sample task and storage classes used by both suites.

## Extension Points

| Need | Touch |
|---|---|
| New storage backend | Implement `TaskStorageInterface` (or `TransactionalStorageInterface`) in `src/Storage/`. Add a service + config branch in `DeployTasksBundle::configure()` and `loadExtension()`. |
| Custom ID scheme | Implement `TaskIdGeneratorInterface`. Set `deploy_tasks.id_generator` to its service ID. |
| Dynamic per-task ID | Task implements `TaskIdProviderInterface::getTaskId()`. |
| Custom ordering | Implement `TaskOrderResolverInterface`. Set `deploy_tasks.order_resolver`. |
| React to lifecycle | Subscribe to `BeforeTaskEvent` / `AfterTaskEvent` / `TaskFailedEvent` (all carry `$taskId`). |
| New command | Add to `src/Command/`, register in `DeployTasksBundle::loadExtension()`. |

## Key Design Decisions

- **Filesystem default** — zero dependencies, no migration needed
- **Auto-create DB table** — `auto_create_table: true` by default; `deploytasks:create-schema` available for explicit control
- **Optional event dispatcher and lock factory** — graceful degradation when `symfony/event-dispatcher` or `symfony/lock` is absent
- **Single attribute reader** — `AsDeployTask::of()` is the sole entry point for attribute parsing
- **All classes `final`** — composition over inheritance

## Git

English commits. Format: `<type>: description` — types: `feat|fix|chore|refactor|docs|test|ci`. **No `(scope)`** — the whole repo is one bundle, scope adds noise. 3rd-person present-tense imperative (`adds`, `fixes`, `removes`). Title expresses visible impact, not implementation detail. No `Co-authored-by` trailer.

## Release

Tagged releases follow semver + Keep a Changelog. See `CONTRIBUTING.md` § Releasing for the full process, or invoke the `release` skill which walks through pre-flight checks, changelog finalization, tagging, and publishing in order.
