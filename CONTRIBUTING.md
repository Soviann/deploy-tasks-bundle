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

- `@Symfony` CS Fixer ruleset (includes `@Symfony:risky`)
- PHPStan level 9 — no suppressions
- All classes must be `final` unless designed for extension; properties `readonly` where possible
- Backslash-prefix native PHP functions: `\array_map()`, `\sprintf()`, `\count()`
- Yoda conditions: `null === $var`, not `$var === null`
- Method order: `__construct` → public → protected → private (`setUp` / `tearDown` first in tests)

`vendor/bin/php-cs-fixer fix` auto-fixes most of the above; run it before opening a PR.

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

## Releasing

1. Decide the next version (semver: MAJOR for breaking changes, MINOR for new features, PATCH for fixes).
2. In `CHANGELOG.md`, move the `## [Unreleased]` bullets into a new `## [X.Y.Z] - YYYY-MM-DD` section above it. Add a fresh empty `## [Unreleased]` at the top.
3. Update the compare links at the bottom of `CHANGELOG.md`:
   - `[Unreleased]: .../compare/vX.Y.Z...HEAD`
   - `[X.Y.Z]: .../compare/vPREV...vX.Y.Z` (or `.../releases/tag/vX.Y.Z` for the first release)
4. Commit: `docs: prepares X.Y.Z release notes`.
5. Tag from `main`: `git tag -a vX.Y.Z -m "vX.Y.Z"` then `git push origin vX.Y.Z`.
6. The `release` workflow creates a draft GitHub Release from the tag's changelog section — review it on GitHub, then publish.
7. Verify Packagist picks up the new version within 5 minutes. If not, force-update the package from its Packagist settings page.

Only stable tags (`vX.Y.Z`, no suffix) trigger the release workflow. Pre-release tags (`vX.Y.Z-rc1`, `-alpha`, `-beta`) still reach Packagist via its own webhook but do not create a GitHub Release automatically — use the workflow's `workflow_dispatch` input if you need release notes for a pre-release.
