<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Layers
    |--------------------------------------------------------------------------
    |
    | Where each generated layer lives and how its classes are named.
    | The file path is derived from the namespace using the application's
    | PSR-4 root (App\ => app/).
    |
    */

    'layers' => [
        'input' => [
            'namespace' => 'App\\Data\\Input',
            'suffix' => 'Data',
            'extends' => \Toshkq93\LaravelLayers\Data\InputData::class,
        ],
        'output' => [
            'namespace' => 'App\\Data\\Output',
            'suffix' => 'Data',
            'extends' => \Toshkq93\LaravelLayers\Data\OutputData::class,
        ],
        'resource' => [
            'namespace' => 'App\\Http\\Resources',
            'suffix' => 'Resource',
            'extends' => \Toshkq93\LaravelLayers\Http\Resources\DataResource::class,
        ],
        'controller' => [
            'namespace' => 'App\\Http\\Controllers\\Api',
            'suffix' => 'Controller',
            // Used only when the class exists.
            'extends' => 'App\\Http\\Controllers\\Controller',
        ],
        'service' => [
            'namespace' => 'App\\Services',
            'suffix' => 'Service',
            'extends' => \Toshkq93\LaravelLayers\Services\CrudService::class,
        ],
        'repository' => [
            'namespace' => 'App\\Repositories',
            'suffix' => 'Repository',
            'extends' => \Toshkq93\LaravelLayers\Repositories\EloquentRepository::class,
        ],
        'middleware' => [
            'namespace' => 'App\\Http\\Middleware',
            'suffix' => 'Middleware',
        ],
        'policy' => [
            'namespace' => 'App\\Policies',
            'suffix' => 'Policy',
        ],
        // Layers outside app/ need an explicit path (relative to the project root).
        'factory' => [
            'namespace' => 'Database\\Factories',
            'suffix' => 'Factory',
            'path' => 'database/factories',
            'extends' => \Illuminate\Database\Eloquent\Factories\Factory::class,
        ],
        'test' => [
            'namespace' => 'Tests\\Feature',
            'suffix' => 'ApiTest',
            'path' => 'tests/Feature',
            'extends' => 'Tests\\TestCase',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Read-only columns
    |--------------------------------------------------------------------------
    |
    | Columns never accepted from the client (left out of the store / update
    | DTOs) whatever the model's $fillable / $guarded say — useful for
    | unguarded models ($guarded = []). They stay in the output DTO unless
    | they are $hidden. The primary key and timestamps are always read-only.
    |
    */

    'read_only_columns' => ['deleted_at', 'remember_token'],

    /*
    |--------------------------------------------------------------------------
    | DTO property naming
    |--------------------------------------------------------------------------
    |
    | 'camel' — PHP properties are camelCase ($userId) and the DTO class is
    |           mapped with #[MapName(SnakeCaseMapper::class)], so request
    |           input, validation errors, JSON output and the attributes passed
    |           to Eloquent stay snake_case (user_id).
    | 'snake' — properties are named exactly like the columns ($user_id).
    |
    */

    'properties' => 'camel',

    /*
    |--------------------------------------------------------------------------
    | Routes
    |--------------------------------------------------------------------------
    |
    | Every model gets one Route::apiResource() line. With a non-empty
    | 'group' all of them are placed in one shared group:
    |
    |     Route::prefix('v1')->middleware(['auth:sanctum'])->name('v1.')->group(function () {
    |         Route::apiResource('posts', PostController::class);
    |         Route::apiResource('users', UserController::class);
    |     });
    |
    | The group line is written once; edit it by hand afterwards.
    |
    */

    'routes' => [
        'file' => 'routes/api.php',

        'group' => [
            'prefix' => null,      // 'v1'
            'middleware' => [],    // ['auth:sanctum']
            'as' => null,          // 'v1.' — route name prefix
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Default actions
    |--------------------------------------------------------------------------
    |
    | Actions generated when --actions is not passed.
    |
    */

    'actions' => ['index', 'show', 'store', 'update', 'destroy'],

];
