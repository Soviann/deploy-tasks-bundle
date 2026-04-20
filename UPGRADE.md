# Upgrade Guide

## Unreleased

### Added: PSR-3 logging

No migration required. The task runner now emits PSR-3 logs through the application logger (with a `deploy_tasks` Monolog channel when `symfony/monolog-bundle` is installed) and falls back to `NullLogger` when no logger is available. To route records through a custom PSR-3 service instead, opt in:

```yaml
deploy_tasks:
    logger: my_custom_psr3_logger_service
```

See `docs/logging.md` for the full record catalog and Monolog routing example.

### Renamed: `RunsProcesses` → `ProcessRunnerTrait` + `runProcess()` now takes a `Process`

The optional helper trait for tasks that shell out to external commands was renamed to match Symfony's trait-naming convention (`*Trait` suffix). At the same time, `runProcess()` stopped proxying `Process::__construct` arguments — callers now build and pass a `Process` themselves, giving access to the full `Process` API (PTY, `fromShellCommandline`, input streams, per-instance options).

| Old | New |
|---|---|
| `Soviann\DeployTasksBundle\RunsProcesses` | `Soviann\DeployTasksBundle\ProcessRunnerTrait` |
| file `src/RunsProcesses.php` | `src/ProcessRunnerTrait.php` |
| `runProcess(array $command, OutputInterface $output, ?string $cwd = null, ?array $env = null, ?string $input = null, ?int $timeout = null)` | `runProcess(Process $process, OutputInterface $output)` |

Migration:

```php
// Before
use Soviann\DeployTasksBundle\RunsProcesses;

use RunsProcesses;

return $this->runProcess(
    ['npm', 'run', 'build'],
    $output,
    cwd: __DIR__.'/../../assets',
    timeout: 120,
);

// After
use Soviann\DeployTasksBundle\ProcessRunnerTrait;
use Symfony\Component\Process\Process;

use ProcessRunnerTrait;

return $this->runProcess(
    new Process(['npm', 'run', 'build'], cwd: __DIR__.'/../../assets', timeout: 120),
    $output,
);
```

### Renamed: Ordering/TaskOrderResolver → Sorting/TaskSorter

The task-sorting extension point was renamed for clarity — the component sorts tasks, it does not resolve anything. Namespace and directory moved in the same stroke so vocabulary is consistent end-to-end.

| Old | New |
|---|---|
| directory `src/Ordering/` | `src/Sorting/` |
| namespace `Soviann\DeployTasksBundle\Ordering` | `Soviann\DeployTasksBundle\Sorting` |
| `TaskOrderResolverInterface` | `TaskSorterInterface` |
| `DefaultTaskOrderResolver` | `DefaultTaskSorter` |
| `OrderedTaskCollection` | `SortedTaskCollection` |
| method `resolve(array): OrderedTaskCollection` | method `sort(array): SortedTaskCollection` |
| config key `deploy_tasks.order_resolver` | config key `deploy_tasks.sorter` |
| service ID `deploy_tasks.order_resolver` | service ID `deploy_tasks.sorter` |

Migration: rename imports, update any class implementing the old interface to implement `TaskSorterInterface` (and rename its `resolve()` method to `sort()`), update the config key in `config/packages/deploy_tasks.yaml`, update autowired type-hints for `TaskSorterInterface` / `SortedTaskCollection`.

## From 0.x to 1.0

No prior version exists. This is the first stable release.
