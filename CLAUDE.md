# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

DeployTasksBundle is a Symfony bundle for running one-time deploy tasks (data migrations, cache warmups, seed scripts) via CLI. Tasks are tracked so they execute only once per environment. Filesystem storage by default; Doctrine DBAL or fully custom backends supported. Requires PHP 8.2+ and Symfony 6.4+/7.0+.

## Architecture

Three-layer design with strict inward dependency flow: Contract ← Component ← Bundle (never reverse).

### Contract Layer (`src/Contract/`)
Pure PHP interfaces, attributes, enums, and value objects. No Symfony imports except `OutputInterface`.

- `DeployTaskInterface` — task contract: `getDescription(): string`, `run(OutputInterface): TaskResult`
- `TaskIdProviderInterface` — opt-in on tasks to supply a dynamic ID via `getTaskId(): string`
- `TaskIdGeneratorInterface` — service: `generate(class-string): string` plus static `generateStatic()` for compile time
- `TaskOrderResolverInterface` — controls task execution order via `resolve(array): OrderedTaskCollection`
- `TaskStorageInterface` — `has()`, `get()`, `save()`, `remove()`, `removeAll()`, `all()`, `reset()`. All lookups scoped by `(taskId, ?group)`.
- `TransactionalStorageInterface` — extends storage, adds `transactional(\Closure): mixed`
- `OrderedTaskCollection` — immutable, variadic-typed collection of `DeployTaskInterface`
- `TaskExecution` — readonly value object: id, status, executedAt, error, group
- `TaskStatus` — enum: `Ran`, `Failed`, `Skipped`
- `TaskResult` — enum returned by `run()`: `SUCCESS`, `FAILURE`, `SKIPPED`, `LOCKED`
- `Attribute\AsDeployTask` — task metadata (id, priority, env, timeout, transactional, description, groups). Static `AsDeployTask::of()` is the **single attribute reader**; `AsDeployTask::groupsOf()` returns the declared groups as `list<string>|null`.

### Component Layer (`src/`)
Storage backends, registry, runner, resolvers, events. Framework-agnostic.

- `TaskRegistry` — holds tagged tasks, env filtering, duplicate detection
- `TaskRunner` — orchestrates execution: ordering, storage tracking, optional events/locking/transactions
- `TaskIdResolver` — `@internal`. Resolution order: `TaskIdProviderInterface` > `AsDeployTask::$id` > generator
- `DefaultTaskIdGenerator` — `@internal`. FQCN → snake_case (strips `Task`/`DeployTask` suffix)
- `DefaultTaskOrderResolver` — sort: priority DESC → date-from-id ASC → stable original order
- `RunResult` — readonly: `$ran`, `$skipped`, `$failed`, `$locked`. `isSuccessful()`.
- `Storage\FilesystemStorage` — JSON file per `(task, group)` slot with `LOCK_EX`. Default slot → `<id>.json`; grouped slot → `<id>@<slug>.json`. Warns if path traverses `/public/`.
- `Storage\DbalStorage` — implements `TransactionalStorageInterface`. Instance `getCreateTableSql()`, `createSchema()`. Composite PK `(id, task_group)`. SQLite/MySQL/PostgreSQL.
- `Storage\DbalStorageConfiguration` — table/column names DTO
- `Storage\InMemoryStorage` — array-backed storage for tests
- `Event\BeforeTaskEvent`, `AfterTaskEvent`, `TaskFailedEvent` — all carry `string $taskId`

### Bundle Layer (`src/Bundle/`)
Symfony DI integration: configuration tree, compiler pass, console commands.

- `DeployTasksBundle` — `AbstractBundle`. `configure()` builds the config tree; `loadExtension()` registers services; `build()` autoconfigures `DeployTaskInterface` with tag `deploy_tasks.task`.
- `DependencyInjection\RegisterTasksCompilerPass` — collects tagged tasks, performs compile-time duplicate-ID detection (skipped when the generator's static method returns null), and wires optional `event_dispatcher` and `lock.factory` into the runner.

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
`#[AsDeployTask(id, priority, env, timeout, transactional, description)]` provides task metadata. `AsDeployTask::of($task)` is the only attribute reader in the codebase.

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
| `deploytasks:generate [name] [--dir=...]` | Create a `Task{YYYYMMDDHHIISS}{Name}.php` stub. |
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
- Backslash-prefix native functions: `\array_map()`, `\sprintf()`, `\count()`
- Yoda conditions: `null === $var`
- All classes `final` unless designed for extension; properties `readonly` where possible
- Method order: `__construct` → public → protected → private (`setUp`/`tearDown` first in tests)

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
| New command | Add to `src/Bundle/Command/`, register in `DeployTasksBundle::loadExtension()`. |

## Key Design Decisions

- **Filesystem default** — zero dependencies, no migration needed
- **Auto-create DB table** — `auto_create_table: true` by default; `deploytasks:create-schema` available for explicit control
- **Optional event dispatcher and lock factory** — graceful degradation when `symfony/event-dispatcher` or `symfony/lock` is absent
- **Single attribute reader** — `AsDeployTask::of()` is the sole entry point for attribute parsing
- **Contract purity** — `src/Contract/` must not import Symfony classes (except `OutputInterface`)
- **All classes `final`** — composition over inheritance

## Git

English commits. Format: `<type>: description` — types: `feat|fix|chore|refactor|docs|test|ci`. **No `(scope)`** — the whole repo is one bundle, scope adds noise. 3rd-person present-tense imperative (`adds`, `fixes`, `removes`). Title expresses visible impact, not implementation detail. No `Co-authored-by` trailer.
