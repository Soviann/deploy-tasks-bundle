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

### Flex recipe

Until the bundle is on Packagist, enable the recipe endpoint:

```bash
composer config extra.symfony.endpoint --json '["https://api.github.com/repos/Soviann/flex-recipes/contents/index.json", "flex://defaults"]'
```

The endpoint repo (`Soviann/flex-recipes`) is private pre-release, so this requires `composer` GitHub auth; it becomes public at release. With the endpoint enabled, `composer require soviann/deploy-tasks-bundle` registers the bundle, publishes `config/packages/soviann_deploy_tasks.yaml`, installs the host runner (`bin/deploy-tasks-host.sh`), and adds the host-task `.gitignore` entries automatically.

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

Tasks that shell out to external binaries can opt into the `ProcessRunnerTrait`, which wraps `symfony/process` to stream output and enforce a per-call timeout. See [`docs/creating-tasks.md`](docs/creating-tasks.md#running-shell-commands) for setup and behavior notes.

## Configuration

```yaml
# config/packages/soviann_deploy_tasks.yaml
soviann_deploy_tasks:
    slow_task_threshold: 300     # seconds; warn when a task runs longer (nothing is killed)
    storage:
        type: filesystem         # filesystem | database | custom
        filesystem:
            path: '%kernel.project_dir%/var/deploy-tasks'
    lock:
        enabled: true
```

Full reference: [`docs/storage.md`](docs/storage.md) and [`docs/installation.md`](docs/installation.md).

## Storage Backends

**Filesystem** (default): stores execution records as files in `var/deploy-tasks/`. No additional dependencies required.

**Database**: stores execution records in a database table. Requires `doctrine/dbal`.

**Custom**: plug in any `TaskStorageInterface` implementation via `storage.type: custom`. See [`docs/storage.md`](docs/storage.md).

## Host-scope tasks

Host tasks run outside the Symfony container — useful for operations that must execute on the host (Docker restarts, SSH-driven commands, infrastructure prep). The host runner (`bin/deploy-tasks-host.sh`) is installed automatically by the Flex recipe; see [`docs/host-tasks.md`](docs/host-tasks.md) for the manual-install fallback, generation, execution, `.env` cascade, and concurrency details.

## Commands

| Command | Description | Options |
|---|---|---|
| `deploytasks:run` | Execute pending tasks | `--dry-run`, `--rerun-all`, `--id=<id>`, `--group=<name>` (repeatable), `--require-some` |
| `deploytasks:status` | List tasks with their execution state | `--no-state`, `--group=<name>` (repeatable), `--filter-status=<comma-list>` |
| `deploytasks:show <id>` | Show full metadata and every stored execution record for a single task | — |
| `deploytasks:skip <id>` | Mark a task as skipped (interactive confirm) | `--group=<name>` |
| `deploytasks:reset <id>` | Clear the execution record for a task (interactive confirm) | `--group=<name>`, `--force` / `--yes` |
| `deploytasks:generate:container` | Generate a blank deploy task (PHP class, runs inside the Symfony container) | `--dir`, `--namespace` |
| `deploytasks:host:generate` | Generate a blank deploy task (bash script, runs on the host outside the container) | `--dir` |
| `deploytasks:rollup` | Clear history and mark all tasks as executed | `--no-interaction`, `--group=<name>` (repeatable), `--force` / `--yes` |
| `deploytasks:create-schema` | Create the storage schema (storages implementing `SchemaManageableInterface`) | `--dump-sql` |
| `deploytasks:host:skip <id>` | Mark a host-scope task as done in the completion log (interactive confirm) | — |
| `deploytasks:host:reset <id>` | Remove a host-scope task's completion-log entry | `--no-interaction`, `--force` / `--yes` |
| `deploytasks:host:rollup` | Mark every pending host-scope task as done | `--no-interaction`, `--force` / `--yes` |

## Task Groups

Tasks can be assigned to one or more groups (e.g. `predeploy`, `postdeploy`) to split a deploy into named stages. Without `--group`, every command operates on every slot — the default (ungrouped) slot and every declared group; `--group=<name>` (repeatable) narrows to the tasks declaring the listed group(s), and a multi-group task records one row per matching slot.

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
| Host-scope tasks (host runner, `.env` cascade, concurrency) | [`docs/host-tasks.md`](docs/host-tasks.md) |
| Lifecycle events | [`docs/events.md`](docs/events.md) |
| Logging (PSR-3, Monolog channel) | [`docs/logging.md`](docs/logging.md) |
| Testing (unit, functional, command tester) | [`docs/testing.md`](docs/testing.md) |
| Security model, host runner hardening | [`docs/security.md`](docs/security.md) |
| Advanced (custom ID generator, custom sorter, locking) | [`docs/advanced.md`](docs/advanced.md) |
| Troubleshooting / FAQ | [`docs/troubleshooting.md`](docs/troubleshooting.md) |

## Security

Failure logs from DBAL-backed storage are scrubbed of full exception objects to avoid leaking connection credentials into shared log sinks. See [`docs/logging.md`](docs/logging.md#credential-safety-when-routing-the-channel) and [`docs/security.md`](docs/security.md) for the full trust model and hardening notes.

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md) for guidelines.

## License

MIT. See [LICENSE](LICENSE) for details.
