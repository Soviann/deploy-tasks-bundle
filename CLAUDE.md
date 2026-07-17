# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

DeployTasksBundle is a Symfony bundle for running one-time deploy tasks (data migrations, cache warmups, seed scripts) via CLI. Tasks are tracked so they execute only once per environment. Filesystem storage by default; Doctrine DBAL or fully custom backends supported. Requires PHP 8.2+ and Symfony 6.4+/7.0+.

## Architecture

Single namespace `Soviann\DeployTasksBundle\` mapped to `src/`. Flat layout with role-based and domain-based folders.

### Root (`src/`)
Primary public surface — matches DoctrineFixturesBundle pattern.

- `DeployTaskInterface` — task contract: `getDescription(): string`, `run(OutputInterface): TaskResult`
- `TaskResult` — enum returned by `run()`: `SUCCESS`, `FAILURE`, `SKIPPED`
- `SoviannDeployTasksBundle` — `AbstractBundle`. `configure()` builds the config tree; `loadExtension()` registers services; `build()` autoconfigures `DeployTaskInterface` with tag `soviann_deploy_tasks.task`.

### Role-based folders

**`Attribute/`**
- `AsDeployTask` — task metadata (id, priority, env, timeout, slowTaskThreshold, transactional, description, groups). Static `AsDeployTask::of()` is the **single attribute reader**; `AsDeployTask::groupsOf()` returns the declared groups as `list<string>|null`.

**`Command/`** — 14 console commands (`Deploy*Command.php`) plus `CommandMessages.php` (shared user-facing strings).

**`DependencyInjection/Compiler/`**
- `RegisterTasksCompilerPass` — collects tagged tasks, performs compile-time duplicate-ID detection on attribute `id`s and class-name-derived IDs (`TaskIdProviderInterface` tasks are skipped — checked at boot instead), and wires optional `event_dispatcher` and `lock.factory` into the runner.

**`Event/`**
- `BeforeTaskEvent`, `AfterTaskEvent`, `TaskFailedEvent` — all carry `string $taskId`.

**`Exception/`** — 6 `*Exception.php` classes.

### Domain-based folders

**`Identifier/`** — task ID + description handling
- `TaskIdGeneratorInterface` — `generate(class-string): string`; type-hint seam only (not configurable), sole implementation is `DefaultTaskIdGenerator`
- `TaskIdProviderInterface` — opt-in on tasks to supply a dynamic ID via `getTaskId(): string`
- `DefaultTaskIdGenerator` — `@internal`. FQCN → snake_case (strips `Task`/`DeployTask` prefix/suffix); prefixes a purely-numeric remainder with `task_` to match the recommended naming convention. Its static `generateStatic()` is called directly by `RegisterTasksCompilerPass` for compile-time duplicate-ID detection.
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
- `RunResult` — readonly: `$ran`, `$skipped` (already recorded), `$deferred` (returned `SKIPPED`, retries next run), `$failed`, `$locked`. `isSuccessful()`.
- `TaskOutcome` — per-task outcome value object

**`Storage/`** — persistence
- `TaskStorageInterface` — `has()`, `get()`, `save()`, `remove()`, `removeAll()`, `findByTaskId()`, `all()`, `reset()`. All lookups scoped by `(taskId, ?group)`; `findByTaskId()` returns every slot for one id as `list<TaskExecution>`.
- `TransactionalStorageInterface` — extends storage, adds `transactional(\Closure): mixed`
- `SchemaManageableInterface` — capability interface (`getCreateTableSql()`, `createSchema()`) opt-in for backends needing DDL provisioning. `deploytasks:create-schema` is registered for any storage implementing it.
- `TaskExecution` — readonly value object: id, status, executedAt, error, group
- `TaskStatus` — enum: `Ran`, `Failed`, `Skipped`
- `Dbal\DbalStorage` — implements `TransactionalStorageInterface` + `SchemaManageableInterface`. Composite PK `(id, task_group)`. SQLite/MySQL/PostgreSQL.
- `Dbal\DbalStorageConfiguration` — table/column names DTO (id, status, executed_at, error, task_group columns + lengths)
- `Filesystem\FilesystemStorage` — JSON file per `(task, group)` slot. Atomic writes via `Filesystem::dumpFile()` + `LOCK_EX`. Directory mode `0700`, files `0600`. Default slot → `<id>.json`; grouped slot → `<id>@<group>.json` (verbatim, no transformation — group names constrained to `AsDeployTask::GROUP_NAME_PATTERN`). Throws `StorageException` when the path (symlinks resolved) contains a public web-root segment (`pub`/`public`/`public_html`/`web`/`html`/`htdocs`/`wwwroot`/`httpdocs`) at or below the project dir — see `docs/security.md`.
- `InMemory\InMemoryStorage` — array-backed storage for tests

## Configuration

Root key `soviann_deploy_tasks:`.

```yaml
soviann_deploy_tasks:
    sorter: ~                               # service ID or null (default: DefaultTaskSorter)
    logger: ~                               # PSR-3 service ID; null = autodetect app logger, NullLogger fallback
    slow_task_threshold: 300                # seconds (>= 0); warn past it, kill nothing; 0 disables the check
    storage:
        type: filesystem                    # filesystem | database | custom
        filesystem:
            path: '%kernel.project_dir%/var/deploy-tasks'
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
            transaction_mode: all_or_nothing # none | per_task | all_or_nothing (default: all_or_nothing)
        custom:
            service: ~                      # service ID, required when type=custom
            transaction_mode: none          # none | per_task | all_or_nothing (default: none); per_task/all_or_nothing require TransactionalStorageInterface
    events:
        enabled: true
    lock:
        enabled: true
    generate:
        directory: src/DeployTasks/Task/    # default output directory for `deploytasks:generate:container`
        template: ~                         # path to a custom PHP template
        root_namespace: App                 # root namespace for `src/`-rooted `--dir` (mirrors symfony/maker-bundle)
    host:
        directory: '%kernel.project_dir%/deploy/host-tasks'        # host-scope `*.sh` task directory — must match `DEPLOY_TASKS_HOST_DIR`
        log_path: '%kernel.project_dir%/.deploy-tasks-host.log'    # host runner completion log — must match `DEPLOY_TASKS_HOST_STORAGE`
        lock_path: '%kernel.project_dir%/.deploy-tasks-host.lock'  # host runner flock file — must match `DEPLOY_TASKS_HOST_LOCK`
```

**Scalar shorthand:** `storage: database` expands to `storage: { type: database }`; `events: false` and `lock: false` expand to `{ enabled: false }`. The long form keeps working unchanged.

Validation: `type: database` requires `doctrine/dbal` at compile time. `type: custom` requires `storage.custom.service`. Per-task `#[AsDeployTask(transactional: false)]` opts a task out of wrapping, but only under `transaction_mode: per_task` — see `docs/storage.md` § Transaction mode.

## Service Registration

### Autoconfiguration
Any class implementing `DeployTaskInterface` is automatically tagged `soviann_deploy_tasks.task`.

### Attribute
`#[AsDeployTask(id, priority, env, timeout, slowTaskThreshold, transactional, description, groups)]` provides task metadata — `timeout` = hard Process kill (ProcessRunnerTrait only), `slowTaskThreshold` = per-task override of the runner's slow-task warning. `AsDeployTask::of($task)` is the only attribute reader in the codebase. The `description` attribute is the fallback when `getDescription()` returns an empty string — same pattern as `id` resolution.

### Service Aliases (autowirable)
- `TaskStorageInterface` → active storage backend
- `TransactionalStorageInterface` → active storage when it supports transactions
- `TaskIdGeneratorInterface` → the built-in generator
- `TaskSorterInterface` → configured or default sorter
- `TaskRegistry`, `TaskRunner` → autowirable (private aliases, constructor injection only)

## Console Commands

| Command | Purpose |
|---|---|
| `deploytasks:run [--dry-run] [--rerun-all] [--id=<taskId>] [--group=<name>]* [--require-some]` | Execute pending tasks. `--rerun-all` re-runs all already-executed tasks; `--id` targets one; `--group` is repeatable; `--require-some` exits 64 (`EX_USAGE`) when no task matches the filters. Lock contention exits 75 (`EX_TEMPFAIL`). |
| `deploytasks:status [--no-state] [--group=<name>]* [--filter-status=<list>]` | Table of registered tasks with their execution state. `--filter-status` accepts a case-insensitive comma list of `RAN`, `FAILED`, `SKIPPED`, `PENDING`; combining with `--no-state` exits `Command::INVALID`. |
| `deploytasks:show <id>` | Show full metadata + every stored execution slot for one task (id, FQCN, description, declared groups, untruncated error text). Exits `Command::INVALID` (`2`) when the ID is not registered. |
| `deploytasks:skip <id> [--group=<name>]` | Record a task as `Skipped` without running it. Interactive confirm unless `--no-interaction`. |
| `deploytasks:reset <id> [--group=<name>] [--force]` | Remove a task's execution record. Interactive unless `--no-interaction` (requires `--force`, otherwise the command refuses to run). |
| `deploytasks:rollup [--group=<name>]* [--force]` | Clear execution history and mark all registered tasks as `Ran`. Interactive unless `--no-interaction` (requires `--force`). |
| `deploytasks:generate:container [--dir=...] [--namespace=...]` | Create a `DeployTask<YYYYMMDDHHIISS>.php` task stub (PHP class, runs inside the Symfony container). Files written `0640`. |
| `deploytasks:host:install [--force]` | Install the host runner (`bin/deploy-tasks-host.sh`, copied from the bundle's `.dist`, `0755`), `deploy/host-tasks/.gitkeep`, and the Flex-style `.gitignore` block. Idempotent — existing artifacts are skipped; `--force` overwrites and rewrites the block in place. Filesystem errors exit `1`. |
| `deploytasks:host:generate [--dir=...]` | Create a `deploy_task_<YYYYMMDD>_<HHIISS>.sh` task stub (bash script, runs on the host outside the container). Files written `0750`. Warns if `bin/deploy-tasks-host.sh` is missing. |
| `deploytasks:host:skip <id>` | Host-scope equivalent of `deploytasks:skip`: mark a host task done in the completion log without running its script. Interactive confirm unless `--no-interaction`. |
| `deploytasks:host:reset <id> [--force]` | Host-scope equivalent of `deploytasks:reset`: remove a host task's completion-log entry. Interactive unless `--no-interaction` (requires `--force`). |
| `deploytasks:host:rollup [--force]` | Host-scope equivalent of `deploytasks:rollup`: append every pending host task to the completion log as done. Interactive unless `--no-interaction` (requires `--force`). |
| `deploytasks:create-schema [--dump-sql]` | Emit/execute the DDL provisioning the configured storage. Registered whenever the storage implements `SchemaManageableInterface` (built-in: database storage). |
| `deploytasks:host:config [--write]` | Render (or write with `--write`) the `DEPLOY_TASKS_HOST_*` env exports derived from `soviann_deploy_tasks.host.*`, keeping `bin/deploy-tasks-host.sh` and the PHP-side host commands in sync. |

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
| New storage backend | Implement `TaskStorageInterface` (or `TransactionalStorageInterface`) in `src/Storage/`. Add a service + config branch in `SoviannDeployTasksBundle::configure()` and `loadExtension()`. |
| Explicit task ID | Set `#[AsDeployTask(id: '...')]` on the task — auto-derivation from the class name is not customizable. |
| Dynamic per-task ID | Task implements `TaskIdProviderInterface::getTaskId()`. |
| Custom ordering | Implement `TaskSorterInterface`. Set `soviann_deploy_tasks.sorter`. |
| React to lifecycle | Subscribe to `BeforeTaskEvent` / `AfterTaskEvent` / `TaskFailedEvent` (all carry `$taskId`). |
| Custom logger | Set `soviann_deploy_tasks.logger` to a PSR-3 service ID. Default auto-detects the app logger with a `soviann_deploy_tasks` Monolog channel; `NullLogger` if no logger is available. |
| New command | Add to `src/Command/`, register in `SoviannDeployTasksBundle::loadExtension()`. |

## Key Design Decisions

- **Filesystem default** — zero dependencies, no migration needed
- **Auto-create DB table** — `auto_create_table: true` by default; `deploytasks:create-schema` available for explicit control
- **Optional event dispatcher and lock factory** — graceful degradation when `symfony/event-dispatcher` or `symfony/lock` is absent
- **PSR-3 logging, channel-aware** — requires `psr/log` (universal); the runner uses `@logger` when available with a `soviann_deploy_tasks` Monolog channel tag, otherwise falls back to `NullLogger`. Mirrors the event-dispatcher / lock graceful-degradation pattern.
- **Single attribute reader** — `AsDeployTask::of()` is the sole entry point for attribute parsing
- **All classes `final`** — composition over inheritance

## Git

English commits. Format: `<type>: description` — types: `feat|fix|chore|refactor|docs|test|ci`. **No `(scope)`** — the whole repo is one bundle, scope adds noise. 3rd-person present-tense imperative (`adds`, `fixes`, `removes`). Title expresses visible impact, not implementation detail. No `Co-authored-by` trailer.

## Release

Tagged releases follow semver + Keep a Changelog. See `CONTRIBUTING.md` § Releasing for the full process.
