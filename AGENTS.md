# AGENTS.md

Condensed context for AI sub-agents working on this repository. See `CLAUDE.md` for the full version.

## Project

DeployTasksBundle — Symfony bundle for one-time deploy tasks (data migrations, cache warmups, seed scripts) via CLI. PHP 8.2+, Symfony 6.4+/7.0+.

## Architecture

Single namespace `Soviann\DeployTasksBundle\` mapped to `src/`. Flat layout: role-based folders (`Attribute/`, `Command/`, `DependencyInjection/Compiler/`, `Event/`, `Exception/`) and domain-based folders (`Identifier/`, `Ordering/`, `Runner/`, `Storage/` with `Dbal/`, `Filesystem/`, `InMemory/` sub-namespaces). Root-level public API: `DeployTaskInterface`, `DeployTasksBundle`, `TaskResult`.

## Namespaces

- `Soviann\DeployTasksBundle\` — all bundle classes (under `src/`)
- `Soviann\DeployTasksBundle\Tests\` — tests (under `tests/`)

## Service Registration

- Tasks tagged `deploy_tasks.task` via autoconfiguration on `DeployTaskInterface`
- `#[AsDeployTask(id, priority, env, timeout, transactional, description, groups)]` carries task metadata; `AsDeployTask::of()` is the **single attribute reader**, `AsDeployTask::groupsOf()` returns declared groups. `id` and `description` attributes are the fallback when the interface method returns an empty string
- Autowirable aliases: `TaskStorageInterface`, `TransactionalStorageInterface`, `TaskIdGeneratorInterface`, `TaskOrderResolverInterface`, `TaskRegistry`, `TaskRunner`

## Console Commands

`deploytasks:run`, `:status`, `:skip`, `:reset`, `:rollup`, `:generate:container` (alias `:generate`), `:generate:host`, `:create-schema` (database storage only).

## Coding Standards

- `@Symfony` CS Fixer ruleset, PHPStan level 9
- Backslash-prefix native functions: `\array_map()`, `\sprintf()`, `\count()`
- Yoda conditions: `null === $var`
- All classes `final` unless designed for extension; properties `readonly` where possible
- Method order: `__construct` → public → protected → private

## Commands

```
vendor/bin/phpunit                    # all tests
vendor/bin/phpstan analyse            # static analysis (level 9)
vendor/bin/php-cs-fixer fix           # fix code style
vendor/bin/php-cs-fixer fix --dry-run # check code style
```

## Git

English, `<type>: description` — no scope. 3rd-person present-tense imperative. No `Co-authored-by` trailer.
