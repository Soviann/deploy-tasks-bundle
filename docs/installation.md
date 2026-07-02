# Installation

## Requirements

- PHP 8.2+
- Symfony 6.4 LTS or 7.x

## Composer

```bash
composer require soviann/deploy-tasks-bundle
```

## Bundle registration

With Symfony Flex, the bundle is registered automatically. Otherwise, add it to `config/bundles.php`:

```php
Soviann\DeployTasksBundle\SoviannDeployTasksBundle::class => ['all' => true],
```

## Optional packages

| Package | Purpose |
|---|---|
| `doctrine/dbal` | Database storage backend |
| `symfony/event-dispatcher` | Task lifecycle events (`BeforeTaskEvent`, `AfterTaskEvent`, `TaskFailedEvent`) |
| `symfony/lock` | Prevent concurrent execution of `deploytasks:run` |

These packages are detected at runtime. If they are not installed, the corresponding features are disabled — silently for events and storage, but `deploytasks:run` prints a warning when `lock.enabled` is `true` and symfony/lock is missing, since that means concurrent-run protection is off.

## Configuration

The bundle works with zero configuration. The default storage backend is filesystem, writing JSON files to `var/deploy-tasks/`.

To customise the storage backend or other options, publish a configuration file:

```yaml
# config/packages/soviann_deploy_tasks.yaml
soviann_deploy_tasks:
    default_timeout: 300          # seconds (>= 0). 0 disables the timeout check entirely (no warning emitted).
    storage:
        type: filesystem           # or: database, custom
        filesystem:
            path: '%kernel.project_dir%/var/deploy-tasks'
    events:
        enabled: true
    lock:
        enabled: true
        ttl: 3600             # lock lifetime in seconds; the runner refreshes it between tasks
    generate:
        directory: src/DeployTasks/Task/
        template: ~           # path to a custom PHP template
        root_namespace: App   # root namespace for src/-rooted --dir (mirrors symfony/maker-bundle)
        host_directory: '%kernel.project_dir%/deploy/host-tasks'   # where deploytasks:generate:host writes stubs
```

See [storage.md](storage.md) for the full storage configuration reference.

> **Warning — ephemeral filesystems.** On Docker, Kubernetes, and similar container platforms, the default filesystem storage path under `%kernel.project_dir%` sits on an overlay filesystem that resets on pod restart or image rebuild. Use a dedicated bind mount or `PersistentVolumeClaim` mapped at `%kernel.project_dir%/var/deploy-tasks/`, or switch to the database backend, so execution records survive restarts.

## Host-scope tasks

Host-scope tasks run as plain bash scripts outside the container, invoked through `bin/deploy-tasks-host.sh` rather than `bin/console deploytasks:run`. See [`docs/host-tasks.md`](host-tasks.md) for installation, task generation, execution, and the `.env` cascade.
