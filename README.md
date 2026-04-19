# DeployTasksBundle

[![Latest Stable Version](https://poser.pugx.org/soviann/deploy-tasks-bundle/v/stable)](https://packagist.org/packages/soviann/deploy-tasks-bundle)
[![License](https://poser.pugx.org/soviann/deploy-tasks-bundle/license)](https://packagist.org/packages/soviann/deploy-tasks-bundle)

A Symfony bundle for running one-time deploy tasks — data migrations, cache warmups, seed scripts — via CLI. Each task is tracked so it executes exactly once across deployments.

## Requirements

- PHP >= 8.2
- Symfony 6.4 or 7.0+

## Installation

```bash
composer require soviann/deploy-tasks-bundle
```

With Symfony Flex, the bundle is registered automatically. Without Flex, register it manually in `config/bundles.php`:

```php
return [
    // ...
    Soviann\DeployTasksBundle\DeployTasksBundle::class => ['all' => true],
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

## Running shell commands

Tasks that shell out to external binaries (asset builds, `rsync`, CLI migrations) can opt into the `RunsProcesses` trait. It wraps `symfony/process` to stream stdout/stderr, enforce a per-call timeout, and map the outcome to a `TaskResult`.

Install the soft dependency first:

```bash
composer require symfony/process
```

Then compose the trait into your task:

```php
use Soviann\DeployTasksBundle\Attribute\AsDeployTask;
use Soviann\DeployTasksBundle\DeployTaskInterface;
use Soviann\DeployTasksBundle\RunsProcesses;
use Soviann\DeployTasksBundle\TaskResult;
use Symfony\Component\Console\Output\OutputInterface;

#[AsDeployTask(id: 'build_assets', timeout: 120)]
final class BuildAssetsTask implements DeployTaskInterface
{
    use RunsProcesses;

    public function getDescription(): string
    {
        return 'Build frontend assets';
    }

    public function run(OutputInterface $output): TaskResult
    {
        return $this->runProcess(
            ['npm', 'run', 'build'],
            $output,
            cwd: __DIR__.'/../../assets',
            timeout: 120,
        );
    }
}
```

Behavior notes:

- **Commands MUST be arrays.** No shell interpretation, no injection surface.
- **Timeout is per call.** It is not auto-read from `#[AsDeployTask(timeout: ...)]` — keep them aligned manually if you want them to match.
- **stdout streams as-is**; **stderr is wrapped in `<error>…</error>`** tags so the runner's styling applies.
- **Non-zero exit or timeout → `TaskResult::FAILURE`.** Any `ProcessExceptionInterface` (e.g. invalid cwd, unstartable process) is also mapped to `FAILURE` with an error message.

## Configuration

```yaml
# config/packages/deploy_tasks.yaml
deploy_tasks:
    id_generator: ~              # service ID of a custom TaskIdGeneratorInterface
    sorter: ~                    # service ID of a custom TaskSorterInterface
    default_timeout: 300         # seconds
    storage:
        type: filesystem         # filesystem | database | custom
        filesystem:
            path: '%kernel.project_dir%/var/deploy-tasks'
            transactional: false     # filesystem storage ignores these (no transaction support)
            all_or_nothing: false
        database:
            connection: default
            table: deploy_task_executions
            auto_create_table: true
            id_column: id
            id_column_length: 255
            status_column: status
            executed_at_column: executed_at
            error_column: error
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
    generate:
        directory: src/DeployTasks/Task/
        template: ~              # path to a custom PHP template
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

Each successful task is logged in `.deploy-tasks-host.log` (one ID per line). Re-running the script skips already-logged tasks. A task is one-shot per environment.

### `.env` loading

The runner loads env files in Symfony cascade order (lowest to highest priority):
1. `.env`
2. `.env.$APP_ENV`
3. `.env.local`
4. `.env.$APP_ENV.local`
5. `deploy-tasks-host.local.sh` (bash source, for overrides the `.env` parser can't express)

Values in host task scripts reference exported env vars (`$NAS_HOST`, etc.).

### Concurrency

A `flock` lock at `.deploy-tasks-host.lock` prevents concurrent runs on the same machine.

## Commands

| Command | Description | Options |
|---|---|---|
| `deploytasks:run` | Execute pending tasks | `--dry-run`, `--force`, `--id=<id>`, `--group=<name>` (repeatable) |
| `deploytasks:status` | List tasks with their execution state | `--no-state`, `--group=<name>` (repeatable) |
| `deploytasks:skip <id>` | Mark a task as skipped | `--group=<name>` |
| `deploytasks:reset <id>` | Clear the execution record for a task (interactive confirm) | `--group=<name>` |
| `deploytasks:generate:container` | Generate a blank container-scope task class (alias: `deploytasks:generate`) | `--dir` |
| `deploytasks:generate:host` | Generate a blank host-scope deploy script | `--dir` |
| `deploytasks:rollup` | Clear history and mark all tasks as executed | `--no-interaction`, `--group=<name>` (repeatable) |
| `deploytasks:create-schema` | Create the DBAL storage table | `--dump-sql` |

## Task Groups

Tasks can be assigned to one or more groups (e.g. `predeploy`, `postdeploy`) to split a deploy into named stages. Without `--group`, only ungrouped tasks run; with `--group=<name>`, only tasks declaring that group run, and a multi-group task records one row per matching slot.

```php
#[AsDeployTask(id: 'task_...', groups: 'predeploy')]
#[AsDeployTask(id: 'task_...', groups: ['predeploy', 'postdeploy'])]
```

See [`docs/creating-tasks.md`](docs/creating-tasks.md#group-filtering) and [`docs/commands.md`](docs/commands.md) for details.

## Documentation

See the [`docs/`](docs/) directory for detailed documentation.

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md) for guidelines.

## License

MIT. See [LICENSE](LICENSE) for details.
