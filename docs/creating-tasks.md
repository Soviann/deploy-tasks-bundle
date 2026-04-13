# Creating Tasks

A deploy task is a class that implements `DeployTaskInterface` and is registered as a Symfony service. Tasks are discovered automatically via autoconfiguration when the class is in a directory covered by your `services.yaml`.

## Generating a task

The quickest way to create a task is via the generator command:

```bash
bin/console deploytasks:generate                     # Task20260412143000.php
bin/console deploytasks:generate SeedCategories      # Task20260412143000SeedCategories.php
bin/console deploytasks:generate Foo --dir=src/Task/ # custom target directory
```

The generated file is placed in `src/DeployTasks/Task/` by default and contains a stub `run()` method ready to implement.

## Attribute-based tasks (recommended)

Add `#[AsDeployTask]` to your class to attach metadata such as priority, environment restriction, and timeout.

```php
use Soviann\DeployTasks\Contract\Attribute\AsDeployTask;
use Soviann\DeployTasks\Contract\DeployTaskInterface;
use Soviann\DeployTasks\Contract\TaskResult;
use Symfony\Component\Console\Output\OutputInterface;

#[AsDeployTask(id: 'task_20260412143000_seed_categories', priority: 10, description: 'Seed categories')]
final class SeedCategoriesTask implements DeployTaskInterface
{
    public function getId(): string
    {
        return 'task_20260412143000_seed_categories';
    }

    public function getDescription(): string
    {
        return 'Seed categories';
    }

    public function run(OutputInterface $output): int
    {
        // Your logic here
        $output->writeln('Seeding categories...');

        return TaskResult::SUCCESS;
    }
}
```

The `id` in the attribute and the value returned by `getId()` must match.

## Interface-only tasks

If you do not need the metadata provided by the attribute, you can implement `DeployTaskInterface` directly without it. The task will still be discovered and executed, but it will have no priority, no environment restriction, no timeout override, and no transactional flag.

## Attribute options

| Parameter | Type | Default | Description |
|---|---|---|---|
| `id` | `string` | required | Unique task identifier across the entire application |
| `priority` | `int` | `0` | Higher value runs first |
| `env` | `string\|string[]\|null` | `null` | Restrict execution to one or more environments; `null` runs everywhere |
| `timeout` | `?int` | `null` | Override the bundle's `default_timeout` for this task (seconds) |
| `transactional` | `bool` | `false` | Wrap execution in a database transaction (requires `DbalStorage`) |
| `description` | `?string` | `null` | Override the value returned by `getDescription()` |

## Environment filtering

```php
#[AsDeployTask(id: 'task_...', env: 'prod')]           // prod only
#[AsDeployTask(id: 'task_...', env: ['dev', 'test'])]  // dev and test only
#[AsDeployTask(id: 'task_...', env: null)]             // all environments (default)
```

Tasks whose `env` does not match the current environment are silently skipped at runtime.

## Execution order

1. Higher `priority` runs first.
2. Same priority: the date extracted from the task ID (format `YYYYMMDD` embedded anywhere in the ID string) determines order — oldest date first.
3. Same date or no date: original service registration order.

## Return values

`run()` must return one of the `TaskResult` constants:

| Constant | Value | Meaning |
|---|---|---|
| `TaskResult::SUCCESS` | `0` | Task completed successfully; recorded as `ran` |
| `TaskResult::FAILURE` | `1` | Task failed; recorded as `failed` and will be retried on the next run |
| `TaskResult::SKIPPED` | `2` | Task decided to skip itself; not recorded in storage |

## Task IDs

Task IDs must be unique across the entire application. The bundle validates for duplicates at compile time and throws a `LogicException` if two services share the same ID.

Recommended naming convention: `task_YYYYMMDDHHMMSS_<description_in_snake_case>`.

Duplicate IDs are detected at container compilation and cause a `LogicException`.
