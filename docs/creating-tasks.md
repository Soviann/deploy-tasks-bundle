# Creating Tasks

A deploy task is a class that implements `DeployTaskInterface` and is registered as a Symfony service. Tasks are discovered automatically via autoconfiguration when the class is in a directory covered by your `services.yaml`.

## Generating a task

The quickest way to create a task is via the generator command:

```bash
bin/console deploytasks:generate:container                    # DeployTask20260412143000.php
bin/console deploytasks:generate:container --dir=src/Task/    # custom target directory
```

The generated file is placed in `src/DeployTasks/Task/` by default and contains a stub `run()` method ready to implement. The filename is always `DeployTask<timestamp>.php` — the command takes no positional argument. Use `deploytasks:generate:host` instead to scaffold a host-scope bash script.

## Attribute-based tasks (recommended)

Add `#[AsDeployTask]` to your class to attach metadata such as priority, environment restriction, and timeout.

```php
use Soviann\DeployTasksBundle\Attribute\AsDeployTask;
use Soviann\DeployTasksBundle\DeployTaskInterface;
use Soviann\DeployTasksBundle\TaskResult;
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

If you do not need the metadata provided by the attribute, you can implement `DeployTaskInterface` directly without it. The task will still be discovered and executed, but it will have no priority, no environment restriction, no timeout override, no transactional flag, and no group assignment.

## Attribute options

| Parameter | Type | Default | Description |
|---|---|---|---|
| `id` | `string` | `''` | Unique task identifier. Resolution order: `TaskIdProviderInterface::getTaskId()` if the task implements it and returns non-empty, then this `id` if non-empty, then the configured `TaskIdGeneratorInterface::generate()` (default: `DefaultTaskIdGenerator`). |
| `priority` | `int` | `0` | Higher value runs first |
| `env` | `string\|string[]\|null` | `null` | Restrict execution to one or more environments; `null` runs everywhere |
| `timeout` | `?int` | `null` | Override the bundle's `default_timeout` for this task (seconds) |
| `transactional` | `?bool` | `null` | Wrap execution in a transaction (requires a storage implementing `TransactionalStorageInterface` — `true` on a non-transactional storage fails the container build). `null` defers to the active storage's `transactional` setting (database default: `true`, filesystem default: `false`). |
| `description` | `?string` | `null` | Human-readable description used when `getDescription()` returns an empty string. Mirrors the `id` resolution: interface method wins when non-empty, attribute fallback otherwise. |
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

`deploytasks:skip` requires `--group` when the task declares groups. `deploytasks:reset` accepts an optional `--group` (default: reset every declared slot). `deploytasks:status` always shows one row per declared slot, and `deploytasks:rollup` marks every slot as run unless `--group` narrows the scope.

## Execution order

1. Higher `priority` runs first.
2. Same priority: the date extracted from the task ID (format `YYYYMMDD` embedded anywhere in the ID string) determines order — oldest date first.
3. Same date or no date: original service registration order.

The date is extracted from the **resolved** task ID, so a custom `TaskIdGeneratorInterface` producing IDs without an embedded `YYYYMMDD` substring silently falls back to registration order for ties.

## Return values

`run()` must return one of the `TaskResult` enum cases:

| Constant | Value | Meaning |
|---|---|---|
| `TaskResult::SUCCESS` | `0` | Task completed successfully; recorded as `ran` |
| `TaskResult::FAILURE` | `1` | Task failed; recorded as `failed` and will be retried on the next run |
| `TaskResult::SKIPPED` | `2` | Task decided to skip itself; recorded as `skipped` in storage |

`TaskResult::LOCKED` exists but is produced by the runner when a concurrent lock is held; do not return it from `run()`.

## Task ID resolution

The bundle resolves task IDs in this order:

1. **`TaskIdProviderInterface::getTaskId()`** — if the task implements `TaskIdProviderInterface` and returns a non-empty value, it wins.
2. **Attribute `id`** — if `#[AsDeployTask(id: '...')]` is present and non-empty.
3. **Configured `TaskIdGeneratorInterface`** — by default `DefaultTaskIdGenerator` strips `Task`/`DeployTask` prefix/suffix, converts CamelCase to snake_case, and prefixes numeric remainders with `task_` (see [`docs/advanced.md`](advanced.md#custom-id-generator)). Examples: `SeedCategoriesTask` → `seed_categories`, `DeployTask20260412143000` → `task_20260412143000`.

If both `getTaskId()` and the attribute `id` return non-empty **different** values, a `E_USER_WARNING` is triggered and the interface value takes precedence.

Whatever the source, the resolved ID must match `AsDeployTask::TASK_ID_PATTERN` (`^[a-zA-Z0-9._-]+$`) — attribute IDs are validated at construction, provider/generator IDs at registry boot.

Most tasks only need the attribute. Use `TaskIdProviderInterface` when you need to compute the ID dynamically:

```php
use Soviann\DeployTasksBundle\Identifier\TaskIdProviderInterface;
use Soviann\DeployTasksBundle\TaskResult;
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

Task IDs must be unique across the entire application. Duplication is detected at two layers:

- **Compile time** — the compiler pass calls `TaskIdGeneratorInterface::generateStatic()` for every tagged task without an explicit attribute ID and throws `LogicException` on collision. Tasks implementing `TaskIdProviderInterface` are skipped at compile time — their real ID only exists at runtime, so the registry catches them at boot.
- **Runtime** — `TaskRegistry` re-checks resolved IDs on boot and throws `DuplicateTaskIdException`. This covers `TaskIdProviderInterface` tasks and any task whose generator returned `null` from `generateStatic()` to opt out of compile-time detection.

Recommended naming convention: `task_YYYYMMDDHHMMSS_<description_in_snake_case>`.

## Host-scope tasks

Container-scope tasks (the default) run through the Symfony kernel and are the right fit for 99% of use cases. Host-scope tasks run as plain bash scripts on the host machine, outside the container — use them when you need to touch the host filesystem, invoke host-only binaries, or sequence deploy steps that cannot run from inside the container. See [README → Host-scope tasks](../README.md#host-scope-tasks) for setup and operation details.
