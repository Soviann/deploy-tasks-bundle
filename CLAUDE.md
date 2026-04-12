# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

DeployTasksBundle is a Symfony bundle for running one-time deploy tasks (data migrations, cache warmups, seed scripts) via CLI. Tasks are tracked so they execute only once. Works out of the box with filesystem storage, optionally with a database via Doctrine DBAL.

## Architecture

Three-layer design with strict dependency flow: Contract ← Component ← Bundle (never reverse).

### Contract Layer (`src/Contract/`)
Pure PHP interfaces, attributes, and value objects. No Symfony imports except `OutputInterface`.

- `DeployTaskInterface` — core task contract: `getId()`, `getDescription()`, `run(OutputInterface)`
- `TaskStorageInterface` — persistence abstraction: `has()`, `get()`, `save()`, `remove()`, `all()`
- `TaskOrderResolverInterface` — controls task execution order
- `TaskExecution` — readonly value object recording a task's execution state
- `TaskStatus` — enum: `Ran`, `Failed`, `Skipped`
- `TaskResult` — constants: `SUCCESS`, `FAILURE`, `SKIPPED`
- `Attribute\AsDeployTask` — PHP attribute for task metadata (id, priority, env, timeout, transactional, description)

### Component Layer (`src/`)
Storage backends, registry, runner, resolver, and events.

- `Storage\FilesystemStorage` — JSON files, one per task, `LOCK_EX` for write safety
- `Storage\DbalStorage` — Doctrine DBAL backend, platform-aware upsert, static `getCreateTableSql()`
- `Storage\InMemoryStorage` — array-backed storage for testing
- `TaskRegistry` — in-memory map of registered tasks, detects duplicate IDs
- `TaskRunner` — orchestrates execution: ordering, environment filtering, storage tracking, optional events/locking/transactions
- `DefaultTaskOrderResolver` — sorts by `#[AsDeployTask]` priority DESC, then date from ID ASC
- `RunResult` — readonly DTO: `$ran`, `$skipped`, `$failed`, `$errors`, `isSuccessful()`

### Event System (`src/Event/`)
Optional events dispatched by `TaskRunner` when `EventDispatcherInterface` is available:
- `BeforeTaskEvent` — fired before each task runs
- `AfterTaskEvent` — fired after successful execution, includes duration
- `TaskFailedEvent` — fired on exception, includes throwable and duration

### Bundle Layer (`src/Bundle/`)
Symfony DI integration, configuration tree, compiler pass, and console commands.

- `DeployTasksBundle` — extends `AbstractBundle`, defines config tree, registers services
- `DependencyInjection\RegisterTasksCompilerPass` — validates tagged services, detects duplicate IDs at compile time, handles graceful degradation for optional event dispatcher and lock factory

## Configuration Architecture

```yaml
deploy_tasks:
    order_resolver: ~               # custom service ID or null (uses default)
    default_timeout: 300            # seconds
    storage:
        type: filesystem            # filesystem | database
        filesystem:
            path: '%kernel.project_dir%/var/deploy-tasks'
        database:
            connection: default     # DBAL connection name
            table: deploy_task_executions
            transaction_wrap: false
    events:
        enabled: true
    lock:
        enabled: true
```

Validation: `type: database` requires `doctrine/dbal` class at compile time. `default_timeout >= 0`.

## Service Registration Patterns

### Attribute-Based Registration
Tasks using `#[AsDeployTask]` are autoconfigured with the `deploy_tasks.task` tag:
```php
#[AsDeployTask(id: 'app.2026_04_12.seed_categories', priority: 10)]
final class SeedCategoriesTask implements DeployTaskInterface { ... }
```

### Interface-Based Autoconfiguration
Any class implementing `DeployTaskInterface` is automatically tagged with `deploy_tasks.task`.

### Service Aliases
- `TaskStorageInterface` → active storage backend (filesystem or database)
- `TaskOrderResolverInterface` → configured resolver or `DefaultTaskOrderResolver`

## Console Commands

| Command | Purpose |
|---|---|
| `deploy:run` | Execute pending tasks. `--dry-run` to preview, `--rerun <id>` to force re-execute |
| `deploy:status` | List all tasks with state. `--no-state` omits execution info |
| `deploy:skip <id>` | Mark task as skipped without executing |
| `deploy:reset <id>` | Clear execution record. `--force` bypasses prod confirmation |

## Development Commands

```bash
vendor/bin/phpunit                          # all tests
vendor/bin/phpunit --testsuite Unit         # unit tests only
vendor/bin/phpunit --testsuite Functional   # functional tests only
vendor/bin/phpstan analyse                  # static analysis (level 9)
vendor/bin/php-cs-fixer fix                 # fix code style
vendor/bin/php-cs-fixer fix --dry-run       # check code style
```

## Testing Patterns

- **Unit tests** (`tests/Unit/`): test each component in isolation. `InMemoryStorage` for runner tests, mock tasks via anonymous classes.
- **Functional tests** (`tests/Functional/`): boot a minimal `TestKernel` with the bundle, test command output and service wiring.
- **Test fixtures** (`tests/Functional/Fixtures/`): sample task classes for functional tests.

## Key Design Decisions

- **Filesystem as default storage** — zero dependencies, no migration needed
- **No auto-create DB table** — users must handle schema themselves
- **No autowiring inside the bundle** — explicit service definitions, but services are autowirable in host projects
- **Optional event dispatcher and lock factory** — graceful degradation when not available
- **All classes `final`** — composition over inheritance
- **Contract purity** — `src/Contract/` must not import Symfony classes (except `OutputInterface`)
