<?php

declare(strict_types=1);

namespace Toshkq93\LaravelLayers\Tests\Feature;

use Illuminate\Database\Schema\Blueprint as Table;
use Illuminate\Support\Facades\Schema;
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

    public function test_unknown_model_fails(): void
    {
        $this->artisan('make:layers', ['model' => 'Nope', '--all' => true])
            ->expectsOutputToContain('Model [Nope] not found.')
            ->assertFailed();
    }

    public function test_unknown_action_fails(): void
    {
        $this->artisan('make:layers', ['model' => Post::class, '--all' => true, '--actions' => 'index,publish'])
            ->expectsOutputToContain('Unknown action [publish]')
            ->assertFailed();
    }
}
