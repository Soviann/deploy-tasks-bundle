# Security Considerations

## Trust Model

Deploy tasks run with the same privileges as the Symfony application. They can access the database, filesystem, and any service registered in the container.

## Access Control

Only grant `deploytasks:run` access to trusted operators (deploy scripts, CI/CD pipelines). Do not expose console commands to untrusted users.

## Filesystem Storage

The default path (`var/deploy-tasks/`) must not be web-accessible. The bundle emits `E_USER_WARNING` if the configured path contains `/public/`. Add the storage directory to `.gitignore`.

## Database Storage

Execution records are stored in a dedicated table. Ensure the table is only accessible to the application's database user, following the principle of least privilege.

## CI/CD Recommendations

- Run `deploytasks:run` as part of the deploy pipeline, after migrations.
- Use `--dry-run` in staging to preview which tasks will run before applying to production.
- Use `symfony/lock` to prevent concurrent execution in multi-instance deployments.
- Monitor task failures via the event system or `deploytasks:status`.

## Task Code

Tasks have full access to all injected services. Review task code carefully, especially tasks contributed by team members. Tasks should be idempotent when possible — if interrupted and re-run, they should produce the same result without side effects.
