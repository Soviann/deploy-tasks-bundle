# Upgrade Guide

## Unreleased

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
