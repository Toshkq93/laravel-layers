# Changelog

All notable changes to this package are documented here.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and versions follow [Semantic Versioning](https://semver.org/).

## [1.2.0] - 2026-11-17

### Added

- `EloquentRepository::restore()` and `forceDelete()` (and `CrudService` methods delegating to them) for models with `SoftDeletes`. No endpoints are generated for them: add a controller action and a route where your API needs one (see README, "Soft deletes"). They look the record up among soft-deleted ones too, go through the model (events run) and throw `ModelNotFoundException` for a missing record and `LogicException` for a model without `SoftDeletes`. Protected helpers: `findWithTrashedOrFail()`, `restoreOne()`, `forceDeleteOne()`.
- `--contract` layer (opt-in, not in `--all`): `App\Repositories\Contracts\PostRepositoryInterface` extending the package's `RepositoryInterface` (and `SoftDeletesRepositoryInterface` for models with `SoftDeletes`). The repository implements it, and the service (or the controller without a service) depends on it. On Laravel 12+ the interface binds itself with `#[Bind(PostRepository::class)]`; on Laravel 11 the binding is added to `$bindings` of `App\Providers\RepositoryServiceProvider`, which is created and registered in `bootstrap/providers.php` on first use. A published `config/layers.php` without the `contract` entry falls back to the defaults.
- OpenAPI documentation through [dedoc/scramble](https://scramble.dedoc.co) 0.13.54+, registered automatically when it is installed (the package does not require it): request bodies and query parameters from input DTOs (spatie/laravel-data, which Scramble does not read on its own), with route-filled fields left out and `int` documented as integer; `422` / `403` for DTOs with rules / `authorize()`; relations of `whenLoadedData()` as the related resource (or an array of them), optional and nullable. Classes in `Toshkq93\LaravelLayers\Scramble`.
- The generated `index()` with a resource has `@return AnonymousResourceCollection<LengthAwarePaginator<int, PostResource>>`, so OpenAPI tools document `links` and `meta`. Regenerate controllers (`--controller --force`) to get it.
- Several models in one run: `make:layers Post User Comment --all`, or `--all-models` for every non-abstract Eloquent model in `app/Models` (or `app/`). Output DTOs, resources and factories of models generated in the same run count as existing, so the models get each other's relations and factories in any order. Models are generated after the ones they belong to (`belongsTo`). Actions are shared.
- `Contracts\RepositoryInterface` and `Contracts\SoftDeletesRepositoryInterface`, both implemented by `EloquentRepository`.
- Static analysis in CI (PHPStan + Larastan, `composer analyse`): the package at level 6, and the code it generates at level `max`. `tests/Analysis/generate.php` runs `make:layers` for the test models into `build/generated`, and the analyser checks the result.

### Changed

- Generated code passes PHPStan at level `max`. Array properties of DTOs (json columns, array casts) get `/** @var array<array-key, mixed> */`; the middleware types `$next` as `Closure(Request): Response`; tests create records with `createOne()` instead of `create()`, so the analyser knows they get one model and not a collection. Regenerate (`--force`) to pick these up; nothing changes at runtime.
- `RepositoryInterface` and `SoftDeletesRepositoryInterface` are covariant in their output type, and `CrudService`'s `TRepository` is bound to `RepositoryInterface<TOutput>`: `CrudService<PostRepositoryInterface, PostData>` type-checks, and the service's methods return `PostData` for the analyser and the IDE (before, the output type was lost on the way from the repository).
- `EloquentRepository` implements `SoftDeletesRepositoryInterface`. `CrudService::restore()` / `forceDelete()` throw `LogicException` for a repository without it instead of calling an undefined method.
- The middleware layer is opt-in like `--gate` and `--contract`: `--all` and the defaults no longer generate `PostMiddleware`, which was an empty `handle()`. Pass `--middleware` to get it. Shared middleware such as `auth:sanctum` or `throttle:api` goes into `routes.group.middleware`.
- `CrudService` takes any `RepositoryInterface` instead of `EloquentRepository` (its `@template TRepository` too). Existing services keep working.

### Upgrading

- `--all` no longer generates the middleware. A route block regenerated with `--route --force` keeps `->middleware(PostMiddleware::class)` as long as the class exists; add `--middleware` to scripts that relied on getting a new one.
- A repository or service of your own that already declares `restore()` or `forceDelete()` with another signature now clashes with the base class: rename the method or match the signature `restore(int|string $id): OutputDataInterface`, `forceDelete(int|string $id): bool`.
- If you published the stubs, publish them again to get the typed middleware, the array docblocks and `createOne()` in tests (`php artisan vendor:publish --tag=layers-stubs --force`, then re-apply your changes). At least publish `repository.stub` again (or add `{{ implements }}` after `extends {{ extends }}`): without it a repository generated with `--contract` does not implement its interface and the container fails to inject it.

## [1.1.2] - 2026-11-10

Same code as 1.1.1, which was withdrawn: its tag was moved to another commit after publishing, and Packagist keeps published versions immutable. Use 1.1.2.

### Fixed

- `DELETE` of a missing record answers `404` instead of `204`: `EloquentRepository::delete()` (and `CrudService::delete()`) throw `ModelNotFoundException` like `findOrFail()` and `update()`. Code that relied on `delete()` returning `false` for a missing record must catch the exception.
- `delete()` loads the model and deletes it, so model events and observers (`deleting`, `deleted`) run. Before, a query-level delete skipped them.
- Models routed by another column (`getRouteKeyName()`, e.g. `slug`) work end to end: the generated repository sets `$findBy = 'slug'`, the input DTOs and the policy type the route value as that column (`string $id`), the update `Unique` rule ignores the current record by that column, and generated tests build URLs with `getRouteKey()`.

### Added

- `EloquentRepository::$findBy`: the column `find()`, `findOrFail()`, `update()` and `delete()` look records up by (default: the primary key), and the protected `findModelOrFail()` helper.
- Generated tests check that `DELETE` of a missing record returns `404`.

## [1.1.0] - 2026-11-09

> **Breaking changes despite the minor version.** Code generated by 1.0.x does not work with the 1.1 base classes, and responses with an API resource change format. Pin `toshkq93/laravel-layers:~1.0.1` until you have migrated, then follow the steps below.

### Upgrading from 1.0

1. Regenerate the repository, service and controller of every model (re-apply your own changes afterwards):
   `php artisan make:layers Post --repository --service --controller --force`.
   Custom repository methods built on `findOne()`, `createFrom()`, `updateFrom()` and `deleteOne()` keep working.
2. Generate the resources, related models first, then regenerate the tests:
   `php artisan make:layers User --resource` and `php artisan make:layers Post --resource --test --force`.
3. Regenerate the routes: `php artisan make:layers Post --route --force`.
4. If you published the stubs, publish them again: `php artisan vendor:publish --tag=layers-stubs --force`.
5. Clients read records from `data.*` and the pagination total from `meta.total`.

### Changed

- `--all` (and `--no-interaction` without layer options) no longer generates the policy. Pass `--gate` for it: `make:layers Post --all --gate`. In the interactive prompt the gate is unchecked by default.
- Routes are generated as one `Route::apiResource()` line per model (with `->only()`, `->parameters()` and `->middleware()` when needed) instead of one route per action in a middleware group. `update` now answers `PUT` as well as `PATCH`.
- **Breaking:** repositories generated by 1.0 declare `create(StorePostData $data)`, `findOrFail(int $id)`, etc., which are incompatible with the new base signatures. Delete these five methods from existing repositories (or regenerate them with `make:layers Post --repository --force`). The `repository.{action}` stubs are removed.
- **Breaking:** generated services extend `CrudService` and contain only a constructor; the controller unpacks the DTO itself (`$this->service->findOrFail($data->id)`, `paginate($data->perPage, $data->page)`, `create($data)`, `update($data->id, $data)`, `delete($data->id)`). Services and controllers generated by 1.0 call `index()/show()/store()/destroy()`: regenerate them with `make:layers Post --service --controller --force`, or rename the calls. The `service.{action}` stubs are removed, and a published `service.stub` must be re-published.
- **Breaking:** with a resource, the API responds in the Laravel resource format: a record is wrapped in `data`, a paginated list has `data` / `links` / `meta` (`meta.total` instead of `total`). Clients and tests that read top-level fields must use `data.*`. Generated feature tests follow the format of the model's controller.
- `--service` generates the repository when it does not exist (the service can no longer be a stub). The repository and the service no longer pull in input DTOs, only the output DTO.

### Added

- `--resource` layer (included in `--all`): an API resource (`App\Http\Resources\PostResource`) around the output DTO with explicit snake_case keys, `DATE_ATOM` dates and loaded relations through the related resources. The controller returns `PostResource::make()` / `PostResource::collection()` and `201` on store; without a resource it returns the output DTO as before. A published `config/layers.php` without the `resource` entry falls back to the defaults.
- `DataResource` base resource with `whenLoadedData()` for relations of output DTOs (`Optional` = not loaded).
- `CrudService` base service delegating the CRUD methods to the repository; `layers.service.extends` config (default `CrudService`).
- `EloquentRepository` has public CRUD methods: `paginate()`, `find()`, `findOrFail()`, `create()`, `update()`, `delete()`. Generated repositories no longer repeat them and contain only `model()`, `output()` and `$with`. `paginate()` sorts by the primary key; change it with `$orderBy` / `$orderDirection` or override `paginateQuery()`.
- `routes.group` config (`prefix`, `middleware`, `as`): places the resources of every model in one shared `Route::...->group()`. Generated tests use the prefixed route names.

## [1.0.1] - 2026-11-04

### Fixed

- README corrections.
- CI: the test matrix installs again with Composer 2.9+, which blocks packages with security advisories.

## [1.0.0] - 2026-11-03

First release.

### Added

- Runtime: `InputDataInterface` / `OutputDataInterface`, base `InputData` / `OutputData` DTOs on top of spatie/laravel-data, `EloquentRepository` that returns output DTOs only.
- `make:layers` command generating, per model: input DTOs per action, output DTO, repository, service, controller, policy (gate checked in the input DTO's `authorize()`), middleware, routes, model factory and feature tests.
- Schema introspection from the database table: types, nullability, defaults, foreign keys (`Exists`), single-column unique indexes (`Unique`, ignoring the current record on update), DB enums (`In`), lengths (`Max`), formats; model casts, `$hidden`, `$fillable` / `$guarded`, soft deletes.
- Model relations in the output DTO, returned only when loaded; `$with` in repositories.
- camelCase or snake_case DTO properties (`properties` config), read-only columns, configurable namespaces, base classes and publishable stubs.
- Laravel 11, 12 and 13; PHP 8.2+.
