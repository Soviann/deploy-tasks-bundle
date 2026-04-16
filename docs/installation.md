# Installation

## Requirements

- PHP 8.2+
- Symfony 6.4 or 7.x

## Composer

```bash
composer require soviann/deploy-tasks-bundle
```

## Bundle registration

With Symfony Flex, the bundle is registered automatically. Otherwise, add it to `config/bundles.php`:

```php
Soviann\DeployTasksBundle\DeployTasksBundle::class => ['all' => true],
```

## Optional packages

| Package | Purpose |
|---|---|
| `doctrine/dbal` | Database storage backend |
| `symfony/event-dispatcher` | Task lifecycle events (`BeforeTaskEvent`, `AfterTaskEvent`, `TaskFailedEvent`) |
| `symfony/lock` | Prevent concurrent execution of `deploytasks:run` |

These packages are detected at runtime. If they are not installed, the corresponding features are silently disabled.

## Configuration

The bundle works with zero configuration. The default storage backend is filesystem, writing JSON files to `var/deploy-tasks/`.

To customise the storage backend or other options, publish a configuration file:

```yaml
# config/packages/deploy_tasks.yaml
deploy_tasks:
    default_timeout: 300          # seconds; 0 = no timeout
    storage:
        type: filesystem           # or: database, custom
        filesystem:
            path: '%kernel.project_dir%/var/deploy-tasks'
    events:
        enabled: true
    lock:
        enabled: true
```

See [storage.md](storage.md) for the full storage configuration reference.
