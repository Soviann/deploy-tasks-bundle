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

**`Command/`** — 9 console commands (`Deploy*Command.php`) plus `CommandMessages.php` (shared user-facing strings).

**`DependencyInjection/Compiler/`**
- `RegisterTasksCompilerPass` — collects tagged tasks, performs compile-time duplicate-ID detection (skipped when the generator's static method returns null), and wires optional `event_dispatcher` and `lock.factory` into the runner.

**`Event/`**
- `BeforeTaskEvent`, `AfterTaskEvent`, `TaskFailedEvent` — all carry `string $taskId`.

**`Exception/`** — 6 `*Exception.php` classes.

### Domain-based folders

**`Identifier/`** — task ID + description handling
- `TaskIdGeneratorInterface` — service: `generate(class-string): string` plus static `generateStatic()` for compile time
- `TaskIdProviderInterface` — opt-in on tasks to supply a dynamic ID via `getTaskId(): string`
- `DefaultTaskIdGenerator` — `@internal`. FQCN → snake_case (strips `Task`/`DeployTask` prefix/suffix); prefixes a purely-numeric remainder with `task_` to match the recommended naming convention.
- `TaskIdResolver` — `@internal`. Resolution order: `TaskIdProviderInterface` > `AsDeployTask::$id` > generator
- `TaskDescriptionResolver` — `@internal`. Resolution order: non-empty `getDescription()` > `AsDeployTask::$description` > empty string. Used by `deploytasks:status` and `deploytasks:show`.

**`Helper/`** — stateless utility classes (instantiated, never injected)
- `PathNormalizer` — path canonicalisation helper
- `ProcessRunnerTrait` — public trait for tasks that shell out via `symfony/process` (soft dep). Wraps a caller-built `Process` with stdout/stderr streaming + timeout enforcement + `TaskResult` mapping.

**`Sorting/`** — execution order
- `TaskSorterInterface` — sorts tasks into execution order via `sort(array): list<DeployTaskInterface>`
- `DefaultTaskSorter` — sort: priority DESC → date-from-id ASC → stable original order

**`Runner/`** — discovery and execution
- `TaskRegistry` — holds tagged tasks, env filtering, duplicate detection
- `TaskRunner` — orchestrates execution: ordering, storage tracking, optional events/locking/transactions
- `RunResult` — readonly: `$ran`, `$skipped`, `$failed`, `$locked`. `isSuccessful()`.
- `TaskOutcome` — per-task outcome value object

**`Storage/`** — persistence
- `TaskStorageInterface` — `has()`, `get()`, `save()`, `remove()`, `removeAll()`, `findByTaskId()`, `all()`, `reset()`. All lookups scoped by `(taskId, ?group)`; `findByTaskId()` returns every slot for one id as `list<TaskExecution>`.
- `TransactionalStorageInterface` — extends storage, adds `transactional(\Closure): mixed`
- `SchemaManageable` — capability interface (`getCreateTableSql()`, `createSchema()`) opt-in for backends needing DDL provisioning. `deploytasks:create-schema` depends on it.
- `TaskExecution` — readonly value object: id, status, executedAt, error, group
- `TaskStatus` — enum: `Ran`, `Failed`, `Skipped`
- `Dbal\DbalStorage` — implements `TransactionalStorageInterface` + `SchemaManageable`. Composite PK `(id, task_group)`. SQLite/MySQL/PostgreSQL.
- `Dbal\DbalStorageConfiguration` — table/column names DTO (id, status, executed_at, error, task_group columns + lengths)
- `Filesystem\FilesystemStorage` — JSON file per `(task, group)` slot. Atomic writes via `Filesystem::dumpFile()` + `LOCK_EX`. Directory mode `0700`, files `0600`. Default slot → `<id>.json`; grouped slot → `<id>@<group>.json` (verbatim, no transformation — group names constrained to `AsDeployTask::GROUP_NAME_PATTERN`). Throws `StorageException` if path contains a `public`/`public_html`/`web`/`htdocs` segment.
- `InMemory\InMemoryStorage` — array-backed storage for tests

## Configuration

Root key `deploy_tasks:`.

```yaml
deploy_tasks:
    id_generator: ~                         # service ID or null (default: DefaultTaskIdGenerator)
    sorter: ~                               # service ID or null (default: DefaultTaskSorter)
    logger: ~                               # PSR-3 service ID; null = autodetect app logger, NullLogger fallback
    default_timeout: 300                    # seconds (>= 0); 0 disables the check
    storage:
        type: filesystem                    # filesystem | database | custom
        filesystem:
            path: '%kernel.project_dir%/var/deploy-tasks'
            transactional: false            # must stay false — true is rejected at container build
            all_or_nothing: false           # must stay false — true is rejected at container build
        database:
            connection: default             # DBAL connection name
            table: deploy_task_executions
            auto_create_table: true         # auto-init schema on first use
            id_column: id
            id_column_length: 255           # >= 1
            status_column: status
            executed_at_column: executed_at
            error_column: error
            group_column: task_group        # column for the group slot key
            group_column_length: 128        # >= 1
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
        directory: src/DeployTasks/Task/    # default output directory for `deploytasks:generate:container`
        host_directory: '%kernel.project_dir%/deploy/host-tasks'  # default output directory for `deploytasks:generate:host`
        template: ~                         # path to a custom PHP template
```

**Scalar shorthand:** `storage: database` expands to `storage: { type: database }`; `events: false` and `lock: false` expand to `{ enabled: false }`. The long form keeps working unchanged.

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
- `TaskSorterInterface` → configured or default sorter
- `TaskRegistry`, `TaskRunner` → autowirable (private aliases, constructor injection only)

## Console Commands

| Command | Purpose |
|---|---|
| `deploytasks:run [--dry-run] [--rerun-all] [--id=<taskId>] [--group=<name>]* [--require-some]` | Execute pending tasks. `--rerun-all` re-runs all already-executed tasks; `--id` targets one; `--group` is repeatable; `--require-some` exits 64 (`EX_USAGE`) when no task matches the filters. Lock contention exits 75 (`EX_TEMPFAIL`). |
| `deploytasks:status [--no-state] [--group=<name>]* [--filter-status=<list>]` | Table of registered tasks with their execution state. `--filter-status` accepts a case-insensitive comma list of `RAN`, `FAILED`, `SKIPPED`, `PENDING`; combining with `--no-state` exits `Command::INVALID`. |
| `deploytasks:show <id>` | Show full metadata + every stored execution slot for one task (id, FQCN, description, declared groups, untruncated error text). Exits 1 when the ID is not registered. |
| `deploytasks:skip <id> [--group=<name>]` | Record a task as `Skipped` without running it. Interactive confirm unless `--no-interaction`. |
| `deploytasks:reset <id> [--group=<name>]` | Remove a task's execution record. Interactive unless `--no-interaction` (prompt defaults to "no"). |
| `deploytasks:rollup [--group=<name>]*` | Clear execution history and mark all registered tasks as `Ran`. |
| `deploytasks:generate:container [--dir=...]` | Create a `DeployTask<YYYYMMDDHHIISS>.php` task stub (PHP class, runs inside the Symfony container). Files written `0640`. |
| `deploytasks:generate:host [--dir=...]` | Create a `deploy_task_<YYYYMMDD>_<HHIISS>.sh` task stub (bash script, runs on the host outside the container). Files written `0750`. Warns if `bin/deploy-tasks-host.sh` is missing. |
| `deploytasks:create-schema [--dump-sql]` | Emit/execute the SQL to create the storage table. Registered only when the active storage backend implements `SchemaManageable`. |

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
| Custom ordering | Implement `TaskSorterInterface`. Set `deploy_tasks.sorter`. |
| React to lifecycle | Subscribe to `BeforeTaskEvent` / `AfterTaskEvent` / `TaskFailedEvent` (all carry `$taskId`). |
| Custom logger | Set `deploy_tasks.logger` to a PSR-3 service ID. Default auto-detects the app logger with a `deploy_tasks` Monolog channel; `NullLogger` if no logger is available. |
| New command | Add to `src/Command/`, register in `DeployTasksBundle::loadExtension()`. |

## Key Design Decisions

- **Filesystem default** — zero dependencies, no migration needed
- **Auto-create DB table** — `auto_create_table: true` by default; `deploytasks:create-schema` available for explicit control
- **Optional event dispatcher and lock factory** — graceful degradation when `symfony/event-dispatcher` or `symfony/lock` is absent
- **PSR-3 logging, channel-aware** — requires `psr/log` (universal); the runner uses `@logger` when available with a `deploy_tasks` Monolog channel tag, otherwise falls back to `NullLogger`. Mirrors the event-dispatcher / lock graceful-degradation pattern.
- **Single attribute reader** — `AsDeployTask::of()` is the sole entry point for attribute parsing
- **All classes `final`** — composition over inheritance

## Git

English commits. Format: `<type>: description` — types: `feat|fix|chore|refactor|docs|test|ci`. **No `(scope)`** — the whole repo is one bundle, scope adds noise. 3rd-person present-tense imperative (`adds`, `fixes`, `removes`). Title expresses visible impact, not implementation detail. No `Co-authored-by` trailer.

## Release

Tagged releases follow semver + Keep a Changelog. See `CONTRIBUTING.md` § Releasing for the full process, or invoke the `release` skill which walks through pre-flight checks, changelog finalization, tagging, and publishing in order.
