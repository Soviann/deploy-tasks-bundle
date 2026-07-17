# Host-scope Tasks

Host tasks run outside the Symfony container — useful for operations that must execute on the host (Docker restarts, SSH-driven commands, infrastructure prep). They live as shell files under `deploy/host-tasks/`.

## Which scope do I need?

| Your task… | Scope |
|---|---|
| Touches the app: DB migrations/data, cache, framework services | **Container** (`#[AsDeployTask]` PHP class) |
| Shells out but can run inside the app container (asset build, CLI tool) | **Container** + `ProcessRunnerTrait` |
| Needs the host: Docker/systemd restarts, mounts, packages, root | **Host** (`deploy/host-tasks/*.sh`) |
| Must run even when the app cannot boot (broken kernel, pre-install) | **Host** |

Default to container tasks: they get storage backends, groups, env filtering, and lifecycle events. Host tasks get none of that by design (see Non-goals) — though they do get read-only status visibility and log-management commands (`host:skip`/`host:reset`/`host:rollup`), described below.

## Install the runner

The Flex recipe installs the runner automatically (see [`installation.md`](installation.md#flex-recipe)): it copies `bin/deploy-tasks-host.sh`, publishes the config file, and adds the `.gitignore` entries below.

Without Flex, or if the recipe endpoint isn't enabled, install manually:

    cp vendor/soviann/deploy-tasks-bundle/bin/deploy-tasks-host.sh.dist bin/deploy-tasks-host.sh
    chmod +x bin/deploy-tasks-host.sh
    mkdir -p deploy/host-tasks

Add to `.gitignore`:

    .deploy-tasks-host.log
    .deploy-tasks-host.lock
    deploy-tasks-host.local.sh

## Create a host task

    bin/console deploytasks:host:generate

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

Paths are resolved relative to the runner's own project root — the script `cd`s to `bin/..` before resolving any path (`bin/deploy-tasks-host.sh.dist` lines 6-7), so this is guaranteed regardless of the working directory the runner is invoked from, not merely a convention. Set the overrides via shell environment, CI secrets, or the `deploy-tasks-host.local.sh` override file.

## Keeping the runner and the PHP config in sync

`soviann_deploy_tasks.host.*` and the runner's `DEPLOY_TASKS_HOST_*` env vars must
point at the same files. Instead of syncing them by hand, generate the runner side
from the bundle config:

    bin/console deploytasks:host:config --write

This writes `deploy-tasks-host.local.sh` at the project root — sourced by
`bin/deploy-tasks-host.sh` on every run — with project-relative paths, so it stays
correct even when the PHP container mounts the project at a different absolute path
than the host. Re-run it after changing any `host.*` value; `deploytasks:status`
warns whenever the generated file drifts from the current config.

## Status visibility

`deploytasks:status` appends a "Host tasks" section listing each `deploy/host-tasks/*.sh` script as `done` (its ID is a full line in `.deploy-tasks-host.log`) or `pending`. This is a read-only bridge: PHP only reads the host directory and the log, it never writes to them, and the bash runner above is unaffected.

**Limitation:** the `DEPLOY_TASKS_HOST_DIR` and `DEPLOY_TASKS_HOST_STORAGE` env var overrides described above are read by the bash runner at execution time — they are **not** visible to the PHP side. `deploytasks:status` always reads from the `host.directory` and `host.log_path` bundle config (defaults `deploy/host-tasks` and `.deploy-tasks-host.log` under the project dir), and the same applies to `deploytasks:host:skip`, `deploytasks:host:reset`, and `deploytasks:host:rollup`. If you run the host runner with either variable overridden, `deploytasks:status` will show stale or empty state, and the three ops commands will **write to a file the runner never reads** — until `host.*` is updated to match the runner's paths.

## Managing host task state

`deploytasks:host:skip`, `deploytasks:host:reset` and `deploytasks:host:rollup` give host tasks the same ops tooling as container tasks — [`deploytasks:skip`](commands.md#deploytasksskip), [`deploytasks:reset`](commands.md#deploytasksreset), [`deploytasks:rollup`](commands.md#deploytasksrollup) — while the execution plane (the bash runner) stays untouched. All three operate on the completion log only (`host.log_path` config, default `.deploy-tasks-host.log`), using the exact-line semantics described in [the host contract](#the-host-contract-pinned-by-tests) below (`grep -Fxq`):

```bash
bin/console deploytasks:host:skip deploy_task_20260418_143022
bin/console deploytasks:host:reset deploy_task_20260418_143022 --no-interaction --force
bin/console deploytasks:host:rollup --no-interaction --force
```

- **`host:skip <id>`** appends the id to the log, marking the task done without running its script. The id must have a matching `<id>.sh` in the host directory. Already-done ids are a no-op (`SUCCESS`, no duplicate line). Prompts for confirmation like `deploytasks:skip` — reversible via `host:reset`, so it proceeds under `--no-interaction` without `--force`.
- **`host:reset <id>`** removes every exact-match line for the id, so the task runs again on the next `bin/deploy-tasks-host.sh`. An id with no log entry is reported as already pending (`SUCCESS`, no-op). Destructive: requires confirmation or `--force` under `--no-interaction`, mirroring `deploytasks:reset`. The rewrite is atomic (temp file + rename), matching `FilesystemStorage`'s write discipline.
- **`host:rollup`** appends every pending script id in one pass and reports the count. An empty host directory (or one where every script is already done) warns and exits successfully without prompting. Destructive: same confirmation/`--force` convention as `deploytasks:rollup` — a bulk-operation guard: each individual append is reversible via `host:reset`, but appending everything in one pass deserves a stop.

**Concurrency:** the ops commands take the runner's own `flock` (`host.lock_path` config, default `.deploy-tasks-host.lock`) around every log mutation, so they can never interleave with a live `bin/deploy-tasks-host.sh` run — a `host:reset` rewrite racing the runner's own append could otherwise drop a just-completed record. While a host run holds the lock, the commands leave the log untouched and exit with code `75` (`EX_TEMPFAIL`), the same "retry later" convention as the runner itself; retry once the run finishes.

All three refuse with `Command::INVALID` and a pointer back to this document when the host tasks directory doesn't exist. See [`docs/commands.md`](commands.md) for full options and exit codes.

## Non-goals — the host runner stays small

The host runner is intentionally a flat, ordered, once-per-machine script runner
(~100 lines of bash). The following are **non-goals** and will not be added:

- groups / stages, env-based task filtering, priorities
- lifecycle events, per-task timeouts or slow-task thresholds
- storage backends beyond the append-only log
- richer `.env` parsing (expansion, inline comments, multiline) — use
  `deploy-tasks-host.local.sh` for anything the parser can't express

If a deploy needs more than this on the host side, prefer a container task
shelling out via `ProcessRunnerTrait`, or real configuration management
(Ansible & co).

Splitting the host runner into its own package was evaluated and **decided
against** (2026-07-02, pre-release): the coupling between the halves is a
small set of conventions (log format, directory layout, id = script
basename), and a split would turn those into a cross-package contract with
no good home for the bridging commands, doubling release overhead for no
identified standalone audience. The bundle stays single — but structured
for extraction-readiness: the contract below is explicit and pinned by
tests, and host assets live under bounded paths, so the decision can be
revisited cheaply if the host side ever grows a genuine second feature
axis AND a standalone audience.

## The host contract (pinned by tests)

- Task id = script basename without `.sh`; scripts live in
  `deploy/host-tasks/` (`DEPLOY_TASKS_HOST_DIR` override).
- Completion log = one id per line, exact-line match
  (`.deploy-tasks-host.log`, `DEPLOY_TASKS_HOST_STORAGE` override);
  append-only from the runner's side.
- Env cascade: `.env` → `.env.local` → `.env.$APP_ENV` →
  `.env.$APP_ENV.local` → `deploy-tasks-host.local.sh`; real environment
  always wins. Parser subset per the contract tests.
