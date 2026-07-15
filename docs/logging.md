# Logging

The bundle emits PSR-3 logs through `LoggerInterface`. Zero config: the runner auto-detects the application logger, and a dedicated `soviann_deploy_tasks` Monolog channel is registered automatically when `symfony/monolog-bundle` is installed. Override with any PSR-3 service.

## Configuration

```yaml
soviann_deploy_tasks:
    logger: ~   # service ID of a PSR-3 LoggerInterface, or null to auto-detect
```

| `logger` value | Resulting wiring |
|---|---|
| `null` (default) and app has `@logger` | Runner uses `@logger`. Monolog rewrites it to the `soviann_deploy_tasks` channel when monolog-bundle is installed. |
| `null` and app has no `@logger` | Runner uses an internal `NullLogger` — silent, no errors. |
| Service ID (e.g. `app.my_logger`) | Runner uses that service. The Monolog channel tag is ignored for user-supplied loggers. |

## Records Emitted

| Level | Message | Context keys |
|---|---|---|
| `info` | `Deploy tasks run starting` | `environment`, `dry_run`, `rerun_all`, `groups` |
| `info` | `Deploy task starting` | `task_id` |
| `info` | `Deploy task executed` | `task_id`, `result`, `duration_ms` |
| `info` | `Deploy task skipped (already executed)` | `task_id` |
| `info` | `Deploy tasks run finished` | `ran`, `skipped`, `failed`, `locked` |
| `warning` | `Deploy task exceeded timeout` | `task_id`, `duration_s`, `timeout_s` |
| `warning` | `Deploy tasks run skipped: another process is already running` | — |
| `warning` | `Deploy tasks runner has no lock factory — concurrent execution is not protected` | — |
| `error` | `Deploy task failed` | `task_id`, `duration_ms`, plus either `exception` or, when a DBAL exception sits in the failure chain, `exception_class`/`exception_message`/`previous_message` (see below) |
| `error` | `Deploy tasks run failed — transaction rolled back.` | same exception fields as `Deploy task failed` |
| `error` | `Deploy task listener failed` | `event`, `task`, `exception` |

`task_id` is always a string. `result` is an `int` — the backing value of the `TaskResult` enum (logger records `$result->value`). `duration_ms` is an int (rounded), `duration_s` a float. `exception` is the raw `\Throwable` per PSR-3 — Monolog's default formatter renders class, message, and trace. When the failure chain contains a `Doctrine\DBAL\Exception`, the runner drops the raw throwable and substitutes `exception_class`, `exception_message`, and `previous_message` (all strings) instead, to avoid forwarding a DSN-bearing trace — see the credential-safety section below.

## Monolog Routing

When `symfony/monolog-bundle` is installed, the runner is tagged with the `soviann_deploy_tasks` channel. Route it to a dedicated handler in `config/packages/monolog.yaml`:

```yaml
monolog:
    channels: ['soviann_deploy_tasks']
    handlers:
        soviann_deploy_tasks:
            type: stream
            path: '%kernel.logs_dir%/soviann_deploy_tasks.log'
            level: info
            channels: ['soviann_deploy_tasks']
```

## Graceful Degradation

- No `symfony/monolog-bundle`: the channel tag is a benign no-op. Records flow to the application's `@logger` if one exists, otherwise to `NullLogger`.
- No application `@logger` and no override: `NullLogger` — silent, no errors.
- Custom logger via `soviann_deploy_tasks.logger`: wins over auto-detection. Channel tag is ignored.

## Credential-safety when routing the channel

The bundle emits PSR-3 `error` records from the task runner on every task failure.
The context carries the original throwable so handlers can surface stack traces,
but when the failure chain contains a `Doctrine\DBAL\Exception` the runner drops
the full throwable and substitutes string-only fields (`exception_class`,
`exception_message`, `previous_message`). This is a defence-in-depth measure:
DBAL driver exceptions raised during connection or authentication typically embed
the full DSN, including credentials, into their message and stack trace — forwarding
that object to a handler that renders `previous.trace` (the Monolog default) would
export the password into every sink the channel writes to.

Operators routing the `soviann_deploy_tasks` Monolog channel to a shared destination
(central logging, stderr slurpers, chat alerts) should still take care:

- Prefer a handler that renders context as JSON with a normaliser configured to
  limit trace depth (Monolog's `LineFormatter` with `$allowInlineLineBreaks = false`
  or the `JsonFormatter` + `NormalizerFormatter::setMaxNormalizeDepth(1)`) rather
  than rolling dumps that serialise every nested exception verbatim.
- Keep the dedicated `soviann_deploy_tasks` channel routed to a handler you control — don't
  fan it into generic "application error" sinks whose redaction guarantees are not
  under your control.
- Set the application's Doctrine connection DSN via environment variables, not
  inline configuration, so accidental exception dumps in other code paths can't
  capture the password from the container parameters.

See [`docs/security.md`](security.md) for the bundle's broader trust model and hardening notes.
