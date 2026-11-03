# Changelog

All notable changes to this package are documented here.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and versions follow [Semantic Versioning](https://semver.org/).

## [0.1.0] - 2026-11-03

First release.

### Added

- Runtime: `InputDataInterface` / `OutputDataInterface`, base `InputData` / `OutputData` DTOs on top of spatie/laravel-data, `EloquentRepository` that returns output DTOs only.
- `make:layers` command generating, per model: input DTOs per action, output DTO, repository, service, controller, policy (gate checked in the input DTO's `authorize()`), middleware, routes, model factory and feature tests.
- Schema introspection from the database table: types, nullability, defaults, foreign keys (`Exists`), single-column unique indexes (`Unique`, ignoring the current record on update), DB enums (`In`), lengths (`Max`), formats; model casts, `$hidden`, `$fillable` / `$guarded`, soft deletes.
- Model relations in the output DTO, returned only when loaded; `$with` in repositories.
- camelCase or snake_case DTO properties (`properties` config), read-only columns, configurable namespaces, base classes and publishable stubs.
- Laravel 11, 12 and 13; PHP 8.2+.
