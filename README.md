# Laravel Layers

Layered architecture for Laravel APIs, plus a generator that builds every layer from a model.

```
route ─► middleware ─► input DTO (validation + gate) ─► controller ─► service ─► repository ─► output DTO
                       InputDataInterface                                                    OutputDataInterface
```

- **Route** (`routes/api.php`) is the entry point.
- **Middleware** holds route access conditions.
- **Input DTO** ([spatie/laravel-data](https://github.com/spatie/laravel-data)) describes the request fields and their validation. Its `authorize()` runs the gate before the controller is reached.
- **Controller** is thin. It receives a validated DTO and calls the service.
- **Service** holds business logic. It may call other services and works with data only through its repository.
- **Repository** stores SQL queries. It returns an output DTO, or a collection or paginator of them. Eloquent models never leave the repository.
- **Output DTO** is what the rest of the code works with. Returned from a controller, it is serialized to JSON.

Every layer is optional: generate the ones you need, in one run or several.

## Requirements

- PHP 8.2+
- Laravel 11, 12 or 13
- spatie/laravel-data 4.11+ (installed as a dependency)

## Installation

```bash
composer require toshkq93/laravel-layers
```

The service provider is auto-discovered. Optionally publish the config and stubs:

```bash
php artisan vendor:publish --tag=layers-config   # config/layers.php
php artisan vendor:publish --tag=layers-stubs    # stubs/layers/*.stub
```

## Quick start

The generator reads the model's table, so run the migrations first.

```bash
php artisan migrate
php artisan make:layers Post --all
```

```
 app/Data/Input/Post/IndexPostData.php ........ created
 app/Data/Input/Post/ShowPostData.php ......... created
 app/Data/Input/Post/StorePostData.php ........ created
 app/Data/Input/Post/UpdatePostData.php ....... created
 app/Data/Input/Post/DestroyPostData.php ...... created
 app/Data/Output/PostData.php ................. created
 app/Repositories/PostRepository.php .......... created
 app/Services/PostService.php ................. created
 app/Http/Controllers/Api/PostController.php .. created
 app/Policies/PostPolicy.php .................. created
 app/Http/Middleware/PostMiddleware.php ....... created
 database/factories/PostFactory.php ........... created
 tests/Feature/PostApiTest.php ................ created
 routes/api.php ............................... routes added
 bootstrap/app.php ............................ api routes registered
```

The result is a working CRUD API at `/api/posts` with passing tests (`php artisan test --filter=PostApiTest`). Requests are validated against the table schema, access goes through the policy, and responses are typed DTOs.

## Command

```bash
php artisan make:layers {model} [options]
```

`model` takes a short name (`Post`, resolved to `App\Models\Post` or `App\Post`), a path (`Blog/Post`) or a fully qualified class name.

| Option | Generates |
|---|---|
| `--input` | One input DTO per action, with validation |
| `--output` | Output DTO |
| `--repository` | Repository |
| `--service` | Service |
| `--controller` | Controller |
| `--gate` | Policy and `authorize()` in the input DTOs |
| `--middleware` | Middleware applied to the model's routes |
| `--route` | Routes in `routes/api.php` |
| `--factory` | Model factory with Faker values that match the schema and pass the validation |
| `--test` | Feature tests for the API (the factory is generated too when missing) |
| `--all` | Every layer above |
| `--actions=index,show` | Limits the actions. Default: `index,show,store,update,destroy` |
| `--force` | Overwrites existing files and replaces the route block |
| `--dry-run` | Shows what would be generated without writing (`-v` also prints the code) |

When no layer option is given, the command asks for layers and actions interactively. With `--no-interaction` it generates every layer.

```bash
# Only the data layer
php artisan make:layers Post --input --output --repository

# A read-only API
php artisan make:layers Post --all --actions=index,show

# Add a service later: the existing repository is picked up
php artisan make:layers Post --service

# Regenerate the controller after changing stubs
php artisan make:layers Post --controller --force
```

### Actions

| Action | Route | Input DTO | Repository | Policy ability |
|---|---|---|---|---|
| `index` | `GET /posts` | `IndexPostData`: `page` (default 1), `per_page` (1–100, default 15; `$perPage` in PHP) | `paginate()` | `viewAny` |
| `show` | `GET /posts/{post}` | `ShowPostData`: `id` | `findOrFail()` | `view` |
| `store` | `POST /posts` | `StorePostData`: writable fields | `create()` | `create` |
| `update` | `PATCH /posts/{post}` | `UpdatePostData`: `id` and writable fields, all optional | `update()` | `update` |
| `destroy` | `DELETE /posts/{post}` | `DestroyPostData`: `id` | `delete()` | `delete` |

## Generated code

The examples below are generated for this model and migration on SQLite. On MySQL, MariaDB and PostgreSQL the `varchar` columns also get `#[Max(255)]` (see [Limitations](#limitations)).

```php
Schema::create('posts', function (Blueprint $table) {
    $table->id();
    $table->foreignId('user_id')->constrained();
    $table->string('title');
    $table->text('body')->nullable();
    $table->string('status')->default('draft');
    $table->boolean('published')->default(false);
    $table->timestamp('published_at')->nullable();
    $table->timestamps();
});

class Post extends Model
{
    protected $fillable = ['user_id', 'title', 'body', 'status', 'published', 'published_at'];

    protected function casts(): array
    {
        return [
            'status' => PostStatus::class,
            'published' => 'boolean',
            'published_at' => 'datetime',
        ];
    }
}
```

### Input DTOs

`StorePostData`. A field is required when the column is `NOT NULL` without a default. Fields with a DB default are `Optional`: when the client omits them, the database default applies.

```php
#[MapName(SnakeCaseMapper::class)]
class StorePostData extends InputData
{
    #[Exists('users', 'id')]
    public int $userId;

    public string $title;

    public ?string $body;

    public PostStatus|Optional $status;

    public bool|Optional $published;

    #[Date]
    public ?string $publishedAt;

    public static function authorize(): bool
    {
        return Gate::allows('create', Post::class);
    }
}
```

`UpdatePostData` performs a partial update: only the fields that were sent are written. The key comes from the route. `#[Hidden]` keeps it out of `toArray()`, so it is never passed to `update()`.

```php
#[MapName(SnakeCaseMapper::class)]
class UpdatePostData extends InputData
{
    #[FromRouteParameter('post'), Hidden]
    public int $id;

    #[Exists('users', 'id')]
    public int|Optional $userId;

    public string|Optional $title;

    public string|Optional|null $body;

    // ...

    public static function authorize(): bool
    {
        return Gate::allows('update', [Post::class, request()->route('post')]);
    }
}
```

### Output DTO

It contains every column except `$hidden` ones, typed after the model casts.

```php
#[MapName(SnakeCaseMapper::class)]
class PostData extends OutputData
{
    public int $id;
    public int $userId;
    public string $title;
    public ?string $body;
    public PostStatus $status;
    public bool $published;
    public ?CarbonInterface $publishedAt;
    public ?CarbonInterface $createdAt;
    public ?CarbonInterface $updatedAt;
}
```

The API still returns `user_id`, `published_at` and so on: the mapper only renames the PHP properties.

#### Relations

The model's relations are added to the output DTO and filled **only when the relation is loaded**. Building the DTO never queries the database, so there is no N+1.

```php
class Post extends Model
{
    public function user(): BelongsTo { return $this->belongsTo(User::class); }

    public function comments() { return $this->hasMany(Comment::class); }   // a return type is optional
}
```

```php
class PostData extends OutputData
{
    // ...columns

    public UserData|Optional|null $user;

    /** @var Collection<int, CommentData>|Optional */
    #[DataCollectionOf(CommentData::class)]
    public Collection|Optional $comments;
}
```

| Relation state | JSON |
|---|---|
| not loaded | left out |
| loaded | `"user": {...}`, `"comments": [{...}]` |
| loaded, but empty | `"user": null`, `"comments": []` |

How relations are found (the same way laravel-ide-helper finds them):

- **Which methods count:** public methods of the model (and of its own traits) without required parameters, either with a `Relation` return type or calling `$this->hasMany(...)`, `$this->belongsTo(...)`, etc. in their body.
- **Type and related model:** the method is called on an empty model, which builds the relation without querying, and gives the relation type and the related model.
- **Skipped:**
  - `morphTo`, whose related model is only known per record;
  - methods of framework traits, such as `Notifiable::notifications()`;
  - relations listed in `$hidden`.

A relation is added only when the related model's output DTO exists. Otherwise the command prints a hint. Generate DTOs for related models first:

```bash
php artisan make:layers User --output
php artisan make:layers Post --all     # PostData gets $user
```

Single relations (`belongsTo`, `hasOne`, `morphOne`, `hasOneThrough`) become `RelatedData|Optional|null`. Collection relations (`hasMany`, `belongsToMany`, `morphMany`, `morphToMany`, `hasManyThrough`) become `Collection|Optional`.

### Repository

```php
/**
 * @extends EloquentRepository<Post, PostData>
 */
class PostRepository extends EloquentRepository
{
    protected function model(): string { return Post::class; }

    protected function output(): string { return PostData::class; }

    /** @return LengthAwarePaginator<int, PostData> */
    public function paginate(int $perPage, int $page = 1): LengthAwarePaginator
    {
        return $this->toPaginator(
            $this->query()->orderBy('id')->paginate(perPage: $perPage, page: $page),
        );
    }

    public function findOrFail(int $id): PostData
    {
        return $this->findOneOrFail($id);
    }

    public function create(StorePostData $data): PostData
    {
        return $this->createFrom($data);
    }

    // update(), delete()
}
```

Add your own queries the same way: build them with `$this->query()` and convert the result with `toOutput()`, `toCollection()` or `toPaginator()`.

Relations listed in `$with` are eager-loaded by every query of the repository, so they appear in every response. The generated repository leaves `$with` empty and lists the available relations in a comment:

```php
/**
 * Relations loaded by every query and returned in the output DTO.
 * Available: 'comments', 'user'.
 */
protected array $with = ['user'];
```

When only some queries need a relation, load it in that query: `$this->query()->with('comments')->...`. After `create()` and `update()` the `$with` relations are loaded again. If `update()` changes `user_id`, the response therefore contains the new user.

```php
/** @return Collection<int, PostData> */
public function publishedBy(int $userId): Collection
{
    return $this->toCollection(
        $this->query()->where('user_id', $userId)->where('published', true)->latest()->get(),
    );
}
```

### Service and controller

```php
class PostService
{
    public function __construct(
        private readonly PostRepository $repository,
    ) {}

    public function store(StorePostData $data): PostData
    {
        return $this->repository->create($data);
    }

    public function update(UpdatePostData $data): PostData
    {
        return $this->repository->update($data->id, $data);
    }

    // index(), show(), destroy()
}

class PostController extends Controller
{
    public function __construct(
        private readonly PostService $service,
    ) {}

    public function store(StorePostData $data): PostData
    {
        return $this->service->store($data);   // 201 Created
    }

    public function destroy(DestroyPostData $data): Response
    {
        $this->service->destroy($data);

        return response()->noContent();        // 204 No Content
    }

    // index(), show(), update()
}
```

### Policy

Abilities receive the model key, not the model, because loading data is the repository's job. Inject the repository into the policy if a check needs the record. `$user` is nullable, so guests reach the policy instead of being rejected before it. All abilities return `true` until you write the rules.

```php
class PostPolicy
{
    public function view(?Authenticatable $user, int $id): bool
    {
        return true;
    }

    public function update(?Authenticatable $user, int $id): bool
    {
        return true;
    }

    // viewAny(), create(), delete()
}
```

### Routes

The routes are added in a marked block. On a second run the block is skipped; with `--force` it is replaced.

```php
// layers:Post:start
Route::middleware(PostMiddleware::class)->group(function () {
    Route::get('posts', [PostController::class, 'index'])->name('posts.index');
    Route::get('posts/{post}', [PostController::class, 'show'])->name('posts.show');
    Route::post('posts', [PostController::class, 'store'])->name('posts.store');
    Route::patch('posts/{post}', [PostController::class, 'update'])->name('posts.update');
    Route::delete('posts/{post}', [PostController::class, 'destroy'])->name('posts.destroy');
});
// layers:Post:end
```

If `routes/api.php` is not registered in `bootstrap/app.php` (the default in Laravel 11+ skeletons), the command adds `api: __DIR__.'/../routes/api.php'` next to `web:`.

### Factory

The factory fills every writable column with a Faker value chosen from the schema. Its `raw()` output is therefore a valid request payload for `StorePostData`.

```php
class PostFactory extends Factory
{
    protected $model = Post::class;

    public function definition(): array
    {
        return [
            'user_id' => UserFactory::new(),
            'title' => fake()->sentence(3),
            'body' => fake()->paragraph(),
            'status' => fake()->randomElement(PostStatus::cases()),
            'published' => fake()->boolean(),
            'published_at' => fake()->dateTime()->format('Y-m-d H:i:s'),
        ];
    }
}
```

Values are chosen in this order:

1. **Foreign key:** the related table's factory (`users` → `UserFactory::new()`), used only when it really builds a model of that table: the factory's model class (its `$model`, or the one resolved by convention) must exist, be an Eloquent model and use the referenced table. Otherwise the value is `null`, with a `TODO` comment when the column is `NOT NULL`.
2. **Enums:** a backed enum cast gives `randomElement(Enum::cases())`; DB enum values give `randomElement([...])`.
3. **Formats:** uuid, date, datetime, time and numeric.
4. **Types:** int, float, bool, array. A column with a custom cast class gets `null` and a `TODO` comment.
5. **Strings:** a hint from the column name (`email`, `name`, `title`, `slug`, `url`, `phone`, `body`, `description`, ...). A `varchar(N)` shorter than 255 gets `fake()->text(N)` instead, so the value passes `#[Max(N)]`.

Columns with a unique index get `fake()->unique()->...`, except booleans and enums, which have too few values for that.

Because `$model` is set explicitly, the tests call `PostFactory::new()` and the model does not need the `HasFactory` trait.

### Tests

`tests/Feature/PostApiTest.php` uses `RefreshDatabase` and contains tests for each generated action:

| Action | Tests |
|---|---|
| `index` | Pagination (`per_page`, `total`); `per_page` above the limit returns 422 |
| `show` | Returns the record; a missing key returns 404 |
| `store` | Creates from `PostFactory::new()->raw()` and checks the response and the database; an empty payload returns 422 for every required field; a duplicate value of the first unique column returns 422 |
| `update` | A partial update changes one field in the response and the database; a missing key returns 404; a record may keep its own value of the first unique column, but taking another record's value returns 422 |
| `destroy` | Returns 204; `assertModelMissing`, or `assertSoftDeleted` for models with `SoftDeletes` |

```php
public function test_update_changes_post(): void
{
    $post = PostFactory::new()->create();
    $payload = Arr::only(PostFactory::new()->raw(), ['title']);

    $this->patchJson(route('posts.update', $post->getKey()), $payload)
        ->assertOk()
        ->assertJsonPath('title', $payload['title']);

    $this->assertDatabaseHas('posts', [
        'id' => $post->getKey(),
        'title' => $payload['title'],
    ]);
}
```

The tests send requests as a guest, which the generated policy allows. Once the policy requires a user, authenticate in `setUp()`, for example with `$this->actingAs(UserFactory::new()->create())`. The tests call routes by name (`posts.store`), so they expect the routes generated by `--route`.

## How layers connect

A layer counts as **available** when it is generated in the current run or its class already exists. This lets you generate layers in several runs and still get code that is wired together.

- The **controller** calls the service if one is available. Otherwise it calls the repository directly. If neither exists, its methods throw `LogicException('Not implemented.')`.
- The **service** calls the repository if one is available. Otherwise its methods throw `LogicException`.
- The **controller, service and repository** cannot compile without DTOs. If the input or output DTO classes do not exist, they are generated as well, and the command reports it.
- **Input DTOs** get `authorize()` only when a policy is available.
- **Routes** are written only when the controller is available, and are grouped under the middleware when it is available.
- **Tests** need the factory. If it does not exist, it is generated as well.

## Schema → types and validation

Columns, types, nullability, defaults and foreign keys come from the **table** (`Schema::getColumns()`, `Schema::getForeignKeys()`), the same way laravel-ide-helper reads them. The model does not have to list its columns anywhere. It only refines the result:

- `$casts` give precise types (enums, dates, booleans). A cast takes precedence over the column type.
- `$hidden` removes columns from the output DTO.
- `$fillable` / `$guarded` limit which columns the client may send.

| Column / cast | Input DTO | Output DTO | Extra validation |
|---|---|---|---|
| `int`, `bigint`, ... | `int` | `int` | |
| `tinyint(1)`, `boolean` | `bool` | `bool` | |
| `float`, `double` | `float` | `float` | |
| `decimal`, `numeric` / `decimal:2` | `string` | `string` | `#[Numeric]` (kept as a string to avoid losing precision) |
| `varchar(N)` | `string` | `string` | `#[Max(N)]` |
| `enum('a','b')` (MySQL) | `string` | `string` | `#[In(['a', 'b'])]` |
| `uuid` (native type: PostgreSQL, MariaDB) | `string` | `string` | `#[Uuid]` (on MySQL `uuid()` is `char(36)` and gets `#[Max(36)]`) |
| `json`, `jsonb` / `array`, `AsCollection` | `array` | `array` | |
| `date`, `datetime` / `date`, `datetime` cast, timestamps | `string` | `CarbonInterface` | `#[Date]` |
| `date`, `datetime`, `timestamp` without a cast | `string` | `string` | `#[Date]` |
| `time` | `string` | `string` | |
| Backed enum cast | `PostStatus` | `PostStatus` | Inferred by laravel-data |
| Custom cast class | `mixed` | `mixed` | |
| Foreign key | | | `#[Exists('users', 'id')]` |
| Unique index on one column | | | Store: `#[Unique('posts', 'slug')]`. Update: `#[Unique('posts', 'slug', ignore: new RouteParameterReference('post'), ignoreColumn: 'id')]`, so a record may keep its own value |

`required`, `nullable` and `sometimes` are inferred by laravel-data from the property type: `?type`, `type|Optional`.

Unique rules count soft-deleted rows as well, because the database index does too. Composite unique indexes such as `(user_id, title)` cannot be expressed as a rule on one property and are skipped; validate them in `rules()` by hand.

Which fields go where:

- **Input DTOs:** every column, except:
  - the primary key and auto-increment columns;
  - `created_at`, `updated_at` and the soft-delete column;
  - `read_only_columns` from the config (default: `deleted_at`, `remember_token`), even for unguarded models;
  - columns excluded by the model: not in `$fillable` when it is set, or listed in `$guarded`.

  `$guarded = []`, or no `$fillable` at all, therefore means every column.
- **Output DTO:** every column except `$hidden`.

## Property naming

`'properties'` in the config controls the names of DTO properties:

| Value | PHP property | Request / response / Eloquent |
|---|---|---|
| `'camel'` (default) | `$userId`, `$publishedAt`, `$perPage` | `user_id`, `published_at`, `per_page` |
| `'snake'` | `$user_id`, `$published_at`, `$per_page` | `user_id`, `published_at`, `per_page` |

In `camel` mode every DTO is marked with `#[MapName(SnakeCaseMapper::class)]`. laravel-data then maps the request input, validation error keys, the JSON output and `toArray()` (which the repository passes to Eloquent) to snake_case, so the HTTP contract and the database are the same in both modes.

Some columns do not survive the round trip: `code_2fa` becomes `$code2fa`, which maps back to `code2fa`. Those properties get their own mapping:

```php
#[MapName('code_2fa')]
public ?string $code2fa;
```

## Configuration

`config/layers.php`:

```php
'layers' => [
    'input'      => ['namespace' => 'App\\Data\\Input', 'suffix' => 'Data', 'extends' => InputData::class],
    'output'     => ['namespace' => 'App\\Data\\Output', 'suffix' => 'Data', 'extends' => OutputData::class],
    'controller' => ['namespace' => 'App\\Http\\Controllers\\Api', 'suffix' => 'Controller', 'extends' => 'App\\Http\\Controllers\\Controller'],
    'service'    => ['namespace' => 'App\\Services', 'suffix' => 'Service'],
    'repository' => ['namespace' => 'App\\Repositories', 'suffix' => 'Repository', 'extends' => EloquentRepository::class],
    'middleware' => ['namespace' => 'App\\Http\\Middleware', 'suffix' => 'Middleware'],
    'policy'     => ['namespace' => 'App\\Policies', 'suffix' => 'Policy'],
    // Layers outside app/ declare a path relative to the project root:
    'factory'    => ['namespace' => 'Database\\Factories', 'suffix' => 'Factory', 'path' => 'database/factories', 'extends' => Factory::class],
    'test'       => ['namespace' => 'Tests\\Feature', 'suffix' => 'ApiTest', 'path' => 'tests/Feature', 'extends' => 'Tests\\TestCase'],
],

'read_only_columns' => ['deleted_at', 'remember_token'],

'properties' => 'camel',

'routes' => ['file' => 'routes/api.php'],

'actions' => ['index', 'show', 'store', 'update', 'destroy'],
```

- File paths are derived from the namespaces (`App\` → `app/`). Namespaces must be inside the application namespace.
- Input DTOs are grouped per model: `App\Data\Input\Post\StorePostData`.
- `extends` lets you swap the base classes for your own. Your base classes must extend the package ones.
- The controller only extends `App\Http\Controllers\Controller` when that class exists.

If you move policies out of `App\Policies`, Laravel will not discover them. The command warns you; register the policy yourself:

```php
Gate::policy(Post::class, \App\Domain\Policies\PostPolicy::class);
```

## Customizing stubs

```bash
php artisan vendor:publish --tag=layers-stubs
```

Stubs are copied to `stubs/layers/`. A stub found there takes precedence over the package one. Each layer has a class stub (`controller.stub`) and one method stub per action (`controller.store.stub`, `controller.update.stub`, ...). Placeholders look like `{{ name }}`:

| Stub | Placeholders |
|---|---|
| `input`, `output` | `namespace`, `imports`, `attributes` (class-level `#[MapName]`), `class`, `extends`, `body` |
| `input.authorize` | `parameters`, `gate`, `ability`, `arguments` |
| `repository` | `namespace`, `imports`, `class`, `extends`, `model`, `output`, `with`, `methods` |
| `repository.{action}` | `input`, `output`, `keyType`, `key` |
| `service`, `controller` | `namespace`, `imports`, `class`, `constructor`, `methods` (controller also has `extends`) |
| `service.{action}`, `controller.{action}` | `input`, `output`, `call` |
| `policy` | `namespace`, `imports`, `class`, `model`, `methods` |
| `policy.{action}` | `keyType` |
| `middleware` | `namespace`, `class`, `model` |
| `factory` | `namespace`, `imports`, `class`, `extends`, `model`, `definition` |
| `test` | `namespace`, `imports`, `class`, `extends`, `model`, `methods` |
| `test.{action}` | `factory`, `route`, `variable`, `singular`, `plural`, `table`, `key`, `missingKey`; plus `assertions`, `unique` (store, update), `validation` (store), `payload`, `database` (update), `deleted` (destroy) |
| `test.store.validation` | the common `test.{action}` placeholders and `fields` |
| `test.store.unique`, `test.update.unique` | the action's placeholders and `field` (the first unique column); rendered into `{{ unique }}` |

## Runtime API

The generated code is built on a few package classes, which you can also use by hand.

**Contracts:** `Toshkq93\LaravelLayers\Contracts\InputDataInterface` and `OutputDataInterface`.

**Base DTOs:** `Toshkq93\LaravelLayers\Data\InputData` and `OutputData`. Both extend `Spatie\LaravelData\Data` and implement the matching contract, so everything from laravel-data is available: validation attributes, `rules()`, casts, transformers, lazy properties.

**`Toshkq93\LaravelLayers\Repositories\EloquentRepository`:** a base repository with protected helpers.

| Method | Returns |
|---|---|
| `$with` | Relations eager-loaded by `query()` (default `[]`) |
| `query()` | `Builder` for the model, with `$with` applied |
| `toOutput(?Model)` | Output DTO or `null` |
| `toCollection(iterable)` | `Collection` of output DTOs |
| `toPaginator(LengthAwarePaginator)` | Paginator of output DTOs |
| `findOne($id)` | Output DTO or `null` |
| `findOneOrFail($id)` | Output DTO, or throws `ModelNotFoundException` (404) |
| `createFrom(InputDataInterface)` | Output DTO. The model is re-read so DB defaults are included |
| `updateFrom($id, InputDataInterface)` | Output DTO. Only the attributes present in the DTO are updated |
| `deleteOne($id)` | `bool` |

## Limitations

- **SQLite** does not store `varchar` lengths or enum values, so `#[Max]` and `#[In]` are only generated on MySQL, MariaDB and PostgreSQL. Use model casts (for example a backed enum) to get typed validation on any driver.
- **Composite primary keys** are not supported.
- **Composite unique indexes** are not turned into validation rules.
- **A foreign key without a factory** for its table gets `null` and a `TODO` in the factory; fill it in before running the tests.
- **`morphTo` relations** are not added to the output DTO.

## Testing

```bash
composer test
```

The suite includes an end-to-end test that generates every layer into a temporary directory, loads the classes and drives the API over HTTP: validation, CRUD, 404 and gate denial.

## License

MIT
