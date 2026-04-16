# Contributing

## Requirements

- PHP 8.2+
- Composer

## Setup

```bash
git clone https://github.com/soviann/deploy-tasks-bundle.git
cd deploy-tasks-bundle
composer install
```

## Running Tests

```bash
# All tests
vendor/bin/phpunit

# Unit tests only
vendor/bin/phpunit --testsuite Unit

# Functional tests only
vendor/bin/phpunit --testsuite Functional
```

## Code Quality

```bash
# Static analysis (level 9)
vendor/bin/phpstan analyse

# Fix code style
vendor/bin/php-cs-fixer fix

# Check code style without modifying files
vendor/bin/php-cs-fixer fix --dry-run
```

## Coding Standards

- `@Symfony` CS Fixer ruleset
- PHPStan level 9 — no suppressions
- All classes must be `final` unless designed for extension
- Backslash-prefix native PHP functions: `\array_map()`, `\sprintf()`, `\count()`
- Yoda conditions: `null === $var`, not `$var === null`

## Architecture

The bundle is split into three layers with a strict dependency direction:

```
Contract (pure PHP interfaces and value objects)
    -> Component (storage, registry, runner — no Symfony DI)
        -> Bundle (Symfony DI, configuration, console commands)
```

The `src/Contract/` layer must not import any Symfony class, with the sole exception of `OutputInterface`.

## Pull Requests

- One feature or fix per PR.
- Include tests covering the new behavior.
- Run PHPStan and PHP CS Fixer before submitting.
- Use clear commit messages in `type: description` format (e.g. `feat: adds Redis storage backend`). Types: `feat`, `fix`, `chore`, `refactor`, `docs`, `test`, `ci`. No `(scope)` — the whole repo is one bundle. English, 3rd-person present-tense imperative (`adds`, `fixes`, `removes`).

CI runs automatically on every pull request. All checks (PHPStan, CS Fixer, test matrix) must pass before a PR can be merged.

## Adding a Storage Backend

1. Create a class implementing `Soviann\DeployTasks\Contract\TaskStorageInterface` (or `Soviann\DeployTasks\Contract\TransactionalStorageInterface` for transaction support).
2. Add unit tests in `tests/Unit/Storage/`.
3. Register the service and alias `TaskStorageInterface` (and `deploy_tasks.storage`) to it. See [storage.md](docs/storage.md#custom-storage) for an example.
