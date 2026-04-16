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
    public function getDescription(): string
    {
        return 'Seed categories';
    }

    public function run(OutputInterface $output): TaskResult
    {
        // Your logic here
        $output->writeln('Seeding categories...');

        return TaskResult::SUCCESS;
    }
}
```

## Interface-only tasks

If you do not need the metadata provided by the attribute, you can implement `DeployTaskInterface` directly without it. The task will still be discovered and executed, but it will have no priority, no environment restriction, no timeout override, and no transactional flag.

## Attribute options

| Parameter | Type | Default | Description |
|---|---|---|---|
| `id` | `string` | `''` | Unique task identifier; overridden by `TaskIdProviderInterface::getTaskId()` if implemented, falls back to FQCN auto-deduction when empty |
| `priority` | `int` | `0` | Higher value runs first |
| `env` | `string\|string[]\|null` | `null` | Restrict execution to one or more environments; `null` runs everywhere |
| `timeout` | `?int` | `null` | Override the bundle's `default_timeout` for this task (seconds) |
| `transactional` | `?bool` | `null` | Wrap execution in a transaction (requires a storage implementing `TransactionalStorageInterface`). `null` defers to the active storage's `transactional` setting (database default: `true`, filesystem default: `false`). |
| `description` | `?string` | `null` | Override the value returned by `getDescription()` |
| `groups` | `string\|string[]\|null` | `null` | Group(s) the task belongs to; `null` = default slot (runs when `deploytasks:run` is called without `--group`) |

## Environment filtering

```php
#[AsDeployTask(id: 'task_...', env: 'prod')]           // prod only
#[AsDeployTask(id: 'task_...', env: ['dev', 'test'])]  // dev and test only
#[AsDeployTask(id: 'task_...', env: null)]             // all environments (default)
```

Tasks whose `env` does not match the current environment are silently skipped at runtime.

## Group filtering

Groups split a deploy into named stages. Typical use cases: a `predeploy` group that runs before switching traffic to the new code, and a `postdeploy` group that runs after.

```php
#[AsDeployTask(id: 'task_...', groups: 'predeploy')]                  // single group
#[AsDeployTask(id: 'task_...', groups: ['predeploy', 'postdeploy'])]  // multiple groups
#[AsDeployTask(id: 'task_...', groups: null)]                         // default slot (omit --group to run)
```

Execution is scoped per `(task, group)` slot:

- A task with `groups: null` only runs when `deploytasks:run` is called without `--group`.
- A task with `groups: 'predeploy'` only runs when `--group=predeploy` is passed.
- A multi-group task records one row per slot it belongs to, so running `--group=predeploy --group=postdeploy` executes the task twice (once per slot) and stores two rows. Running `--group=predeploy` later only re-runs the predeploy slot.
- `--group=postdeploy` leaves default-slot tasks untouched; `--group` with no declared match is a success (nothing to run).

`deploytasks:skip` and `deploytasks:reset` require `--group` when the task declares groups. `deploytasks:status` always shows one row per declared slot, and `deploytasks:rollup` marks every slot as run unless `--group` narrows the scope.

## Execution order

1. Higher `priority` runs first.
2. Same priority: the date extracted from the task ID (format `YYYYMMDD` embedded anywhere in the ID string) determines order — oldest date first.
3. Same date or no date: original service registration order.

## Return values

`run()` must return one of the `TaskResult` enum cases:

| Constant | Value | Meaning |
|---|---|---|
| `TaskResult::SUCCESS` | `0` | Task completed successfully; recorded as `ran` |
| `TaskResult::FAILURE` | `1` | Task failed; recorded as `failed` and will be retried on the next run |
| `TaskResult::SKIPPED` | `2` | Task decided to skip itself; recorded as `skipped` in storage |

## Task ID resolution

The bundle resolves task IDs in this order:

1. **`TaskIdProviderInterface::getTaskId()`** — if the task implements `TaskIdProviderInterface` and returns a non-empty value, it wins.
2. **Attribute `id`** — if `#[AsDeployTask(id: '...')]` is present and non-empty.
3. **FQCN auto-deduction** — the ID is derived from the short class name: strip `Task`/`DeployTask` suffix, then convert to `snake_case`. Example: `SeedCategories` → `seed_categories`.

If both `getTaskId()` and the attribute `id` return non-empty **different** values, a `E_USER_WARNING` is triggered and the interface value takes precedence.

Most tasks only need the attribute. Use `TaskIdProviderInterface` when you need to compute the ID dynamically:

```php
use Soviann\DeployTasks\Contract\TaskIdProviderInterface;
use Soviann\DeployTasks\Contract\TaskResult;
use Symfony\Component\Console\Output\OutputInterface;

final class DynamicIdTask implements TaskIdProviderInterface
{
    public function getTaskId(): string
    {
        return 'task_' . self::VERSION . '_migrate';
    }

    // ...getDescription(), run()
}
```

Task IDs must be unique across the entire application. Duplicate IDs are detected at container compilation and cause a `LogicException`.

Recommended naming convention: `task_YYYYMMDDHHMMSS_<description_in_snake_case>`.
