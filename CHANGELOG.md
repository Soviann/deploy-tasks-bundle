# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Contract layer: `DeployTaskInterface`, `TaskStorageInterface`, `TaskOrderResolverInterface`, value objects (`TaskExecution`, `TaskStatus`, `TaskResult`), and `#[AsDeployTask]` attribute
- Storage backends: `FilesystemStorage` (default, JSON files), `DbalStorage` (Doctrine DBAL), `InMemoryStorage` (testing)
- Task registry with duplicate ID detection and environment filtering
- Task runner with ordered execution, dry-run mode, optional event dispatching, lock support, timeout tracking, and transaction wrapping
- Event system: `BeforeTaskEvent`, `AfterTaskEvent`, `TaskFailedEvent`
- Default priority-based task order resolver with date extraction from task IDs
- Console commands: `deploytasks:run`, `deploytasks:status`, `deploytasks:skip`, `deploytasks:reset`, `deploytasks:generate`, `deploytasks:rollup`, `deploytasks:create-schema`
- Symfony bundle with full configuration tree, compiler pass for service validation, and autoconfiguration
- Support for PHP 8.2+ and Symfony 6.4+/7.0+

[Unreleased]: https://github.com/soviann/deploy-tasks-bundle/compare/HEAD
