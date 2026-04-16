# Advanced Usage

## Custom Order Resolver

Implement `TaskOrderResolverInterface` to define a custom task execution order.

```php
use Soviann\DeployTasks\Contract\TaskOrderResolverInterface;
use Soviann\DeployTasks\Contract\OrderedTaskCollection;
use Soviann\DeployTasks\Contract\DeployTaskInterface;

final class MyOrderResolver implements TaskOrderResolverInterface
{
    /** @param array<DeployTaskInterface> $tasks */
    public function resolve(array $tasks): OrderedTaskCollection
    {
        // Your custom ordering logic
        return new OrderedTaskCollection(...$tasks);
    }
}
```

Register it in the bundle configuration:

```yaml
deploy_tasks:
    order_resolver: App\Deploy\MyOrderResolver
```

## Lock Configuration

When `symfony/lock` is installed, the runner acquires a lock before execution to prevent concurrent runs.

```yaml
deploy_tasks:
    lock:
        enabled: true  # default
```

If the lock cannot be acquired, the command exits with a warning instead of failing.

Disable locking by setting `lock.enabled: false` or by not installing `symfony/lock`.

## Timeout Behavior

The default timeout is 300 seconds (5 minutes). Override globally or per task.

Global override:

```yaml
deploy_tasks:
    default_timeout: 600  # 10 minutes
```

Per-task override via the attribute:

```php
#[AsDeployTask(id: 'task_heavy_migration', timeout: 1800)]  // 30 minutes
```

Timeout is tracked but does not kill the running task — a warning is logged when the threshold is exceeded. Design long-running tasks to handle interruption gracefully.

## Transaction Wrapping

For tasks that require database transaction support, set `transactional: true` on the attribute:

```php
#[AsDeployTask(id: 'task_data_migration', transactional: true)]
```

This requires a storage backend implementing `TransactionalStorageInterface`. The built-in `DbalStorage` supports this out of the box. The task's `run()` method and the storage `save()` call are wrapped in a single transaction. If the task fails, both the data changes and the execution record are rolled back.

If the active storage does not implement `TransactionalStorageInterface`, `transactional: true` is silently ignored.

## Custom ID Generator

Implement `TaskIdGeneratorInterface` to customize how task IDs are derived from class names. The default generator (`DefaultTaskIdGenerator`) produces IDs like `task_20260412143000_seed_categories`.

```php
use Soviann\DeployTasks\Contract\TaskIdGeneratorInterface;

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
deploy_tasks:
    id_generator: App\Deploy\MyIdGenerator
```

When `id_generator` is `null` (default), the built-in `DefaultTaskIdGenerator` is used. The generator is used for FQCN auto-deduction (when no explicit ID is provided) and by `deploytasks:generate` for new task IDs.

> **Note:** `generateStatic()` is called at compile time by the compiler pass for duplicate ID detection. If your implementation requires runtime context (e.g. injected services), return `null` to opt out of compile-time detection — duplicates will then be caught at runtime by the `TaskRegistry`.
