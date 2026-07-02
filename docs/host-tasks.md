# Host-scope Tasks

Host tasks run outside the Symfony container — useful for operations that must execute on the host (Docker restarts, SSH-driven commands, infrastructure prep). They live as shell files under `deploy/host-tasks/`.

## Install the runner

Until a Flex recipe ships, install the runner manually:

    cp vendor/soviann/deploy-tasks-bundle/bin/deploy-tasks-host.sh.dist bin/deploy-tasks-host.sh
    chmod +x bin/deploy-tasks-host.sh
    mkdir -p deploy/host-tasks

Add to `.gitignore`:

    .deploy-tasks-host.log
    .deploy-tasks-host.lock
    deploy-tasks-host.local.sh

## Create a host task

    bin/console deploytasks:generate:host

Creates `deploy/host-tasks/deploy_task_20260418_143022.sh`. Edit the file to implement the task.

## Run pending host tasks

    bash bin/deploy-tasks-host.sh           # defaults to APP_ENV=dev
    bash bin/deploy-tasks-host.sh prod      # loads .env.prod + .env.prod.local
    bash bin/deploy-tasks-host.sh prod --dry-run

## Storage & idempotency

Host tasks use a separate append-only log (`.deploy-tasks-host.log`, one-shot per machine). `APP_ENV` determines which `.env.*` files are loaded for task execution; it does not scope storage.

## `.env` loading

The runner loads env files in Symfony cascade order (lowest to highest priority):
1. `.env`
2. `.env.local`
3. `.env.$APP_ENV`
4. `.env.$APP_ENV.local`
5. `deploy-tasks-host.local.sh` (bash source, for overrides the `.env` parser can't express)

As with Symfony's Dotenv, real environment variables always take precedence: a variable already set in the process environment before the runner starts (e.g. CI-injected `DATABASE_URL`) is never overwritten by any `.env` file. The resolved `APP_ENV` (CLI argument, else pre-set `APP_ENV`, else `dev`) is likewise authoritative — an `APP_ENV=` line in a `.env` file cannot change which environment the tasks run in.

Values are taken literally — no variable expansion, no inline comments, no multiline values; `deploy-tasks-host.local.sh` is the escape hatch for anything the parser can't express.

Values in host task scripts reference exported env vars (`$NAS_HOST`, etc.).

## Concurrency

A `flock` lock at `.deploy-tasks-host.lock` prevents concurrent runs on the same machine. When the lock is already held, the runner exits with code `75` (`EX_TEMPFAIL`) — the same "temporary failure, retry later" convention as `deploytasks:run`.

## Environment variables

The runner honours three environment variables for path overrides (useful for CI, shared-machine deployments, or keeping state outside the repo root). Each one has a sensible default; you rarely need to set them explicitly.

| Variable | Default | Purpose |
|---|---|---|
| `DEPLOY_TASKS_HOST_DIR` | `deploy/host-tasks` | Directory scanned for `*.sh` task scripts. |
| `DEPLOY_TASKS_HOST_STORAGE` | `.deploy-tasks-host.log` | Append-only log of completed task IDs (one-shot per machine). |
| `DEPLOY_TASKS_HOST_LOCK` | `.deploy-tasks-host.lock` | `flock` file guarding against concurrent runs. |

Paths are resolved relative to the runner's current working directory (the repo root by convention). Set them via shell environment, CI secrets, or the `deploy-tasks-host.local.sh` override file.
