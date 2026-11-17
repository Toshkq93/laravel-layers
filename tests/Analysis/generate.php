<?php

declare(strict_types=1);

/*
 * Generates every layer of the fixture models into build/generated, so
 * PHPStan can analyse the code the package generates (phpstan-generated.neon).
 *
 *     php tests/Analysis/generate.php && vendor/bin/phpstan analyse -c phpstan-generated.neon
 *
 * It runs the real make:layers command in a Testbench application whose
 * app/ points at build/generated/app. Routes are left out: the routes file
 * belongs to the Testbench skeleton.
 */

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Schema\Blueprint as Table;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application as Laravel;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\Foundation\Application;
use Spatie\LaravelData\LaravelDataServiceProvider;
use Toshkq93\LaravelLayers\LaravelLayersServiceProvider;
use Toshkq93\LaravelLayers\Tests\Fixtures\Article;
use Toshkq93\LaravelLayers\Tests\Fixtures\Comment;
use Toshkq93\LaravelLayers\Tests\Fixtures\User;

require __DIR__.'/../../vendor/autoload.php';

$root = dirname(__DIR__, 2);
$build = $root.'/build/generated';

(new Filesystem)->deleteDirectory($build);
(new Filesystem)->ensureDirectoryExists($build.'/app');

$app = Application::create(
    resolvingCallback: function (Laravel $app) use ($build) {
        $app->useAppPath($build.'/app');
        // The Testbench skeleton has no composer.json to read the App\ namespace from.
        (new ReflectionProperty($app, 'namespace'))->setValue($app, 'App\\');
        // A Laravel app does this in LoadConfiguration; without it the container ignores #[Bind].
        $app->resolveEnvironmentUsing($app->environment(...));
    },
    options: ['extra' => ['providers' => [LaravelDataServiceProvider::class, LaravelLayersServiceProvider::class]]],
);

$app['config']->set('database.default', 'testing');
$app['config']->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
$app['config']->set('layers.layers.factory.path', $build.'/database/factories');
$app['config']->set('layers.layers.test.path', $build.'/tests/Feature');
// The skeleton has no Tests\TestCase.
$app['config']->set('layers.layers.test.extends', Toshkq93\LaravelLayers\Tests\TestCase::class);

// The same tables as the end-to-end test (tests/Feature/GeneratedCodeTest.php).
Schema::create('users', fn (Table $t) => $t->id());
Schema::create('articles', function (Table $table) {
    $table->id();
    $table->foreignId('user_id')->constrained();
    $table->string('title', 200);
    $table->text('body')->nullable();
    $table->string('status')->default('draft');
    $table->json('meta')->nullable();
    $table->boolean('published')->default(false);
    $table->timestamp('published_at')->nullable();
    $table->string('secret')->default('s3cret');
    $table->string('code_2fa')->nullable();
    $table->string('slug')->nullable()->unique();
    $table->timestamps();
    $table->softDeletes();
});
Schema::create('comments', function (Table $table) {
    $table->id();
    $table->foreignId('article_id')->constrained();
    $table->string('body');
    $table->timestamps();
});

// The second run finds the classes of the first one, as in an application.
spl_autoload_register(function (string $class) use ($build) {
    foreach (['App\\' => '/app/', 'Database\\Factories\\' => '/database/factories/'] as $prefix => $dir) {
        if (str_starts_with($class, $prefix) && is_file($path = $build.$dir.str_replace('\\', '/', substr($class, strlen($prefix))).'.php')) {
            require $path;
        }
    }
});

$kernel = $app->make(Kernel::class);

// Every layer but --route, which would write into the Testbench skeleton.
$layers = array_fill_keys(['--input', '--output', '--resource', '--repository', '--service', '--controller', '--gate', '--middleware', '--factory', '--test'], true);

$runs = [
    // Several models in one run, with repository contracts and SoftDeletes (Article).
    fn () => $kernel->call('make:layers', ['model' => [User::class, Article::class], ...$layers, '--contract' => true, '--no-interaction' => true]),
    // Services on the concrete repository, snake_case properties, relations to existing classes.
    function () use ($app, $kernel, $layers) {
        $app['config']->set('layers.properties', 'snake');

        return $kernel->call('make:layers', ['model' => [Comment::class], ...$layers, '--no-interaction' => true]);
    },
];

foreach ($runs as $run) {
    $status = $run();
    echo $kernel->output();

    if ($status !== 0) {
        exit($status);
    }
}
