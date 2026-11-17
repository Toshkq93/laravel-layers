<?php

declare(strict_types=1);

namespace Toshkq93\LaravelLayers\Tests\Feature;

use Illuminate\Database\Schema\Blueprint as Table;
use Illuminate\Support\Facades\Schema;
use Toshkq93\LaravelLayers\Tests\Fixtures\Article;
use Toshkq93\LaravelLayers\Tests\Fixtures\Comment;
use Toshkq93\LaravelLayers\Tests\Fixtures\User;
use Toshkq93\LaravelLayers\Tests\Fixtures\Post;
use Toshkq93\LaravelLayers\Tests\TestCase;

class MakeLayersCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('posts', function (Table $table) {
            $table->id();
            $table->string('title');
        });
    }

    public function test_dry_run_lists_files_without_writing(): void
    {
        $this->artisan('make:layers', ['model' => Post::class, '--controller' => true, '--actions' => 'index,store', '--dry-run' => true])
            ->expectsOutputToContain('app/Data/Input/Post/IndexPostData.php')
            ->expectsOutputToContain('app/Data/Input/Post/StorePostData.php')
            ->expectsOutputToContain('app/Data/Output/PostData.php')
            ->expectsOutputToContain('app/Http/Controllers/Api/PostController.php')
            ->doesntExpectOutputToContain('ShowPostData')
            ->doesntExpectOutputToContain('PostRepository.php')
            ->assertSuccessful();

        $this->assertFileDoesNotExist(app_path('Http/Controllers/Api/PostController.php'));
    }

    public function test_all_skips_the_gate(): void
    {
        $this->artisan('make:layers', ['model' => Post::class, '--all' => true, '--dry-run' => true])
            ->expectsOutputToContain('app/Http/Controllers/Api/PostController.php')
            ->expectsOutputToContain('app/Http/Resources/PostResource.php')
            ->doesntExpectOutputToContain('PostPolicy.php')
            ->assertSuccessful();
    }

    public function test_middleware_is_opt_in(): void
    {
        $this->artisan('make:layers', ['model' => Post::class, '--all' => true, '--dry-run' => true])
            ->doesntExpectOutputToContain('PostMiddleware.php')
            ->assertSuccessful();

        $this->artisan('make:layers', ['model' => Post::class, '--dry-run' => true, '--no-interaction' => true])
            ->doesntExpectOutputToContain('PostMiddleware.php')
            ->assertSuccessful();

        $this->artisan('make:layers', ['model' => Post::class, '--all' => true, '--middleware' => true, '--dry-run' => true])
            ->expectsOutputToContain('app/Http/Middleware/PostMiddleware.php')
            ->assertSuccessful();
    }

    public function test_no_interaction_without_layer_options_skips_the_gate(): void
    {
        $this->artisan('make:layers', ['model' => Post::class, '--dry-run' => true, '--no-interaction' => true])
            ->expectsOutputToContain('app/Repositories/PostRepository.php')
            ->doesntExpectOutputToContain('PostPolicy.php')
            ->assertSuccessful();
    }

    public function test_all_with_gate_generates_the_policy(): void
    {
        $this->artisan('make:layers', ['model' => Post::class, '--all' => true, '--gate' => true, '--dry-run' => true])
            ->expectsOutputToContain('app/Policies/PostPolicy.php')
            ->expectsOutputToContain('app/Http/Controllers/Api/PostController.php')
            ->assertSuccessful();
    }

    public function test_unknown_model_fails(): void
    {
        $this->artisan('make:layers', ['model' => 'Nope', '--all' => true])
            ->expectsOutputToContain('Model [Nope] not found.')
            ->assertFailed();
    }

    public function test_soft_deletes_add_no_endpoints(): void
    {
        Schema::create('articles', function (Table $table) {
            $table->id();
            $table->softDeletes();
        });

        // restore() / forceDelete() live in the repository and the service; no HTTP actions for them.
        $this->artisan('make:layers', ['model' => Article::class, '--input' => true, '--dry-run' => true])
            ->expectsOutputToContain('DestroyArticleData.php')
            ->doesntExpectOutputToContain('RestoreArticleData.php')
            ->assertSuccessful();

        $this->artisan('make:layers', ['model' => Article::class, '--all' => true, '--actions' => 'index,restore'])
            ->expectsOutputToContain('Unknown action [restore]')
            ->assertFailed();
    }

    public function test_contract_is_opt_in(): void
    {
        $this->artisan('make:layers', ['model' => Post::class, '--all' => true, '--dry-run' => true])
            ->doesntExpectOutputToContain('PostRepositoryInterface')
            ->assertSuccessful();

        $command = $this->artisan('make:layers', ['model' => Post::class, '--contract' => true, '--dry-run' => true])
            ->expectsOutputToContain('app/Repositories/Contracts/PostRepositoryInterface.php')
            ->expectsOutputToContain('app/Repositories/PostRepository.php');

        // Laravel 12+ binds with #[Bind]; Laravel 11 gets the binding in a service provider.
        class_exists(\Illuminate\Container\Attributes\Bind::class)
            ? $command->doesntExpectOutputToContain('RepositoryServiceProvider')
            : $command->expectsOutputToContain('RepositoryServiceProvider');

        $command->assertSuccessful();
    }

    public function test_several_models_in_dependency_order_link_each_other(): void
    {
        Schema::create('users', fn (Table $t) => $t->id());
        Schema::create('articles', function (Table $table) {
            $table->id();
            $table->foreignId('user_id')->constrained();
            $table->softDeletes();
        });
        Schema::create('comments', function (Table $table) {
            $table->id();
            $table->foreignId('article_id')->constrained();
        });

        // None of the related DTOs / resources exist yet: they are generated in the same run.
        $this->artisan('make:layers', ['model' => [Comment::class, Article::class, User::class], '--output' => true, '--resource' => true, '--dry-run' => true])
            ->expectsOutputToContain(User::class)
            ->expectsOutputToContain('UserData.php')
            ->expectsOutputToContain('ArticleData.php')
            ->expectsOutputToContain('CommentResource.php')
            ->doesntExpectOutputToContain('skipped')
            ->assertSuccessful();

        $output = \Illuminate\Support\Facades\Artisan::call('make:layers', ['model' => [Comment::class, Article::class, User::class], '--output' => true, '--dry-run' => true, '-v' => true]);
        $text = \Illuminate\Support\Facades\Artisan::output();

        // belongsTo first: User, then Article, then Comment.
        $this->assertLessThan(strpos($text, Article::class."\n"), strpos($text, User::class."\n"));
        $this->assertLessThan(strpos($text, Comment::class."\n"), strpos($text, Article::class."\n"));
        $this->assertStringContainsString('public CommentData|Optional|null $latestComment;', $text);   // Article -> Comment
        $this->assertStringContainsString('public ArticleData|Optional|null $article;', $text);         // Comment -> Article
    }

    public function test_all_models_discovers_eloquent_models(): void
    {
        $dir = sys_get_temp_dir().'/layers-app-'.bin2hex(random_bytes(4));
        $ns = 'DiscoveredApp'.bin2hex(random_bytes(4));
        mkdir($dir.'/Models/Blog', 0777, true);
        file_put_contents($dir.'/Models/Note.php', "<?php\nnamespace {$ns}\\Models;\nclass Note extends \\Illuminate\\Database\\Eloquent\\Model {}\n");
        file_put_contents($dir.'/Models/Blog/Tag.php', "<?php\nnamespace {$ns}\\Models\\Blog;\nclass Tag extends \\Illuminate\\Database\\Eloquent\\Model {}\n");
        file_put_contents($dir.'/Models/Base.php', "<?php\nnamespace {$ns}\\Models;\nabstract class Base extends \\Illuminate\\Database\\Eloquent\\Model {}\n");
        file_put_contents($dir.'/Models/Helper.php', "<?php\nnamespace {$ns}\\Models;\nclass Helper {}\n");

        spl_autoload_register(function (string $class) use ($ns, $dir) {
            if (str_starts_with($class, $ns.'\\')) {
                $path = $dir.'/'.str_replace('\\', '/', substr($class, strlen($ns) + 1)).'.php';

                if (is_file($path)) {
                    require_once $path;
                }
            }
        });

        Schema::create('notes', fn (Table $t) => $t->id());
        Schema::create('tags', fn (Table $t) => $t->id());

        $this->app->useAppPath($dir);
        $namespace = new \ReflectionProperty($this->app, 'namespace');
        $namespace->setValue($this->app, $ns.'\\');
        config(['layers.layers.output.namespace' => $ns.'\\Data\\Output']);

        try {
            $this->artisan('make:layers', ['--all-models' => true, '--output' => true, '--dry-run' => true])
                ->expectsOutputToContain("{$ns}\\Models\\Blog\\Tag")
                ->expectsOutputToContain("{$ns}\\Models\\Note")
                ->doesntExpectOutputToContain('Base')
                ->doesntExpectOutputToContain('Helper')
                ->assertSuccessful();
        } finally {
            (new \Illuminate\Filesystem\Filesystem)->deleteDirectory($dir);
        }
    }

    public function test_model_or_all_models_is_required(): void
    {
        $this->artisan('make:layers', ['--output' => true])
            ->expectsOutputToContain('Pass a model (make:layers Post) or --all-models.')
            ->assertFailed();
    }

    public function test_unknown_action_fails(): void
    {
        $this->artisan('make:layers', ['model' => Post::class, '--all' => true, '--actions' => 'index,publish'])
            ->expectsOutputToContain('Unknown action [publish]')
            ->assertFailed();
    }
}
