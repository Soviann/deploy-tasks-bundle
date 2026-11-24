# Upgrade notes

Migration notes for breaking changes, one section per release that ships any
(pre-1.0, a breaking change bumps the minor version).

Everything user-visible, breaking or not, is tracked in `CHANGELOG.md`.

## Upgrade to 0.7.0

### What broke

`SoviannDeployTasksBundle` extends `Symfony\Component\HttpKernel\Bundle\Bundle` instead of `AbstractBundle` (absent from Symfony 5.4, now supported). Its public `configure()` and `loadExtension()` methods were removed: the configuration tree moved to `Soviann\DeployTasksBundle\DependencyInjection\Configuration` and the service wiring to `Soviann\DeployTasksBundle\DependencyInjection\SoviannDeployTasksExtension`, both internal.

Applications that only register the bundle in `config/bundles.php` and configure `soviann_deploy_tasks` are not affected: the configuration keys, defaults, validation, service IDs and autowiring aliases are identical.

### Before / after

```diff
-(new SoviannDeployTasksBundle())->configure($definitionConfigurator);
+$extension = (new SoviannDeployTasksBundle())->getContainerExtension();
+\assert($extension instanceof \Symfony\Component\DependencyInjection\Extension\ConfigurationExtensionInterface);
+$configuration = $extension->getConfiguration([], $containerBuilder);
+$processed = (new \Symfony\Component\Config\Definition\Processor())->processConfiguration($configuration, $configs);
```

### Migration

Code that called `SoviannDeployTasksBundle::configure()` / `loadExtension()` directly, or checked `instanceof AbstractBundle`, goes through the bundle's container extension instead (`getContainerExtension()`, then `getConfiguration()` or `load()`), as with any classic bundle.

## Upgrade to 0.5.0

### What broke

`ProcessRunnerTrait::runProcessWithTimeout()` was removed. `runProcess()` now accepts an optional `$timeout` parameter directly (`runProcess($process, $output, timeout: $seconds)`).

### Before / after

```diff
-$this->runProcessWithTimeout($process, 30, $output);
+$this->runProcess($process, $output, timeout: 30);
```

### Migration

Replace calls to `runProcessWithTimeout($process, $seconds, $output)` with `$this->runProcess($process, $output, timeout: $seconds)`.

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
