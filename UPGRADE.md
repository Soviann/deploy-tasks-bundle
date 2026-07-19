# Upgrade notes

Migration notes for breaking changes, one section per release that ships any
(pre-1.0, a breaking change bumps the minor version).

Everything user-visible, breaking or not, is tracked in `CHANGELOG.md`.

## Upgrade to 0.3.0

### What broke

The container-scope generator command was renamed from
`deploytasks:generate:container` to `deploytasks:generate`. The old name no
longer exists; calling it fails with a "command not found" error.

The rename aligns the generator with the rest of the command set: a bare
`deploytasks:<verb>` targets the container scope (as `run`, `reset`, `rollup`,
and `skip` already do), while the `deploytasks:host:<verb>` prefix targets the
host scope. `generate:container` was the only command that spelled the
container scope out and placed it after the verb.

### Before / after

```diff
-bin/console deploytasks:generate:container
+bin/console deploytasks:generate

-bin/console deploytasks:generate:container --dir=src/Task/
+bin/console deploytasks:generate --dir=src/Task/
```

### Migration

Replace every `deploytasks:generate:container` invocation with
`deploytasks:generate` in deploy scripts, Makefiles, CI pipelines, and
documentation. Nothing else changed — the options (`--dir`, `--namespace`),
the generated file, and its output directory are all identical.
