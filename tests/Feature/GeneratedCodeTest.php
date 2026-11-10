<?php

declare(strict_types=1);

namespace Toshkq93\LaravelLayers\Tests\Feature;

use Illuminate\Database\Schema\Blueprint as Table;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Toshkq93\LaravelLayers\Generation\Action;
use Toshkq93\LaravelLayers\Generation\Blueprint;
use Toshkq93\LaravelLayers\Generation\Layer;
use Toshkq93\LaravelLayers\Generation\LayerGenerator;
use Toshkq93\LaravelLayers\Generation\Naming;
use Toshkq93\LaravelLayers\Generation\StubRenderer;
use Toshkq93\LaravelLayers\Schema\ModelInspector;
use Toshkq93\LaravelLayers\Tests\Fixtures\Article;
use Toshkq93\LaravelLayers\Tests\Fixtures\Comment;
use Toshkq93\LaravelLayers\Tests\Fixtures\SluggedArticle;
use Toshkq93\LaravelLayers\Tests\Fixtures\User;
use Toshkq93\LaravelLayers\Tests\TestCase;

/**
 * Generates every layer for a model into a temp dir, loads the classes and
 * drives the API over HTTP: proves the generated code actually works.
 */
class GeneratedCodeTest extends TestCase
{
    private static string $dir;

    private static string $namespace;

    protected function setUp(): void
    {
        parent::setUp();

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
            // camel 'code2fa' does not snake back to 'code_2fa': needs its own #[MapName].
            $table->string('code_2fa')->nullable();
            $table->string('slug')->nullable()->unique();
            $table->timestamps();
            // Composite: cannot be a single-property rule, must be ignored.
            $table->unique(['user_id', 'title']);
            $table->softDeletes();
        });

        Schema::create('comments', function (Table $table) {
            $table->id();
            $table->foreignId('article_id')->constrained();
            $table->string('body');
            $table->timestamps();
        });

        \DB::table('users')->insert(['id' => 1]);
    }

    protected function tearDown(): void
    {
        if (isset(self::$dir)) {
            (new Filesystem)->deleteDirectory(self::$dir);
        }

        parent::tearDown();
    }

    /**
     * @param  list<Layer>  $layers
     * @return array{blueprint: Blueprint, files: list<\Toshkq93\LaravelLayers\Generation\GeneratedFile>}
     */
    private function generate(array $layers, string $properties = Naming::PROPERTIES_CAMEL, array $outputsFirst = [], ?callable $patch = null, array $relatedLayers = [Layer::Output, Layer::Resource], string $main = Article::class): array
    {
        // A fresh namespace per test: generated classes cannot be redeclared.
        self::$namespace = 'Generated'.bin2hex(random_bytes(4));
        self::$dir = sys_get_temp_dir().'/'.self::$namespace;
        $ns = self::$namespace;

        $config = [
            'input' => ['namespace' => "{$ns}\\Data\\Input", 'suffix' => 'Data', 'extends' => \Toshkq93\LaravelLayers\Data\InputData::class],
            'output' => ['namespace' => "{$ns}\\Data\\Output", 'suffix' => 'Data', 'extends' => \Toshkq93\LaravelLayers\Data\OutputData::class],
            'resource' => ['namespace' => "{$ns}\\Http\\Resources", 'suffix' => 'Resource', 'extends' => \Toshkq93\LaravelLayers\Http\Resources\DataResource::class],
            'controller' => ['namespace' => "{$ns}\\Http\\Controllers", 'suffix' => 'Controller'],
            'service' => ['namespace' => "{$ns}\\Services", 'suffix' => 'Service'],
            'repository' => ['namespace' => "{$ns}\\Repositories", 'suffix' => 'Repository', 'extends' => \Toshkq93\LaravelLayers\Repositories\EloquentRepository::class],
            'middleware' => ['namespace' => "{$ns}\\Http\\Middleware", 'suffix' => 'Middleware'],
            'policy' => ['namespace' => "{$ns}\\Policies", 'suffix' => 'Policy'],
            'factory' => ['namespace' => "{$ns}\\Factories", 'suffix' => 'Factory', 'path' => self::$dir.'/Factories', 'extends' => \Illuminate\Database\Eloquent\Factories\Factory::class],
            'test' => ['namespace' => "{$ns}\\Tests", 'suffix' => 'ApiTest', 'path' => self::$dir.'/Tests', 'extends' => TestCase::class],
        ];

        $dir = self::$dir;
        spl_autoload_register(function (string $class) use ($ns, $dir) {
            if (str_starts_with($class, $ns.'\\')) {
                $path = $dir.'/'.str_replace('\\', '/', substr($class, strlen($ns) + 1)).'.php';

                if (is_file($path)) {
                    require $path;
                }
            }
        });

        // An existing factory for the related table: user_id should use it.
        $fs = new Filesystem;
        $fs->ensureDirectoryExists($dir.'/Factories');
        $fs->put($dir.'/Factories/UserFactory.php', "<?php\nnamespace {$ns}\\Factories;\n\nclass UserFactory extends \\Illuminate\\Database\\Eloquent\\Factories\\Factory\n{\n    protected \$model = \\".User::class."::class;\n\n    public function definition(): array\n    {\n        return [];\n    }\n}\n");

        // Output DTOs of related models, generated before the article's.
        foreach ([...$outputsFirst, $main] as $model) {
            $definition = (new ModelInspector)->inspect($model);
            $naming = new Naming($definition, $config, $ns.'\\', self::$dir, $properties);
            $blueprint = new Blueprint($definition, $naming, $model === $main ? $layers : $relatedLayers, Action::cases());
            $blueprint = $blueprint->withImplicitLayers();
            $files = (new LayerGenerator(new StubRenderer))->generate($blueprint);

            foreach ($files as $file) {
                $fs->ensureDirectoryExists(dirname($file->path));
                $fs->put($file->path, $patch ? $patch($file) : $file->contents);
            }
        }

        return ['blueprint' => $blueprint, 'files' => $files];
    }

    private function routes(): void
    {
        $controller = self::$namespace.'\\Http\\Controllers\\ArticleController';

        // The same registration RouteWriter generates.
        Route::apiResource('articles', $controller);
    }

    public static function propertyNaming(): array
    {
        return ['camelCase properties' => [Naming::PROPERTIES_CAMEL], 'snake_case properties' => [Naming::PROPERTIES_SNAKE]];
    }

    /**
     * The HTTP contract is the same in both modes: snake_case in and out.
     */
    #[DataProvider('propertyNaming')]
    public function test_full_stack_crud(string $properties): void
    {
        ['files' => $files] = $this->generate(Layer::cases(), $properties);

        foreach ($files as $file) {
            $this->assertSame(0, $this->lint($file->path), "Syntax error in {$file->path}");
        }

        Gate::policy(Article::class, self::$namespace.'\\Policies\\ArticlePolicy');
        $this->routes();

        $this->postJson('articles', ['title' => '', 'user_id' => 999, 'status' => 'nope'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['title', 'user_id', 'status'])
            ->assertJsonMissingValidationErrors(['secret', 'published', 'body']); // DB default / nullable

        // SQLite does not keep varchar lengths, so #[Max] is covered by TypeMapperTest (MySQL / PostgreSQL types).
        $this->postJson('articles', ['title' => 'x', 'user_id' => 1, 'published_at' => 'not a date'])
            ->assertJsonValidationErrors(['published_at']);

        $id = $this->postJson('articles', ['title' => 'Hello', 'user_id' => 1, 'body' => null, 'secret' => 'a', 'meta' => null, 'published_at' => null, 'code_2fa' => 'abc'])
            ->assertCreated()
            ->assertJsonPath('data.user_id', 1)
            ->assertJsonPath('data.code_2fa', 'abc')
            ->assertJsonPath('data.status', 'draft')     // DB default, re-read after insert
            ->assertJsonPath('data.published', false)
            ->assertJsonMissingPath('data.secret')       // $hidden
            ->json('data.id');

        $this->getJson("articles/{$id}")->assertOk()->assertJsonPath('data.title', 'Hello');
        $this->getJson('articles/999')->assertNotFound();

        $this->patchJson("articles/{$id}", ['status' => 'published', 'published_at' => '2026-01-02 03:04:05'])
            ->assertOk()
            ->assertJsonPath('data.published_at', '2026-01-02T03:04:05+00:00')
            ->assertJsonPath('data.status', 'published')
            ->assertJsonPath('data.title', 'Hello');     // untouched: partial update

        $this->getJson('articles?per_page=500')->assertJsonValidationErrors(['per_page']);
        $this->getJson('articles?per_page=10')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $id);

        $this->deleteJson("articles/{$id}")->assertNoContent();
        $this->getJson("articles/{$id}")->assertNotFound();
    }

    public function test_gate_denies_through_input_dto(): void
    {
        $this->generate(Layer::cases());
        Gate::policy(Article::class, self::$namespace.'\\Policies\\ArticlePolicy');
        Gate::before(fn (?\Illuminate\Contracts\Auth\Authenticatable $user) => false);
        $this->routes();

        $this->getJson('articles')->assertForbidden();
        $this->getJson('articles/1')->assertForbidden();
        $this->postJson('articles', [])->assertForbidden();
    }

    public function test_controller_uses_repository_when_there_is_no_service(): void
    {
        ['blueprint' => $blueprint, 'files' => $files] = $this->generate([Layer::Controller, Layer::Repository]);

        // Input and output DTOs are added implicitly.
        $this->assertTrue($blueprint->generates(Layer::Input));
        $this->assertTrue($blueprint->generates(Layer::Output));
        $this->assertFalse($blueprint->generates(Layer::Service));

        $controller = $this->contents($files, Layer::Controller);
        $this->assertStringContainsString('private readonly ArticleRepository $repository', $controller);
        // No resource: the output DTO is returned as is.
        $this->assertStringContainsString('public function show(ShowArticleData $data): ArticleData', $controller);
        $this->assertStringContainsString('$this->repository->create($data)', $controller);

        // No gate: DTOs have no authorize().
        $this->assertStringNotContainsString('authorize', $this->contents($files, Layer::Input));

        $this->routes();
        $this->postJson('articles', ['title' => 'Hi', 'user_id' => 1, 'body' => null, 'secret' => 'a', 'meta' => null, 'published_at' => null])
            ->assertCreated();
    }

    public function test_repository_inherits_crud_from_the_base(): void
    {
        ['files' => $files] = $this->generate([Layer::Repository]);

        $repository = $this->contents($files, Layer::Repository);
        $this->assertStringContainsString('class ArticleRepository extends EloquentRepository', $repository);
        $this->assertStringContainsString('return Article::class;', $repository);

        foreach (['paginate', 'findOrFail', 'create', 'update', 'delete'] as $method) {
            $this->assertStringNotContainsString("function {$method}(", $repository);
        }

        // No unused imports left from the old per-action methods.
        $this->assertStringNotContainsString('LengthAwarePaginator', $repository);
        $this->assertStringNotContainsString('StoreArticleData', $repository);
    }

    public function test_camel_case_properties_are_mapped(): void
    {
        ['files' => $files] = $this->generate([Layer::Input, Layer::Output]);

        $store = $this->contents($files, Layer::Input);
        $this->assertStringContainsString("#[MapName(SnakeCaseMapper::class)]\nclass StoreArticleData", $store);
        $this->assertStringContainsString('public int $userId;', $store);
        $this->assertStringContainsString("#[MapName('code_2fa')]\n".'    public ?string $code2fa;', $store);
        $this->assertStringContainsString('public int $perPage = 15;', $store);

        $output = $this->contents($files, Layer::Output);
        $this->assertStringContainsString('public ?CarbonInterface $createdAt;', $output);
    }

    public function test_snake_case_properties_keep_column_names(): void
    {
        ['files' => $files] = $this->generate([Layer::Input, Layer::Output], Naming::PROPERTIES_SNAKE);

        $all = $this->contents($files, Layer::Input).$this->contents($files, Layer::Output);
        $this->assertStringNotContainsString('MapName', $all);
        $this->assertStringContainsString('public int $user_id;', $all);
        $this->assertStringContainsString('public int $per_page = 15;', $all);
    }

    #[DataProvider('propertyNaming')]
    public function test_factory_payload_passes_generated_validation(string $properties): void
    {
        ['files' => $files] = $this->generate(Layer::cases(), $properties);
        Gate::policy(Article::class, self::$namespace.'\\Policies\\ArticlePolicy');
        $this->routes();
        $factory = self::$namespace.'\\Factories\\ArticleFactory';

        $factoryCode = $this->contents($files, Layer::Factory);
        $this->assertStringContainsString("'user_id' => UserFactory::new(),", $factoryCode);
        $this->assertStringContainsString("'status' => fake()->randomElement(PostStatus::cases()),", $factoryCode);
        $this->assertStringContainsString("'title' => fake()->sentence(3),", $factoryCode);

        // create(): every NOT NULL column gets a value, the FK factory makes the user.
        $article = $factory::new()->create();
        $this->assertInstanceOf(Article::class, $article);
        $this->assertNotNull($article->user_id);

        // raw(): a valid request payload for the generated input DTO.
        foreach (range(1, 5) as $_) {
            $this->postJson('articles', $factory::new()->raw())->assertCreated();
        }
    }

    public function test_generated_tests_cover_every_action(): void
    {
        ['files' => $files] = $this->generate(Layer::cases());
        $test = $this->contents($files, Layer::Test);

        foreach ([
            'test_index_returns_paginated_articles', 'test_index_validates_per_page',
            'test_show_returns_article', 'test_show_returns_404_for_missing_article',
            'test_store_creates_article', 'test_store_validates_required_fields',
            'test_update_changes_article', 'test_update_returns_404_for_missing_article',
            'test_destroy_deletes_article', 'test_destroy_returns_404_for_missing_article',
        ] as $method) {
            $this->assertStringContainsString("public function {$method}(): void", $test);
        }

        $this->assertStringContainsString("route('articles.index', ['per_page' => 2])", $test);
        $this->assertStringContainsString("->assertJsonValidationErrors(['user_id', 'title']);", $test);
        $this->assertStringContainsString("->assertJsonPath('data.title', \$payload['title'])", $test);
        $this->assertStringContainsString("->assertJsonPath('meta.total', 3);", $test);
        $this->assertStringContainsString("->assertJsonPath('data.id', \$article->getKey());", $test);
        $this->assertStringContainsString('$this->assertSoftDeleted($article);', $test);   // Article uses SoftDeletes
        $this->assertSame(0, $this->lint(collect($files)->firstWhere('layer', Layer::Test)->path));
    }

    public function test_records_addressed_by_route_key(): void
    {
        ['files' => $files] = $this->generate(Layer::cases(), main: SluggedArticle::class);
        Gate::policy(SluggedArticle::class, self::$namespace.'\\Policies\\SluggedArticlePolicy');
        Route::apiResource('articles', self::$namespace.'\\Http\\Controllers\\SluggedArticleController')
            ->parameters(['articles' => 'sluggedArticle']);

        $this->assertStringContainsString("protected ?string \$findBy = 'slug';", $this->contents($files, Layer::Repository));
        $input = $this->contents($files, Layer::Input);
        $this->assertStringContainsString("#[FromRouteParameter('sluggedArticle'), Hidden]\n    public string \$id;", $input);
        $this->assertStringContainsString("ignore: new RouteParameterReference('sluggedArticle'), ignoreColumn: 'slug')", $input);
        $this->assertStringContainsString('string $id): bool', $this->contents($files, Layer::Policy));
        $test = $this->contents($files, Layer::Test);
        $this->assertStringContainsString('$sluggedArticle->getRouteKey()', $test);
        $this->assertStringContainsString("route('slugged-articles.show', 'missing')", $test);

        foreach ($files as $file) {
            $this->assertSame(0, $this->lint($file->path), "Syntax error in {$file->path}");
        }

        \DB::table('articles')->insert(['user_id' => 1, 'title' => 'Hello', 'slug' => 'hello']);
        \DB::table('articles')->insert(['user_id' => 1, 'title' => 'Other', 'slug' => 'other']);

        $this->getJson('articles/hello')->assertOk()->assertJsonPath('data.title', 'Hello');
        $this->getJson('articles/1')->assertNotFound();

        // Keeping its own slug passes the unique rule, taking another one does not.
        $this->patchJson('articles/hello', ['slug' => 'hello', 'title' => 'New'])->assertOk()->assertJsonPath('data.title', 'New');
        $this->patchJson('articles/hello', ['slug' => 'other'])->assertJsonValidationErrors(['slug']);

        $this->deleteJson('articles/hello')->assertNoContent();
        $this->deleteJson('articles/hello')->assertNotFound();
        $this->assertSoftDeleted('articles', ['slug' => 'hello']);
    }

    public function test_unique_columns(): void
    {
        ['files' => $files] = $this->generate(Layer::cases());
        Gate::policy(Article::class, self::$namespace.'\\Policies\\ArticlePolicy');
        $this->routes();

        $input = $this->contents($files, Layer::Input);
        $this->assertStringContainsString("#[Unique('articles', 'slug')]\n    public ?string \$slug;", $input);
        $this->assertStringContainsString("#[Unique('articles', 'slug', ignore: new RouteParameterReference('article'), ignoreColumn: 'id')]", $input);
        $this->assertSame(2, substr_count($input, 'Unique('));   // composite (user_id, title) is skipped

        $this->assertStringContainsString("'slug' => fake()->unique()->", $this->contents($files, Layer::Factory));
        $this->assertStringContainsString('test_store_rejects_duplicate_slug', $this->contents($files, Layer::Test));

        $payload = ['user_id' => 1, 'body' => null, 'meta' => null, 'published_at' => null, 'slug' => 'taken'];
        $first = $this->postJson('articles', ['title' => 'A'] + $payload)->assertCreated()->json('data.id');
        $second = $this->postJson('articles', ['title' => 'B'] + ['slug' => 'free'] + $payload)->assertCreated()->json('data.id');

        $this->postJson('articles', ['title' => 'C'] + $payload)->assertJsonValidationErrors(['slug']);
        $this->patchJson("articles/{$first}", ['slug' => 'taken'])->assertOk();                       // its own value
        $this->patchJson("articles/{$second}", ['slug' => 'taken'])->assertJsonValidationErrors(['slug']);

        // Soft-deleted rows still hold the value in the unique index.
        $this->deleteJson("articles/{$first}")->assertNoContent();
        $this->postJson('articles', ['title' => 'D'] + $payload)->assertJsonValidationErrors(['slug']);
    }

    #[DataProvider('propertyNaming')]
    public function test_relations_are_returned_only_when_loaded(string $properties): void
    {
        // The repository eager-loads two relations; latestComment stays unloaded.
        $withRelations = fn ($file) => $file->layer === Layer::Repository
            ? str_replace('protected array $with = [];', "protected array \$with = ['author', 'comments'];", $file->contents)
            : $file->contents;

        ['files' => $files] = $this->generate(Layer::cases(), $properties, [User::class, Comment::class], $withRelations);
        Gate::policy(Article::class, self::$namespace.'\\Policies\\ArticlePolicy');
        $this->routes();

        $output = $this->contents($files, Layer::Output);
        $latest = $properties === Naming::PROPERTIES_CAMEL ? '$latestComment' : '$latest_comment';
        $this->assertStringContainsString('public UserData|Optional|null $author;', $output);
        $this->assertStringContainsString("#[DataCollectionOf(CommentData::class)]\n    public Collection|Optional \$comments;", $output);
        $this->assertStringContainsString("public CommentData|Optional|null {$latest};", $output);
        $this->assertStringNotContainsString('imageable', $output);

        $this->assertStringContainsString("Available: 'author', 'comments', 'latestComment'.", $this->contents($files, Layer::Repository));

        // create(): relations of the new record are loaded too.
        $id = $this->postJson('articles', ['title' => 'A', 'user_id' => 1, 'body' => null, 'meta' => null, 'published_at' => null])
            ->assertCreated()
            ->assertJsonPath('data.author.id', 1)
            ->assertJsonPath('data.comments', [])
            ->json('data.id');

        \DB::table('comments')->insert([
            ['article_id' => $id, 'body' => 'first'],
            ['article_id' => $id, 'body' => 'second'],
        ]);

        $this->getJson("articles/{$id}")
            ->assertOk()
            ->assertJsonPath('data.author.id', 1)
            ->assertJsonPath('data.comments.1.body', 'second')
            ->assertJsonPath('data.comments.0.article_id', $id)         // snake_case inside nested DTOs too
            ->assertJsonMissingPath('data.latest_comment')              // not loaded -> not in the response
            ->assertJsonMissingPath('data.latestComment');

        // update(): a changed foreign key reloads the relation.
        \DB::table('users')->insert(['id' => 2]);
        $this->patchJson("articles/{$id}", ['user_id' => 2])->assertOk()->assertJsonPath('data.author.id', 2);

        // No N+1: the number of queries does not grow with the number of records.
        $queries = function (): int {
            \DB::flushQueryLog();
            \DB::enableQueryLog();
            $this->getJson('articles?per_page=50')->assertOk();

            return count(\DB::getQueryLog());
        };

        $few = $queries();
        foreach (range(1, 5) as $i) {
            $this->postJson('articles', ['title' => "T{$i}", 'user_id' => 1, 'body' => null, 'meta' => null, 'published_at' => null])->assertCreated();
        }
        $this->assertSame($few, $queries());
    }

    public function test_unloaded_relations_are_left_out(): void
    {
        // Default repository: $with = [], nothing is loaded.
        $this->generate(Layer::cases(), Naming::PROPERTIES_CAMEL, [User::class, Comment::class]);
        Gate::policy(Article::class, self::$namespace.'\\Policies\\ArticlePolicy');
        $this->routes();

        $id = $this->postJson('articles', ['title' => 'A', 'user_id' => 1, 'body' => null, 'meta' => null, 'published_at' => null])
            ->assertCreated()
            ->assertJsonMissingPath('data.author')
            ->json('data.id');

        \DB::enableQueryLog();
        $this->getJson("articles/{$id}")
            ->assertOk()
            ->assertJsonPath('data.title', 'A')
            ->assertJsonMissingPath('data.author')
            ->assertJsonMissingPath('data.comments')
            ->assertJsonMissingPath('data.latest_comment');

        // Only the article itself is queried: building the DTO never lazy-loads relations.
        $this->assertCount(1, \DB::getQueryLog());
    }

    public function test_relation_without_output_dto_is_skipped_with_a_note(): void
    {
        ['files' => $files] = $this->generate([Layer::Output]);   // UserData / CommentData do not exist

        $output = collect($files)->firstWhere('layer', Layer::Output);
        $this->assertStringNotContainsString('$author', $output->contents);
        $this->assertCount(3, $output->notes);
        $this->assertStringContainsString('Relation [author] skipped', $output->notes[0]);
        $this->assertStringContainsString('php artisan make:layers User --output', $output->notes[0]);
    }

    #[DataProvider('propertyNaming')]
    public function test_resource_lists_fields_and_relations(string $properties): void
    {
        ['files' => $files] = $this->generate([Layer::Resource], $properties, [User::class, Comment::class]);

        $resource = $this->contents($files, Layer::Resource);
        $camel = $properties === Naming::PROPERTIES_CAMEL;

        $this->assertStringContainsString("@mixin ArticleData\n *\n * @property ArticleData \$resource\n */\nclass ArticleResource extends DataResource", $resource);
        $this->assertStringContainsString("'user_id' => \$this->".($camel ? 'userId' : 'user_id').',', $resource);
        $this->assertStringContainsString("'code_2fa' => \$this->".($camel ? 'code2fa' : 'code_2fa').',', $resource);
        $this->assertStringContainsString("'published_at' => \$this->".($camel ? 'publishedAt' : 'published_at').'?->format(DATE_ATOM),', $resource);
        $this->assertStringNotContainsString("'secret'", $resource);   // $hidden
        $this->assertStringContainsString("'author' => \$this->whenLoadedData(\$this->author, UserResource::class),", $resource);
        $this->assertStringContainsString("'latest_comment' => \$this->whenLoadedData(\$this->".($camel ? 'latestComment' : 'latest_comment').', CommentResource::class),', $resource);
        $this->assertSame(0, $this->lint(collect($files)->firstWhere('layer', Layer::Resource)->path));
    }

    public function test_relation_without_resource_is_skipped_with_a_note(): void
    {
        // UserData / CommentData exist, their resources do not.
        ['files' => $files] = $this->generate([Layer::Resource], Naming::PROPERTIES_CAMEL, [User::class, Comment::class], relatedLayers: [Layer::Output]);

        $resource = collect($files)->firstWhere('layer', Layer::Resource);
        $this->assertStringNotContainsString("'author'", $resource->contents);
        $this->assertStringContainsString('Relation [author] skipped in the resource', $resource->notes[0]);
        $this->assertStringContainsString('php artisan make:layers User --resource', $resource->notes[0]);
    }

    public function test_controller_returns_resources(): void
    {
        ['files' => $files] = $this->generate([Layer::Controller, Layer::Service, Layer::Resource]);

        $controller = $this->contents($files, Layer::Controller);
        $this->assertStringContainsString("public function index(IndexArticleData \$data): AnonymousResourceCollection\n    {\n        return ArticleResource::collection(\$this->service->paginate(", $controller);
        $this->assertStringContainsString("public function show(ShowArticleData \$data): ArticleResource\n    {\n        return ArticleResource::make(\$this->service->findOrFail(\$data->id));", $controller);
        $this->assertStringContainsString("return ArticleResource::make(\$this->service->create(\$data))\n            ->response()\n            ->setStatusCode(Response::HTTP_CREATED);", $controller);
        $this->assertStringNotContainsString('Output\\ArticleData;', $controller);   // the output DTO is not referenced
        $this->assertStringNotContainsString('LengthAwarePaginator', $controller);
    }

    public function test_service_extends_crud_service_and_pulls_in_the_repository(): void
    {
        ['blueprint' => $blueprint, 'files' => $files] = $this->generate([Layer::Service]);

        $this->assertTrue($blueprint->generates(Layer::Repository));
        $this->assertTrue($blueprint->generates(Layer::Output));
        $this->assertFalse($blueprint->generates(Layer::Input));   // the service no longer takes input DTOs

        $service = $this->contents($files, Layer::Service);
        $this->assertStringContainsString("/**\n * @extends CrudService<ArticleRepository, ArticleData>\n */\nclass ArticleService extends CrudService", $service);
        $this->assertStringContainsString("public function __construct(ArticleRepository \$repository)\n    {\n        parent::__construct(\$repository);", $service);
        $this->assertStringNotContainsString('function paginate(', $service);

        foreach ($files as $file) {
            $this->assertSame(0, $this->lint($file->path), "Syntax error in {$file->path}");
        }
    }

    public function test_controller_unpacks_the_dto_for_the_service(): void
    {
        ['files' => $files] = $this->generate([Layer::Controller, Layer::Service]);

        $controller = $this->contents($files, Layer::Controller);
        $this->assertStringContainsString('return $this->service->paginate($data->perPage, $data->page);', $controller);
        $this->assertStringContainsString('return $this->service->findOrFail($data->id);', $controller);
        $this->assertStringContainsString('return $this->service->create($data);', $controller);
        $this->assertStringContainsString('return $this->service->update($data->id, $data);', $controller);
        $this->assertStringContainsString('$this->service->delete($data->id);', $controller);
    }

    /** @param list<\Toshkq93\LaravelLayers\Generation\GeneratedFile> $files */
    private function contents(array $files, Layer $layer): string
    {
        return implode("\n", array_map(fn ($f) => $f->contents, array_filter($files, fn ($f) => $f->layer === $layer)));
    }

    private function lint(string $path): int
    {
        exec(escapeshellarg(PHP_BINARY).' -l '.escapeshellarg($path).' 2>&1', $output, $code);

        return $code;
    }
}
