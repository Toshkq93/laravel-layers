<?php

declare(strict_types=1);

namespace Toshkq93\LaravelLayers\Tests\Feature;

use Illuminate\Database\Schema\Blueprint as Table;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Schema;
use Toshkq93\LaravelLayers\Generation\Action;
use Toshkq93\LaravelLayers\Generation\Blueprint;
use Toshkq93\LaravelLayers\Generation\Layer;
use Toshkq93\LaravelLayers\Generation\Naming;
use Toshkq93\LaravelLayers\Generation\RouteGroup;
use Toshkq93\LaravelLayers\Generation\RouteWriter;
use Toshkq93\LaravelLayers\Schema\ModelInspector;
use Toshkq93\LaravelLayers\Tests\Fixtures\OpenArticle;
use Toshkq93\LaravelLayers\Tests\Fixtures\Post;
use Toshkq93\LaravelLayers\Tests\TestCase;

class RouteWriterTest extends TestCase
{
    private string $dir;

    private RouteWriter $writer;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('posts', function (Table $table) {
            $table->id();
            $table->string('title');
        });

        $this->dir = sys_get_temp_dir().'/layers-routes-'.bin2hex(random_bytes(4));
        mkdir($this->dir.'/routes', 0777, true);
        mkdir($this->dir.'/bootstrap', 0777, true);
        $this->writer = new RouteWriter(new Filesystem);
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->dir);

        parent::tearDown();
    }

    /**
     * @param  list<Action>  $actions
     * @param  class-string  $model
     */
    private function blueprint(array $layers, array $actions = [], ?RouteGroup $group = null, string $model = Post::class): Blueprint
    {
        $definition = (new ModelInspector)->inspect($model);
        $naming = new Naming($definition, config('layers.layers'), 'App\\', $this->dir.'/app');

        return new Blueprint($definition, $naming, $layers, $actions ?: Action::cases(), $group ?? new RouteGroup);
    }

    private function group(): RouteGroup
    {
        return RouteGroup::fromConfig(['prefix' => '/v1/', 'middleware' => ['auth:sanctum'], 'as' => 'v1.']);
    }

    public function test_block_for_all_actions_is_one_line(): void
    {
        $this->assertSame(<<<'PHP'
            // layers:Post:start
            Route::apiResource('posts', PostController::class);
            // layers:Post:end
            PHP, $this->writer->block($this->blueprint([Layer::Route])));
    }

    public function test_block_with_actions_and_middleware(): void
    {
        $block = $this->writer->block($this->blueprint([Layer::Route, Layer::Middleware], [Action::Index, Action::Update]));

        $this->assertSame(<<<'PHP'
            // layers:Post:start
            Route::apiResource('posts', PostController::class)
                ->only(['index', 'update'])
                ->middleware(PostMiddleware::class);
            // layers:Post:end
            PHP, $block);
    }

    public function test_block_maps_compound_parameter(): void
    {
        Schema::create('articles', function (Table $table) {
            $table->id();
        });

        $block = $this->writer->block($this->blueprint([Layer::Route], model: OpenArticle::class));

        $this->assertStringContainsString("Route::apiResource('open-articles', OpenArticleController::class)\n    ->parameters(['open-articles' => 'openArticle']);", $block);
    }

    public function test_generated_routes_register_resource_routes(): void
    {
        $path = $this->dir.'/routes/api.php';
        $this->writer->write($this->blueprint([Layer::Route], [Action::Index, Action::Show, Action::Update], $this->group()), $path);

        // Load the file the way Laravel would, with stub classes for the imports.
        if (! class_exists('App\\Http\\Controllers\\Api\\PostController')) {
            eval('namespace App\\Http\\Controllers\\Api; class PostController {}');
        }

        require $path;

        $routes = app('router')->getRoutes();
        $routes->refreshNameLookups();

        $this->assertSame('v1/posts', $routes->getByName('v1.posts.index')->uri());
        $this->assertSame('v1/posts/{post}', $routes->getByName('v1.posts.show')->uri());
        $this->assertSame(['PUT', 'PATCH'], $routes->getByName('v1.posts.update')->methods());
        $this->assertSame(['auth:sanctum'], $routes->getByName('v1.posts.show')->middleware());
        $this->assertNull($routes->getByName('v1.posts.store'));
    }

    public function test_group_wraps_blocks_of_every_model(): void
    {
        Schema::create('articles', function (Table $table) {
            $table->id();
        });

        $path = $this->dir.'/routes/api.php';

        $this->assertSame(RouteWriter::CREATED, $this->writer->write($this->blueprint([Layer::Route], group: $this->group()), $path)['status']);
        $this->assertSame(RouteWriter::CREATED, $this->writer->write($this->blueprint([Layer::Route], group: $this->group(), model: OpenArticle::class), $path)['status']);

        $this->assertStringEndsWith(<<<'PHP'
            // layers:start
            Route::prefix('v1')->middleware(['auth:sanctum'])->name('v1.')->group(function () {
                // layers:Post:start
                Route::apiResource('posts', PostController::class);
                // layers:Post:end

                // layers:OpenArticle:start
                Route::apiResource('open-articles', OpenArticleController::class)
                    ->parameters(['open-articles' => 'openArticle']);
                // layers:OpenArticle:end
            });
            // layers:end

            PHP, file_get_contents($path));

        // --force replaces the block in place, keeping the indentation.
        $this->assertSame(RouteWriter::REPLACED, $this->writer->write($this->blueprint([Layer::Route], [Action::Index], $this->group()), $path, force: true)['status']);
        $contents = file_get_contents($path);
        $this->assertStringContainsString("    // layers:Post:start\n    Route::apiResource('posts', PostController::class)\n        ->only(['index']);\n    // layers:Post:end\n", $contents);
        $this->assertSame(1, substr_count($contents, 'layers:start'));
    }

    public function test_route_names_in_tests_use_the_group_name_prefix(): void
    {
        $this->assertSame('v1.posts.show', $this->blueprint([Layer::Route], group: $this->group())->routeName(Action::Show));
        $this->assertSame('posts.show', $this->blueprint([Layer::Route])->routeName(Action::Show));
    }

    public function test_creates_file_and_is_idempotent(): void
    {
        $path = $this->dir.'/routes/api.php';
        $blueprint = $this->blueprint([Layer::Route]);

        $this->assertSame(RouteWriter::CREATED, $this->writer->write($blueprint, $path)['status']);
        $first = file_get_contents($path);

        $this->assertStringStartsWith("<?php\n\nuse Illuminate\\Support\\Facades\\Route;\nuse App\\Http\\Controllers\\Api\\PostController;\n", $first);

        $this->assertSame(RouteWriter::SKIPPED, $this->writer->write($blueprint, $path)['status']);
        $this->assertSame($first, file_get_contents($path));

        $this->assertSame(RouteWriter::REPLACED, $this->writer->write($this->blueprint([Layer::Route], [Action::Index]), $path, force: true)['status']);
        $replaced = file_get_contents($path);
        $this->assertSame(1, substr_count($replaced, 'layers:Post:start'));
        $this->assertStringNotContainsString("'destroy'", $replaced);
    }

    public function test_appends_to_existing_routes(): void
    {
        $path = $this->dir.'/routes/api.php';
        file_put_contents($path, "<?php\n\nuse Illuminate\\Http\\Request;\nuse Illuminate\\Support\\Facades\\Route;\n\nRoute::get('/user', fn (Request \$r) => \$r->user());\n");

        $this->writer->write($this->blueprint([Layer::Route]), $path);
        $contents = file_get_contents($path);

        $this->assertStringContainsString("use Illuminate\\Support\\Facades\\Route;\nuse App\\Http\\Controllers\\Api\\PostController;\n\nRoute::get('/user'", $contents);
        $this->assertSame(1, substr_count($contents, 'use Illuminate\\Support\\Facades\\Route;'));
        $this->assertStringEndsWith("// layers:Post:end\n", $contents);
    }

    public function test_registers_api_routes_in_bootstrap(): void
    {
        $bootstrap = $this->dir.'/bootstrap/app.php';
        file_put_contents($bootstrap, <<<'PHP'
            <?php
            return Application::configure(basePath: dirname(__DIR__))
                ->withRouting(
                    web: __DIR__.'/../routes/web.php',
                    commands: __DIR__.'/../routes/console.php',
                )->create();
            PHP);

        $routes = $this->dir.'/routes/api.php';

        $this->assertTrue($this->writer->ensureRegistered($bootstrap, $routes, $this->dir));
        $this->assertStringContainsString(
            "        web: __DIR__.'/../routes/web.php',\n        api: __DIR__.'/../routes/api.php',\n",
            file_get_contents($bootstrap),
        );

        $this->assertNull($this->writer->ensureRegistered($bootstrap, $routes, $this->dir));
    }
}
