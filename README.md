# DeployTasksBundle

[![CI](https://github.com/Soviann/deploy-tasks-bundle/actions/workflows/ci.yml/badge.svg)](https://github.com/Soviann/deploy-tasks-bundle/actions/workflows/ci.yml)
[![Latest Stable Version](https://poser.pugx.org/soviann/deploy-tasks-bundle/v/stable)](https://packagist.org/packages/soviann/deploy-tasks-bundle)
[![License](https://poser.pugx.org/soviann/deploy-tasks-bundle/license)](https://packagist.org/packages/soviann/deploy-tasks-bundle)

A Symfony bundle for running one-time deploy tasks — data migrations, cache warmups, seed scripts — via CLI. Each task is tracked so it executes exactly once across deployments.

> **Status: pre-1.0.** Public API and configuration may change without a major-version bump until `v1.0.0`. Each release ships an `UPGRADE.md` section.

## Requirements

- PHP >= 8.2
- Symfony 6.4 LTS or 7.x

## Installation

```bash
composer require soviann/deploy-tasks-bundle
```

With Symfony Flex, the bundle is registered automatically. Without Flex, register it manually in `config/bundles.php`:

```php
return [
    // ...
    Soviann\DeployTasksBundle\SoviannDeployTasksBundle::class => ['all' => true],
];
```

## Quick Start

### Creating a task

```php
use Soviann\DeployTasksBundle\Attribute\AsDeployTask;
use Soviann\DeployTasksBundle\DeployTaskInterface;
use Soviann\DeployTasksBundle\TaskResult;
use Symfony\Component\Console\Output\OutputInterface;

#[AsDeployTask(id: 'task_20260412143000_seed_categories', priority: 10)]
final class SeedCategoriesTask implements DeployTaskInterface
{
    public function getDescription(): string
    {
        return 'Seeds the categories table with initial data.';
    }

    public function run(OutputInterface $output): TaskResult
    {
        // Your task logic here
        $output->writeln('Categories seeded.');

        return TaskResult::SUCCESS;
    }
}
```

### Running tasks

Execute all pending tasks:

```bash
bin/console deploytasks:run
```

Check the status of all tasks:

```bash
bin/console deploytasks:status
```

## Project Documents

- [`CHANGELOG.md`](CHANGELOG.md) — release notes, Keep-a-Changelog format.
- [`UPGRADE.md`](UPGRADE.md) — breaking-change migration notes (per release).
- [`SECURITY.md`](SECURITY.md) — vulnerability disclosure.
- [`CONTRIBUTING.md`](CONTRIBUTING.md) — local dev setup and PR conventions.

## Running shell commands

Tasks that shell out to external binaries (asset builds, `rsync`, CLI migrations) can opt into the `ProcessRunnerTrait`. It wraps `symfony/process` to stream stdout/stderr, enforce a per-call timeout, and map the outcome to a `TaskResult`.

Install the soft dependency first:

```bash
composer require symfony/process
```

Then compose the trait into your task:

```php
use Soviann\DeployTasksBundle\Attribute\AsDeployTask;
use Soviann\DeployTasksBundle\DeployTaskInterface;
use Soviann\DeployTasksBundle\Helper\ProcessRunnerTrait;
use Soviann\DeployTasksBundle\TaskResult;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

#[AsDeployTask(id: 'build_assets', timeout: 120)]
final class BuildAssetsTask implements DeployTaskInterface
{
    use ProcessRunnerTrait;

    public function getDescription(): string
    {
        return 'Build frontend assets';
    }

    public function run(OutputInterface $output): TaskResult
    {
        return $this->runProcess(
            new Process(['npm', 'run', 'build'], cwd: __DIR__.'/../../assets', timeout: 120),
            $output,
        );
    }
}
```

Behavior notes:

- **You own the `Process` instance** — use array-form commands to avoid shell parsing, or `Process::fromShellCommandline()` if you deliberately need shell features.
- **Timeout lives on the `Process`.** It is not auto-read from `#[AsDeployTask(timeout: ...)]` — keep them aligned manually if you want them to match.
- **stdout streams as-is**; **stderr is wrapped in `<error>…</error>`** tags so the runner's styling applies.
- **Non-zero exit or timeout → `TaskResult::FAILURE`.** Any `ProcessExceptionInterface` (e.g. invalid cwd, unstartable process) is also mapped to `FAILURE` with an error message.

## Configuration

```yaml
# config/packages/soviann_deploy_tasks.yaml
soviann_deploy_tasks:
    id_generator: ~              # service ID of a custom TaskIdGeneratorInterface
    sorter: ~                    # service ID of a custom TaskSorterInterface
    logger: ~                    # service ID of a custom PSR-3 logger (null = auto-detect app logger, monolog channel "soviann_deploy_tasks")
    default_timeout: 300         # seconds
    storage:
        type: filesystem         # filesystem | database | custom
        filesystem:
            path: '%kernel.project_dir%/var/deploy-tasks'
            transactional: false     # must stay false — true is rejected at container build
            all_or_nothing: false    # must stay false — true is rejected at container build
        database:
            connection: default
            table: deploy_task_executions
            auto_create_table: true
            id_column: id
            id_column_length: 255
            status_column: status
            executed_at_column: executed_at
            error_column: error
            group_column: task_group
            group_column_length: 128
            transactional: true      # wrap each task in a DB transaction (default for database)
            all_or_nothing: true     # wrap the entire run in a single transaction
        custom:
            service: ~               # service ID of a TaskStorageInterface implementation
            transactional: false
            all_or_nothing: false
    events:
        enabled: true
    lock:
        enabled: true
        ttl: 3600                # lock lifetime in seconds; the runner refreshes it between tasks
    generate:
        directory: src/DeployTasks/Task/
        template: ~              # path to a custom PHP template
        root_namespace: App      # root namespace for src/-rooted --dir (mirrors symfony/maker-bundle)
        host_directory: '%kernel.project_dir%/deploy/host-tasks'   # where deploytasks:generate:host writes stubs
```

## Storage Backends

**Filesystem** (default): stores execution records as files in `var/deploy-tasks/`. No additional dependencies required.

**Database**: stores execution records in a database table. Requires `doctrine/dbal`.

**Custom**: plug in any `TaskStorageInterface` implementation via `storage.type: custom`. See [`docs/storage.md`](docs/storage.md).

## Host-scope tasks

Host tasks run outside the Symfony container — useful for operations that must execute on the host (Docker restarts, SSH-driven commands, infrastructure prep). They live as shell files under `deploy/host-tasks/`.

### Install the runner

Until a Flex recipe ships, install the runner manually:

    cp vendor/soviann/deploy-tasks-bundle/bin/deploy-tasks-host.sh.dist bin/deploy-tasks-host.sh
    chmod +x bin/deploy-tasks-host.sh
    mkdir -p deploy/host-tasks

Add to `.gitignore`:

    .deploy-tasks-host.log
    .deploy-tasks-host.lock
    deploy-tasks-host.local.sh

### Create a host task

    bin/console deploytasks:generate:host

Creates `deploy/host-tasks/deploy_task_20260418_143022.sh`. Edit the file to implement the task.

### Run pending host tasks

    bash bin/deploy-tasks-host.sh           # defaults to APP_ENV=dev
    bash bin/deploy-tasks-host.sh prod      # loads .env.prod + .env.prod.local
    bash bin/deploy-tasks-host.sh prod --dry-run

### Storage & idempotency

Host tasks use a separate append-only log (`.deploy-tasks-host.log`, one-shot per machine). `APP_ENV` determines which `.env.*` files are loaded for task execution; it does not scope storage.

### `.env` loading

The runner loads env files in Symfony cascade order (lowest to highest priority):
1. `.env`
2. `.env.local`
3. `.env.$APP_ENV`
4. `.env.$APP_ENV.local`
5. `deploy-tasks-host.local.sh` (bash source, for overrides the `.env` parser can't express)

As with Symfony's Dotenv, real environment variables always take precedence: a variable already set in the process environment before the runner starts (e.g. CI-injected `DATABASE_URL`) is never overwritten by any `.env` file. The resolved `APP_ENV` (CLI argument, else pre-set `APP_ENV`, else `dev`) is likewise authoritative — an `APP_ENV=` line in a `.env` file cannot change which environment the tasks run in.

Values are taken literally — no variable expansion, no inline comments, no multiline values; `deploy-tasks-host.local.sh` is the escape hatch for anything the parser can't express.

Values in host task scripts reference exported env vars (`$NAS_HOST`, etc.).

### Concurrency

A `flock` lock at `.deploy-tasks-host.lock` prevents concurrent runs on the same machine. When the lock is already held, the runner exits with code `75` (`EX_TEMPFAIL`) — the same "temporary failure, retry later" convention as `deploytasks:run`.

### Environment variables

The runner honours three environment variables for path overrides (useful for CI, shared-machine deployments, or keeping state outside the repo root). Each one has a sensible default; you rarely need to set them explicitly.

| Variable | Default | Purpose |
|---|---|---|
| `DEPLOY_TASKS_HOST_DIR` | `deploy/host-tasks` | Directory scanned for `*.sh` task scripts. |
| `DEPLOY_TASKS_HOST_STORAGE` | `.deploy-tasks-host.log` | Append-only log of completed task IDs (one-shot per machine). |
| `DEPLOY_TASKS_HOST_LOCK` | `.deploy-tasks-host.lock` | `flock` file guarding against concurrent runs. |

Paths are resolved relative to the runner's current working directory (the repo root by convention). Set them via shell environment, CI secrets, or the `deploy-tasks-host.local.sh` override file.

## Commands

| Command | Description | Options |
|---|---|---|
| `deploytasks:run` | Execute pending tasks | `--dry-run`, `--rerun-all`, `--id=<id>`, `--group=<name>` (repeatable), `--require-some` |
| `deploytasks:status` | List tasks with their execution state | `--no-state`, `--group=<name>` (repeatable), `--filter-status=<comma-list>` |
| `deploytasks:show <id>` | Show full metadata and every stored execution record for a single task | — |
| `deploytasks:skip <id>` | Mark a task as skipped (interactive confirm) | `--group=<name>` |
| `deploytasks:reset <id>` | Clear the execution record for a task (interactive confirm) | `--group=<name>`, `--force` / `--yes` |
| `deploytasks:generate:container` | Generate a blank deploy task (PHP class, runs inside the Symfony container) | `--dir`, `--namespace` |
| `deploytasks:generate:host` | Generate a blank deploy task (bash script, runs on the host outside the container) | `--dir` |
| `deploytasks:rollup` | Clear history and mark all tasks as executed | `--no-interaction`, `--group=<name>` (repeatable), `--force` / `--yes` |
| `deploytasks:create-schema` | Create the storage table | `--dump-sql` |

## Task Groups

Tasks can be assigned to one or more groups (e.g. `predeploy`, `postdeploy`) to split a deploy into named stages. Without `--group`, only ungrouped tasks run; with `--group=<name>`, only tasks declaring that group run, and a multi-group task records one row per matching slot.

```php
#[AsDeployTask(id: 'task_...', groups: 'predeploy')]
#[AsDeployTask(id: 'task_...', groups: ['predeploy', 'postdeploy'])]
```

See [`docs/creating-tasks.md`](docs/creating-tasks.md#group-filtering) and [`docs/commands.md`](docs/commands.md) for details.

## Documentation

Full index: [`docs/index.md`](docs/index.md).

| Topic | File |
|---|---|
| Installation, requirements, optional packages | [`docs/installation.md`](docs/installation.md) |
| Creating tasks (attributes, env/group filtering, IDs) | [`docs/creating-tasks.md`](docs/creating-tasks.md) |
| Console commands reference | [`docs/commands.md`](docs/commands.md) |
| Storage backends (filesystem, database, custom) | [`docs/storage.md`](docs/storage.md) |
| Lifecycle events | [`docs/events.md`](docs/events.md) |
| Logging (PSR-3, Monolog channel) | [`docs/logging.md`](docs/logging.md) |
| Testing (unit, functional, command tester) | [`docs/testing.md`](docs/testing.md) |
| Security model, host runner hardening | [`docs/security.md`](docs/security.md) |
| Advanced (custom ID generator, custom sorter, locking) | [`docs/advanced.md`](docs/advanced.md) |
| Troubleshooting / FAQ | [`docs/troubleshooting.md`](docs/troubleshooting.md) |

## Security

### Logger routing for DBAL-backed storage

The bundle emits PSR-3 `error` records from the task runner on every task failure.
The context carries the original throwable so handlers can surface stack traces,
but when the failure chain contains a `Doctrine\DBAL\Exception` the runner drops
the full throwable and substitutes string-only fields (`exception_class`,
`exception_message`, `previous_message`). This is a defence-in-depth measure:
DBAL driver exceptions raised during connection or authentication typically embed
the full DSN, including credentials, into their message and stack trace — forwarding
that object to a handler that renders `previous.trace` (the Monolog default) would
export the password into every sink the channel writes to.

Operators routing the `soviann_deploy_tasks` Monolog channel to a shared destination
(central logging, stderr slurpers, chat alerts) should still take care:

- Prefer a handler that renders context as JSON with a normaliser configured to
  limit trace depth (Monolog's `LineFormatter` with `$allowInlineLineBreaks = false`
  or the `JsonFormatter` + `NormalizerFormatter::setMaxNormalizeDepth(1)`) rather
  than rolling dumps that serialise every nested exception verbatim.
- Keep the dedicated `soviann_deploy_tasks` channel routed to a handler you control — don't
  fan it into generic "application error" sinks whose redaction guarantees are not
  under your control.
- Set the application's Doctrine connection DSN via environment variables, not
  inline configuration, so accidental exception dumps in other code paths can't
  capture the password from the container parameters.

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md) for guidelines.

## License

MIT. See [LICENSE](LICENSE) for details.
