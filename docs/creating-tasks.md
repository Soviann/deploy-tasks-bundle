# Creating Tasks

A deploy task is a class that implements `DeployTaskInterface` and is registered as a Symfony service. Tasks are discovered automatically via autoconfiguration when the class is in a directory covered by your `services.yaml`.

## Generating a task

The quickest way to create a task is via the generator command:

```bash
bin/console deploytasks:generate                    # DeployTask20260412143000.php
bin/console deploytasks:generate --dir=src/Task/    # custom target directory
```

The generated file is placed in `src/DeployTasks/Task/` by default and contains a stub `run()` method ready to implement. The filename is always `DeployTask<timestamp>.php` — the command takes no positional argument. Use `deploytasks:host:generate` instead to scaffold a host-scope bash script.

## Attribute-based tasks (recommended)

Add `#[AsDeployTask]` to your class to attach metadata such as priority, environment restriction, and a slow-task threshold.

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

If you do not need the metadata provided by the attribute, you can implement `DeployTaskInterface` directly without it. The task will still be discovered and executed, but it will have no priority, no environment restriction, no timeout or slow-task threshold override, no transactional flag, and no group assignment.

## Attribute options

| Parameter | Type | Default | Description |
|---|---|---|---|
| `id` | `string` | `''` | Unique task identifier. Resolution order: `TaskIdProviderInterface::getTaskId()` if the task implements it and returns non-empty, then this `id` if non-empty, then auto-derived from the class name (see [Task ID resolution](#task-id-resolution)). |
| `priority` | `int` | `0` | Higher value runs first |
| `env` | `string\|string[]\|null` | `null` | Restrict execution to one or more environments; `null` runs everywhere |
| `timeout` | `?int` | `null` | Hard timeout in seconds for processes run through `ProcessRunnerTrait::runProcess()` — the process is killed past it. No effect outside that trait; see [Running shell commands](#running-shell-commands) |
| `slowTaskThreshold` | `?int` | `null` | Per-task override of the bundle's `slow_task_threshold` (seconds): the runner logs a warning when the task runs longer — nothing is killed. `0` disables the check for this task; `null` follows the configured threshold |
| `transactional` | `?bool` | `null` | Per-task override, only meaningful under `storage.<backend>.transaction_mode: per_task`: `null`/unset wraps the task like every other one, `false` opts it out. `true` on a storage that doesn't implement `TransactionalStorageInterface`, `true` under `transaction_mode: none`, or `false` under `transaction_mode: all_or_nothing` all fail the container build — see [`docs/storage.md` → Transaction mode](storage.md#transaction-mode). |
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

Execution is scoped per `(task, group)` slot. The uniform rule across `deploytasks:run`, `run --id`, `status`, `skip`, `reset`, and `rollup`: **without `--group`, every slot runs — the default (ungrouped) slot and every declared group; `--group=<name>` (repeatable) narrows to the tasks declaring the listed group(s).**

- A task with `groups: null` only runs when `deploytasks:run` is called without `--group` — an explicit `--group=<name>` never targets the default slot.
- A task with `groups: 'predeploy'` runs both when `deploytasks:run` is called without `--group` (bundled with every other slot) and when `--group=predeploy` is passed (targeted on its own).
- A multi-group task records one row per slot it belongs to. A bare invocation executes it once per declared group; `--group=predeploy --group=postdeploy` executes it twice (once per requested slot) and stores two rows. Running `--group=predeploy` later only re-runs the predeploy slot.
- `--group=postdeploy` leaves default-slot tasks (and other groups) untouched; `--group` with no declared match is a success (nothing to run).

`deploytasks:skip` and `deploytasks:reset` both accept an optional `--group` (default: every declared slot for skip, every recorded slot for reset); a bare invocation that resolves to more than one slot shows a single confirmation naming every targeted slot. `deploytasks:status` shows one row per slot (every slot by default, narrowed by `--group`), and `deploytasks:rollup` marks every slot as run unless `--group` narrows the scope. `TaskGroupRequiredException` does not exist — omitting `--group` is never an error.

## Execution order

1. Higher `priority` runs first.
2. Same priority: the date extracted from the task ID (format `YYYYMMDD` embedded anywhere in the ID string) determines order — oldest date first.
3. Same date or no date: original service registration order.

The date is extracted from the **resolved** task ID, so an ID without an embedded `YYYYMMDD` substring silently falls back to registration order for ties.

## Return values

`run()` must return one of the `TaskResult` enum cases:

| Constant | Meaning |
|---|---|
| `TaskResult::SUCCESS` | Task completed successfully; recorded as `ran` |
| `TaskResult::FAILURE` | Task failed; recorded as `failed` and will be retried on the next run |
| `TaskResult::SKIPPED` | Task decided to skip itself (preconditions not met); nothing is recorded — the slot stays pending and the task is retried on the next run. Use `deploytasks:skip` to skip a task permanently |

## Task ID resolution

The bundle resolves task IDs in this order:

1. **`TaskIdProviderInterface::getTaskId()`** — if the task implements `TaskIdProviderInterface` and returns a non-empty value, it wins.
2. **Attribute `id`** — if `#[AsDeployTask(id: '...')]` is present and non-empty.
3. **Auto-derived from the class name** — the built-in generator strips the `Task`/`DeployTask` prefix/suffix, converts CamelCase to snake_case, and prefixes numeric remainders with `task_`. Examples: `SeedCategoriesTask` → `seed_categories`, `DeployTask20260412143000` → `task_20260412143000`.

If both `getTaskId()` and the attribute `id` return non-empty **different** values, the bundle throws `MismatchedTaskIdException` at registry boot instead of letting one silently win — remove one declaration or make them identical.

Whatever the source, the resolved ID must match `AsDeployTask::TASK_ID_PATTERN` (`^[a-zA-Z0-9._-]+\z`) — attribute IDs are validated at construction, provider/generator IDs at registry boot.

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

- **Compile time** — the compiler pass derives the ID from the class name for every tagged task without an explicit attribute ID and throws `LogicException` on collision. Tasks implementing `TaskIdProviderInterface` are skipped at compile time — their real ID only exists at runtime, so the registry catches them at boot.
- **Runtime** — `TaskRegistry` re-checks resolved IDs on boot and throws `DuplicateTaskIdException`. This covers `TaskIdProviderInterface` tasks.

Recommended naming convention: `task_YYYYMMDDHHMMSS_<description_in_snake_case>`.

## Host-scope tasks

Container-scope tasks (the default) run through the Symfony kernel and are the right fit for 99% of use cases. Host-scope tasks run as plain bash scripts on the host machine, outside the container — use them when you need to touch the host filesystem, invoke host-only binaries, or sequence deploy steps that cannot run from inside the container. See [`docs/host-tasks.md`](host-tasks.md) for setup and operation details.

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
            new Process(['npm', 'run', 'build'], cwd: __DIR__.'/../../assets'),
            $output,
        );
    }
}
```

Behavior notes:

- **You own the `Process` instance** — use array-form commands to avoid shell parsing, or `Process::fromShellCommandline()` if you deliberately need shell features.
- **`#[AsDeployTask(timeout: N)]` is applied automatically** as the `Process`'s hard timeout by `runProcess()`, but only when `N > 0` — in that case it overrides any timeout already set on the `Process` instance. When the attribute timeout is `null` or `0`, `runProcess()` leaves the `Process`'s own timeout untouched (see the trap below). Use `runProcessWithTimeout()` to apply a different explicit limit per call.
- **stdout streams as-is**; **stderr is wrapped in `<error>…</error>`** tags so the runner's styling applies.
- **Non-zero exit or timeout → `TaskResult::FAILURE`.** Any `ProcessExceptionInterface` (e.g. invalid cwd, unstartable process) is also mapped to `FAILURE` with an error message.
- **A hard-killed process is recorded as a plain failure.** The runner's [slow-task warning](advanced.md#slow-task-threshold) is a separate mechanism: it fires only for tasks that run to completion, and never kills anything.

### Hard-timeout traps

- **symfony/process has its own default timeout of 60 seconds**, independent of the `timeout` attribute. `runProcess()` only overrides the `Process`'s own timeout when the attribute declares `timeout: N` with `N > 0` — with `timeout: null` (the default) or an explicit `timeout: 0`, the `Process` instance is left untouched, so a `Process` you construct without an explicit `timeout` argument will still be hard-killed by symfony/process after 60 seconds. For a genuinely unlimited process, pass `timeout: null` to the `Process` constructor (or call `$process->setTimeout(null)` before running it):

  ```php
  $process = new Process(['rsync', '-a', $src, $dest], timeout: null);
  ```

- **A hard kill signals the direct child process only** — background children and pipeline stages it spawned (`&`, `|`, nested shells) are not reached and keep running. If your command forks background work, run it in its own process group (e.g. wrap it with `setsid …`) so it can be killed as a unit, or reap the children yourself. Left unmanaged, these orphans keep mutating state after the deploy has already recorded `TaskResult::FAILURE`.
