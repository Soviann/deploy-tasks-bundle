# DeployTasksBundle Documentation

One-time deploy tasks for Symfony — tracked, ordered, transactional.

| Guide | Contents |
|---|---|
| [Installation](installation.md) | Requirements, Composer install, Flex or manual registration, configuration |
| [Creating tasks](creating-tasks.md) | Task classes, `#[AsDeployTask]`, IDs, priorities, environments, groups, host-scope tasks |
| [Commands](commands.md) | Every `deploytasks:*` command, options, and exit codes |
| [Storage backends](storage.md) | Filesystem, database (DBAL), in-memory, and custom backends |
| [Host-scope tasks](host-tasks.md) | Host runner install, task generation, `.env` cascade, concurrency |
| [Events](events.md) | `BeforeTaskEvent`, `AfterTaskEvent`, `TaskFailedEvent` |
| [Logging](logging.md) | PSR-3 wiring, Monolog channel, message reference |
| [Security](security.md) | Trust model, permissions, secrets handling, hardening notes |
| [Advanced](advanced.md) | Custom sorters and ID generators, locks, the slow-task threshold, transactions |
| [Testing](testing.md) | Unit and functional testing of tasks and storage |
| [Troubleshooting](troubleshooting.md) | Symptom-first FAQ |

Start with [Installation](installation.md), then [Creating tasks](creating-tasks.md).
