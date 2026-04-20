# Logging

The bundle emits PSR-3 logs through `LoggerInterface`. Zero config: the runner auto-detects the application logger, and a dedicated `deploy_tasks` Monolog channel is registered automatically when `symfony/monolog-bundle` is installed. Override with any PSR-3 service.

## Configuration

```yaml
deploy_tasks:
    logger: ~   # service ID of a PSR-3 LoggerInterface, or null to auto-detect
```

| `logger` value | Resulting wiring |
|---|---|
| `null` (default) and app has `@logger` | Runner uses `@logger`. Monolog rewrites it to the `deploy_tasks` channel when monolog-bundle is installed. |
| `null` and app has no `@logger` | Runner uses an internal `NullLogger` — silent, no errors. |
| Service ID (e.g. `app.my_logger`) | Runner uses that service. The Monolog channel tag is ignored for user-supplied loggers. |

## Records Emitted

| Level | Message | Context keys |
|---|---|---|
| `info` | `Deploy tasks run starting` | `environment`, `dry_run`, `force`, `groups` |
| `info` | `Deploy task starting` | `task_id` |
| `info` | `Deploy task executed` | `task_id`, `result`, `duration_ms` |
| `info` | `Deploy task skipped (already executed)` | `task_id` |
| `info` | `Deploy tasks run finished` | `ran`, `skipped`, `failed`, `locked` |
| `warning` | `Deploy task exceeded timeout` | `task_id`, `duration_s`, `timeout_s` |
| `warning` | `Deploy tasks run skipped: another process is already running` | — |
| `warning` | `Deploy tasks runner has no lock factory — concurrent execution is not protected` | — |
| `error` | `Deploy task failed` | `task_id`, `duration_ms`, `exception` |

`task_id` is always a string. `result` is the `TaskResult` enum value. `duration_ms` is an int (rounded), `duration_s` a float. `exception` is the raw `\Throwable` per PSR-3 — Monolog's default formatter renders class, message, and trace.

## Monolog Routing

When `symfony/monolog-bundle` is installed, the runner is tagged with the `deploy_tasks` channel. Route it to a dedicated handler in `config/packages/monolog.yaml`:

```yaml
monolog:
    channels: ['deploy_tasks']
    handlers:
        deploy_tasks:
            type: stream
            path: '%kernel.logs_dir%/deploy_tasks.log'
            level: info
            channels: ['deploy_tasks']
```

## Graceful Degradation

- No `symfony/monolog-bundle`: the channel tag is a benign no-op. Records flow to the application's `@logger` if one exists, otherwise to `NullLogger`.
- No application `@logger` and no override: `NullLogger` — silent, no errors.
- Custom logger via `deploy_tasks.logger`: wins over auto-detection. Channel tag is ignored.
