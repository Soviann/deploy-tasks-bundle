# Security Considerations

## Trust Model

Deploy tasks run with the same privileges as the Symfony application. They can access the database, filesystem, and any service registered in the container.

## Access Control

Only grant `deploytasks:run` access to trusted operators (deploy scripts, CI/CD pipelines). Do not expose console commands to untrusted users.

## Filesystem Storage

The default path (`var/deploy-tasks/`) must not be web-accessible. `FilesystemStorage` **throws** a `StorageException` when the storage path contains a path segment named like a public web root — `pub`, `public`, `public_html`, `web`, `html`, `htdocs`, `wwwroot`, or `httpdocs` (case-insensitive).

The check is scoped to the project directory: the bundle passes `kernel.project_dir` to the storage, and only the storage path portion **at or below** the project directory is inspected. Segments above it name the server's deploy layout, not the app's docroot, so a project deployed under `/var/www/html/` is not falsely refused. When the storage path lies outside the project directory (or none is known, e.g. direct construction), every segment of the path is checked conservatively.

Both the storage path and the project directory are canonicalized with symlinks resolved (down to the deepest existing ancestor — the storage directory itself may not exist yet), so a storage path that only reaches the docroot through a symlink is refused too.

**Limitations.** If the project directory itself is the served docroot (e.g. shared hosting with the app rooted at `/home/user/public_html`), the guard cannot detect it — every path inside the project then looks like a plain project subdirectory. Rely on the file permissions below and on web-server configuration to deny serving the storage directory. Symlinks are also resolved at construction time only; a symlink created afterwards is out of the guard's reach.

This guard is a defense-in-depth layer, not the primary protection: the state directory is created with mode `0700` and each record file is written `0600`, re-applied on every save. Add the storage directory to `.gitignore`.

## Database Storage

Execution records are stored in a dedicated table. Ensure the table is only accessible to the application's database user, following the principle of least privilege.

## Generated File Permissions

Generated task files are not world-readable, since deploy tasks often embed credentials, fixtures, or production data:

- `deploytasks:generate:container` writes `.php` task classes with mode `0640` (owner read/write, group read).
- `deploytasks:generate:host` writes `.sh` task stubs with mode `0750` (owner read/write/execute, group read/execute).

`FilesystemStorage` likewise persists its state directory at `0700` and each per-slot JSON file at `0600`, re-applying these modes on every write so pre-existing files tighten on the next save.

## CI/CD Recommendations

- Run `deploytasks:run` as part of the deploy pipeline, after migrations.
- Use `--dry-run` in staging to preview which tasks will run before applying to production.
- Use `symfony/lock` to prevent concurrent execution in multi-instance deployments.
- Monitor task failures via the event system or `deploytasks:status`.

## Task Code

Tasks have full access to all injected services. Review task code carefully, especially tasks contributed by team members. Tasks should be idempotent when possible — if interrupted and re-run, they should produce the same result without side effects.

## Host-scope tasks

Host-scope tasks are **not** container-sandboxed. They execute inside the operator's host shell (the one that runs `bin/deploy-tasks-host.sh`) with its full environment, path, umask, and filesystem rights. Treat them as shell code you authored yourself:

- `deploy-tasks-host.local.sh` (project root, sibling of `bin/deploy-tasks-host.sh` — **not** under `bin/`) is sourced by bash at execution time; any secret exported there lands in the shell's process environment for the rest of the run. `deploy/host-tasks/deploy_task_*.sh` scripts are **executed** (`bash task.sh`), not sourced, but each one inherits that same process environment as a child process. `.deploy-tasks-host.log` only ever records completed task IDs, one per line — it never receives environment values. Never commit secrets or production credentials in these files — source them from an out-of-repo location instead.
- Both `.deploy-tasks-host.log` and `.deploy-tasks-host.lock` can leak task metadata and timing. Git-ignore them and make sure they are not served from a web-exposed directory.
- Only operators who are already trusted to run `bin/deploy-tasks-host.sh` (deploy user, CI runner) should be able to write to `deploy/host-tasks/` — any attacker who can drop a script there gets arbitrary shell execution on the host.
