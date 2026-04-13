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
use Soviann\DeployTasks\Contract\Attribute\AsDeployTask;
use Soviann\DeployTasks\Contract\DeployTaskInterface;
use Soviann\DeployTasks\Contract\TaskResult;
use Symfony\Component\Console\Output\OutputInterface;

#[AsDeployTask(id: 'task_20260412_seed_categories', priority: 10)]
final class SeedCategoriesTask implements DeployTaskInterface
{
    public function getDescription(): string
    {
        return 'Seeds the categories table with initial data.';
    }

    public function run(OutputInterface $output): int
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

## Configuration

```yaml
# config/packages/deploy_tasks.yaml
deploy_tasks:
    id_resolver: ~
    order_resolver: ~
    default_timeout: 300
    storage:
        type: filesystem  # filesystem | database
        filesystem:
            path: '%kernel.project_dir%/var/deploy-tasks'
        database:
            connection: default
            table: deploy_task_executions
            transaction_wrap: false
    events:
        enabled: true
    lock:
        enabled: true
```

## Storage Backends

**Filesystem** (default): stores execution records as files in `var/deploy-tasks/`. No additional dependencies required.

**Database**: stores execution records in a database table. Requires `doctrine/dbal`.

## Commands

| Command | Description | Options |
|---|---|---|
| `deploytasks:run` | Execute pending tasks | `--dry-run`, `--force`, `--id=<id>` |
| `deploytasks:status` | List tasks with their execution state | `--no-state` |
| `deploytasks:skip <id>` | Mark a task as skipped | |
| `deploytasks:reset <id>` | Clear the execution record for a task (interactive confirm) | |
| `deploytasks:generate [name]` | Generate a blank task class | `--dir` |

## Documentation

See the [`docs/`](docs/) directory for detailed documentation.

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md) for guidelines.

## License

MIT. See [LICENSE](LICENSE) for details.
