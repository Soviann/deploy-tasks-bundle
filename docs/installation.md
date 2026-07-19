# Installation

## Requirements

- PHP 8.2+ (8.4+ for Symfony 8)
- Symfony 6.4 LTS, 7.x or 8.x

## Install with Symfony Flex

### 1. Enable the Flex recipe endpoint

The bundle's Flex recipe is served from a dedicated endpoint until it lands in `symfony/recipes-contrib`. Enable it **before** requiring the bundle, so Flex applies the recipe on install:

```bash
composer config extra.symfony.endpoint --json '["https://api.github.com/repos/Soviann/flex-recipes/contents/index.json", "flex://defaults"]'
```

### 2. Require the bundle

```bash
composer require soviann/deploy-tasks-bundle
```

With the endpoint enabled, Flex applies the recipe automatically: it registers the bundle in `config/bundles.php`, publishes `config/packages/soviann_deploy_tasks.yaml`, creates `deploy/host-tasks/` (with a `.gitkeep`), installs the host runner `bin/deploy-tasks-host.sh`, and adds its `.gitignore` entries.

## Install without Flex (or without the endpoint)

Register the bundle manually in `config/bundles.php`:

```php
Soviann\DeployTasksBundle\SoviannDeployTasksBundle::class => ['all' => true],
```

Then install the host runner and its supporting files with `bin/console deploytasks:host:install` — see [`docs/host-tasks.md`](host-tasks.md).

## Optional packages

| Package | Purpose |
|---|---|
| `doctrine/dbal` | Database storage backend |
| `symfony/event-dispatcher` | Task lifecycle events (`BeforeTaskEvent`, `AfterTaskEvent`, `TaskFailedEvent`) |
| `symfony/lock` | Prevent concurrent execution of `deploytasks:run` |

These packages are detected at runtime. If they are not installed, the corresponding features are disabled — silently for events and storage, but not for locking: `deploytasks:run` unconditionally prints a console warning when `lock.enabled` is `true` and symfony/lock is missing, since that means concurrent-run protection is off. Separately, whenever no lock factory ends up wired at all (package missing, or `lock.enabled: false`), the runner itself also logs a PSR-3 `warning` on every run (`Deploy tasks runner has no lock factory — concurrent execution is not protected`) and, only under `-v`/`--verbose`, prints its own additional console line to the same effect.

## Configuration

The bundle works with zero configuration. The default storage backend is filesystem, writing JSON files to `var/deploy-tasks/`.

To customise the storage backend or other options, publish a configuration file:

```yaml
# config/packages/soviann_deploy_tasks.yaml
soviann_deploy_tasks:
    slow_task_threshold: 300      # seconds (>= 0); warn when a task runs longer — nothing is killed. 0 disables the check.
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
    host:
        directory: '%kernel.project_dir%/deploy/host-tasks'        # host-scope *.sh task directory — must match DEPLOY_TASKS_HOST_DIR
        log_path: '%kernel.project_dir%/.deploy-tasks-host.log'    # host runner completion log — must match DEPLOY_TASKS_HOST_STORAGE
        lock_path: '%kernel.project_dir%/.deploy-tasks-host.lock'  # host runner flock file — must match DEPLOY_TASKS_HOST_LOCK
```

See [storage.md](storage.md) for the full storage configuration reference.

> **Warning — ephemeral filesystems.** On Docker, Kubernetes, and similar container platforms, the default filesystem storage path under `%kernel.project_dir%` sits on an overlay filesystem that resets on pod restart or image rebuild. Use a dedicated bind mount or `PersistentVolumeClaim` mapped at `%kernel.project_dir%/var/deploy-tasks/`, or switch to the database backend, so execution records survive restarts.

## Host-scope tasks

Host-scope tasks run as plain bash scripts outside the container, invoked through `bin/deploy-tasks-host.sh` rather than `bin/console deploytasks:run`. The host runner is installed automatically by the Flex recipe (see above); see [`docs/host-tasks.md`](host-tasks.md) for the manual-install fallback, task generation, execution, and the `.env` cascade.
