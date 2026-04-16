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

Single namespace `Soviann\DeployTasksBundle\` mapped to `src/`. Flat layout with role-based folders (`Attribute/`, `Command/`, `DependencyInjection/Compiler/`, `Event/`, `Exception/`) and domain-based folders (`Identifier/`, `Ordering/`, `Runner/`, `Storage/`). Root-level public API: `DeployTaskInterface`, `DeployTasksBundle`, `TaskResult`.

## Pull Requests

- One feature or fix per PR.
- Include tests covering the new behavior.
- Run PHPStan and PHP CS Fixer before submitting.
- Use clear commit messages in `type: description` format (e.g. `feat: adds Redis storage backend`). Types: `feat`, `fix`, `chore`, `refactor`, `docs`, `test`, `ci`. No `(scope)` — the whole repo is one bundle. English, 3rd-person present-tense imperative (`adds`, `fixes`, `removes`).

CI runs automatically on every pull request. All checks (PHPStan, CS Fixer, test matrix) must pass before a PR can be merged.

## Adding a Storage Backend

1. Create a class implementing `Soviann\DeployTasksBundle\Storage\TaskStorageInterface` (or `Soviann\DeployTasksBundle\Storage\TransactionalStorageInterface` for transaction support).
2. Add unit tests in `tests/Unit/Storage/`.
3. Register the service and alias `TaskStorageInterface` (and `deploy_tasks.storage`) to it. See [storage.md](docs/storage.md#custom-storage) for an example.
