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
use Toshkq93\LaravelLayers\Generation\RouteWriter;
use Toshkq93\LaravelLayers\Schema\ModelInspector;
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

    /** @param list<Action> $actions */
    private function blueprint(array $layers, array $actions = []): Blueprint
    {
        $definition = (new ModelInspector)->inspect(Post::class);
        $naming = new Naming($definition, config('layers.layers'), 'App\\', $this->dir.'/app');

        return new Blueprint($definition, $naming, $layers, $actions ?: Action::cases());
    }

    public function test_block_with_middleware(): void
    {
        $block = $this->writer->block($this->blueprint([Layer::Route, Layer::Middleware], [Action::Index, Action::Update]));

        $this->assertSame(<<<'PHP'
            // layers:Post:start
            Route::middleware(PostMiddleware::class)->group(function () {
                Route::get('posts', [PostController::class, 'index'])->name('posts.index');
                Route::patch('posts/{post}', [PostController::class, 'update'])->name('posts.update');
            });
            // layers:Post:end
            PHP, $block);
    }

    public function test_block_without_middleware(): void
    {
        $block = $this->writer->block($this->blueprint([Layer::Route], [Action::Show]));

        $this->assertStringContainsString("\nRoute::get('posts/{post}', [PostController::class, 'show'])->name('posts.show');\n", $block);
        $this->assertStringNotContainsString('middleware', $block);
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
