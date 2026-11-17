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
use Toshkq93\LaravelLayers\Generation\ProviderWriter;
use Toshkq93\LaravelLayers\Generation\StubRenderer;
use Toshkq93\LaravelLayers\Schema\ModelInspector;
use Toshkq93\LaravelLayers\Tests\Fixtures\Comment;
use Toshkq93\LaravelLayers\Tests\Fixtures\Post;
use Toshkq93\LaravelLayers\Tests\TestCase;

/**
 * Binding of the repository contract on Laravel without #[Bind].
 */
class ProviderWriterTest extends TestCase
{
    private string $dir;

    private ProviderWriter $writer;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('posts', fn (Table $t) => $t->id());
        Schema::create('comments', fn (Table $t) => $t->id());

        $this->dir = sys_get_temp_dir().'/layers-provider-'.bin2hex(random_bytes(4));
        mkdir($this->dir.'/bootstrap', 0777, true);
        file_put_contents($this->dir.'/bootstrap/providers.php', "<?php\n\nreturn [\n    App\\Providers\\AppServiceProvider::class,\n];\n");
        $this->writer = new ProviderWriter(new Filesystem, new StubRenderer);
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->dir);

        parent::tearDown();
    }

    /** @param class-string $model */
    private function blueprint(string $model): Blueprint
    {
        $definition = (new ModelInspector)->inspect($model);
        $naming = new Naming($definition, config('layers.layers'), 'App\\', $this->dir.'/app');

        return new Blueprint($definition, $naming, [Layer::Contract], Action::cases(), bindAttribute: false);
    }

    private function write(string $model): array
    {
        return $this->writer->write(
            $this->blueprint($model),
            $this->dir.'/app/Providers/RepositoryServiceProvider.php',
            'App\\Providers\\RepositoryServiceProvider',
            $this->dir.'/bootstrap/providers.php',
        );
    }

    public function test_creates_the_provider_binds_contracts_and_registers_it(): void
    {
        $this->assertSame(['status' => ProviderWriter::CREATED, 'registered' => true], $this->write(Post::class));
        $this->assertSame(['status' => ProviderWriter::ADDED, 'registered' => false], $this->write(Comment::class));
        $this->assertSame(['status' => ProviderWriter::SKIPPED, 'registered' => false], $this->write(Post::class));

        $provider = file_get_contents($this->dir.'/app/Providers/RepositoryServiceProvider.php');

        $this->assertStringContainsString(<<<'PHP'
                public array $bindings = [
                    \App\Repositories\Contracts\PostRepositoryInterface::class => \App\Repositories\PostRepository::class,
                    \App\Repositories\Contracts\CommentRepositoryInterface::class => \App\Repositories\CommentRepository::class,
                    // layers:bindings
                ];
            PHP, $provider);
        $this->assertStringContainsString("namespace App\\Providers;\n", $provider);
        $this->assertStringContainsString('class RepositoryServiceProvider extends ServiceProvider', $provider);

        exec(escapeshellarg(PHP_BINARY).' -l '.escapeshellarg($this->dir.'/app/Providers/RepositoryServiceProvider.php'), $output, $code);
        $this->assertSame(0, $code);

        $this->assertSame(
            ['App\\Providers\\AppServiceProvider', 'App\\Providers\\RepositoryServiceProvider'],
            require $this->dir.'/bootstrap/providers.php',
        );
    }

    public function test_provider_without_marker_is_left_alone(): void
    {
        mkdir($this->dir.'/app/Providers', 0777, true);
        $path = $this->dir.'/app/Providers/RepositoryServiceProvider.php';
        file_put_contents($path, "<?php\n\nclass RepositoryServiceProvider {}\n");

        $this->assertSame(['status' => ProviderWriter::NO_MARKER, 'registered' => false], $this->write(Post::class));
        $this->assertSame("<?php\n\nclass RepositoryServiceProvider {}\n", file_get_contents($path));
    }
}
