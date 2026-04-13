# AGENTS.md

Condensed context for AI sub-agents working on specific tasks in this repository.

## Project

DeployTasksBundle — Symfony bundle for one-time deploy tasks (data migrations, cache warmups, seed scripts). PHP 8.2+, Symfony 6.4+/7.0+.

## Architecture

Contract (`src/Contract/`) → Component (`src/`) → Bundle (`src/Bundle/`). Dependencies flow inward only.

- **Contract**: pure PHP interfaces and value objects. No Symfony imports except `OutputInterface`.
- **Component**: `TaskRegistry`, `TaskRunner`, `DefaultTaskOrderResolver`, storage backends (`Filesystem`, `Dbal`, `InMemory`), events.
- **Bundle**: `DeployTasksBundle` (extends `AbstractBundle`), compiler pass, 5 console commands.

## Namespaces

- `Soviann\DeployTasks\Contract\` — contracts
- `Soviann\DeployTasks\` — component
- `Soviann\DeployTasksBundle\` — bundle (under `src/Bundle/`)
- `Soviann\DeployTasks\Tests\` — tests

## Coding Standards

- `@Symfony` CS Fixer ruleset, PHPStan level 9
- Backslash-prefix native functions: `\array_map()`, `\sprintf()`
- Yoda conditions: `null === $var`
- All classes `final`, all properties `readonly` where possible
- Method order: `__construct` → public → protected → private

## Commands

```
vendor/bin/phpunit                    # all tests
vendor/bin/phpstan analyse            # static analysis
vendor/bin/php-cs-fixer fix           # fix code style
vendor/bin/php-cs-fixer fix --dry-run # check code style
```

## Git

English, `type(scope): description` format. No `Co-authored-by` trailers.
