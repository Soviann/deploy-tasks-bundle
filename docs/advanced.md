# Advanced Usage

## Custom Task Sorter

Implement `TaskSorterInterface` to define a custom task execution order.

```php
use Soviann\DeployTasksBundle\Sorting\TaskSorterInterface;
use Soviann\DeployTasksBundle\DeployTaskInterface;

final class MySorter implements TaskSorterInterface
{
    /**
     * @param array<DeployTaskInterface> $tasks
     *
     * @return list<DeployTaskInterface>
     */
    public function sort(array $tasks): array
    {
        // Your custom ordering logic
        return \array_values($tasks);
    }
}
```

Register it in the bundle configuration:

```yaml
soviann_deploy_tasks:
    sorter: App\Deploy\MySorter
```

## Lock Configuration

When `symfony/lock` is installed, the runner acquires a lock before execution to prevent concurrent runs.

```yaml
soviann_deploy_tasks:
    lock:
        enabled: true  # default
```

If the lock cannot be acquired, the command exits with code `75` (`EX_TEMPFAIL`, sysexits.h). CI pipelines should treat this as "retry recommended" and re-run the deploy step rather than treating it as a genuine failure (`1`).

Disable locking by setting `lock.enabled: false` or by not installing `symfony/lock`.

## Slow-Task Threshold

After a task completes, the runner compares its wall-clock duration against a threshold and logs a warning when the task ran longer. Nothing is ever killed — the check exists to flag tasks that are slower than expected, not to enforce a limit. (For a real kill, see the hard `Process` timeout in [`docs/creating-tasks.md` → Running shell commands](creating-tasks.md#running-shell-commands).)

The default threshold is 300 seconds (5 minutes). Override globally:

```yaml
soviann_deploy_tasks:
    slow_task_threshold: 600  # 10 minutes
```

Or per task, for one that is legitimately slow and should not warn on every deploy:

```php
#[AsDeployTask(id: 'task_heavy_migration', slowTaskThreshold: 1800)]  // 30 minutes
```

Set `slow_task_threshold: 0` to disable the check entirely: no warning is emitted regardless of duration. `#[AsDeployTask(slowTaskThreshold: 0)]` does the same for a single task.

## Transaction Wrapping

Per-task transaction wrapping only applies when the active storage is configured with `storage.<type>.transaction_mode: per_task` (see [`docs/storage.md` → Transaction mode](storage.md#transaction-mode)). Under `per_task`, every task is wrapped by default — opt one out with:

```php
#[AsDeployTask(id: 'task_data_migration', transactional: false)]
```

This requires a storage backend implementing `TransactionalStorageInterface`. The built-in `DbalStorage` supports this out of the box. The task's `run()` method and the storage `save()` call are wrapped in a single transaction. If the task fails, both the data changes and the execution record are rolled back.

`transactional` has no effect under `transaction_mode: none` or `all_or_nothing`, and declaring it there fails the container build instead of being silently ignored: `transactional: true` under `none` (nothing to wrap it into), or `transactional: false` under `all_or_nothing` (the run-wide transaction cannot exempt one task). Independently of mode, `transactional: true` on a storage that does not implement `TransactionalStorageInterface` at all always fails the container build with `IncompatibleStorageException` — an explicit per-task transaction demand never silently degrades to unwrapped execution. Switch to `storage.type: database` (or a custom transactional backend), or remove the flag.

## Custom ID Generator

Implement `TaskIdGeneratorInterface` to customize how task IDs are derived from class names. The default generator (`DefaultTaskIdGenerator`) produces IDs like `task_20260412143000` (from `DeployTask20260412143000`) or `seed_categories` (from `SeedCategoriesTask`).

```php
use Soviann\DeployTasksBundle\Identifier\TaskIdGeneratorInterface;

final class MyIdGenerator implements TaskIdGeneratorInterface
{
    public function generate(string $className): string
    {
        // Your custom ID generation logic
    }

    public static function generateStatic(string $className): ?string
    {
        // Static variant for compile-time duplicate detection.
        // Return null if the ID requires runtime context.
    }
}
```

Register it in the bundle configuration:

```yaml
soviann_deploy_tasks:
    id_generator: App\Deploy\MyIdGenerator
```

When `id_generator` is `null` (default), the built-in `DefaultTaskIdGenerator` is used. The generator is the third step of task-ID resolution (see [`creating-tasks.md`](creating-tasks.md#task-id-resolution)): it runs only when neither `TaskIdProviderInterface::getTaskId()` nor the attribute `id` produces a non-empty value, and it is also used by `deploytasks:generate:container` for the initial ID stub.

> **Note:** `generateStatic()` is called at compile time by the compiler pass for duplicate ID detection (see the uniqueness paragraph in [`creating-tasks.md`](creating-tasks.md#task-id-resolution)). If your implementation requires runtime context (e.g. injected services), return `null` to opt out of compile-time detection — duplicates will then be caught at runtime by the `TaskRegistry`.

## Run summary (`RunResult`)

`TaskRunner::runAll()` returns a `Soviann\DeployTasksBundle\Runner\RunResult` value object summarising the outcome. The built-in `deploytasks:run` command consumes it to decide its exit code and to format the user-facing summary line, and it is the shape you read from any custom wrapper that drives `TaskRunner` directly.

| Field | Type | Meaning |
|---|---|---|
| `ran` | `int` | Tasks executed successfully during this run. In dry-run mode this instead holds the number of pending tasks the runner *would* have executed. |
| `skipped` | `int` | Slots not executed because they already hold a record — they will not run again. |
| `deferred` | `int` | Slots whose task returned `TaskResult::SKIPPED` from its `run()`. Nothing is recorded for them: the slot stays pending and the task is retried on the next run. Always `0` in dry runs. |
| `failed` | `int` | Tasks whose `run()` threw or returned `TaskResult::FAILURE`, plus tasks a caller-built transaction rolled back. |
| `locked` | `bool` | `true` when the run was short-circuited because another process held the runner lock. No tasks ran in that case (`ran`/`skipped`/`deferred`/`failed` are all `0`). |
| `dryRun` | `bool` | `true` when this result describes a dry run — nothing was executed or persisted, and `ran` counts the slots that *would* run. Defaults to `false`. |

Convenience method: `isSuccessful()` returns `true` iff `failed === 0 && !locked` — use it in custom CLI wrappers to map to process exit codes.

## Task registry: `all()` vs `allRegistered()`

`TaskRegistry` exposes two task accessors with different semantics:

- `all(?string $environment = null, array $groups = []): array<string, DeployTaskInterface>` — returns the subset that matches the current runtime filter. When `$environment` is non-null, tasks whose `#[AsDeployTask(env: ...)]` attribute excludes that environment are dropped. When `$groups` is non-empty, only tasks declaring at least one of the listed groups are returned. This is the accessor every runtime code path uses (`TaskRunner`, `deploytasks:run`, `deploytasks:status`).
- `allRegistered(): array<string, DeployTaskInterface>` — returns every task registered with the bundle, unfiltered. Use it for tooling that must inspect the full registered surface regardless of environment or group scoping (compiler-pass diagnostics, admin listings, custom introspection commands).

Both return the same `array<string, DeployTaskInterface>` shape keyed by resolved task ID; the difference is purely the filter scope.
