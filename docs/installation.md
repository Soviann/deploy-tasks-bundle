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
    default_timeout: 300          # seconds (>= 0). 0 means every task exceeds the timeout and a warning is logged.
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

## Host-scope tasks

Host-scope tasks run as plain bash scripts outside the container; they share storage with container-scope tasks but are invoked through `bin/deploy-tasks-host.sh` rather than `bin/console deploytasks:run`. One-time setup:

```bash
cp vendor/soviann/deploy-tasks-bundle/bin/deploy-tasks-host.sh.dist bin/deploy-tasks-host.sh
chmod +x bin/deploy-tasks-host.sh
mkdir -p deploy/host-tasks
```

Add the following entries to `.gitignore`:

```
/.deploy-tasks-host.log
/.deploy-tasks-host.lock
/bin/deploy-tasks-host.local.sh
```

See [README → Host-scope tasks](../README.md#host-scope-tasks) for generation, execution, and rollout details.
