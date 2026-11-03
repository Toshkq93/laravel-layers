<?php

declare(strict_types=1);

namespace Toshkq93\LaravelLayers\Tests\Feature;

use Carbon\CarbonInterface;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use RuntimeException;
use stdClass;
use Toshkq93\LaravelLayers\Schema\ModelInspector;
use Toshkq93\LaravelLayers\Tests\Fixtures\Article;
use Toshkq93\LaravelLayers\Tests\Fixtures\OpenArticle;
use Toshkq93\LaravelLayers\Tests\Fixtures\PartlyGuardedArticle;
use Toshkq93\LaravelLayers\Tests\Fixtures\PostStatus;
use Toshkq93\LaravelLayers\Tests\TestCase;

class ModelInspectorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function (Blueprint $table) {
            $table->id();
        });

        Schema::create('articles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained();
            $table->string('title', 200);
            $table->text('body')->nullable();
            $table->string('status');
            $table->json('meta')->nullable();
            $table->boolean('published')->default(false);
            $table->timestamp('published_at')->nullable();
            $table->string('secret');
            $table->string('slug')->unique();
            $table->timestamps();
            $table->unique(['user_id', 'title']);
            $table->softDeletes();
        });
    }

    public function test_it_describes_the_model(): void
    {
        $definition = (new ModelInspector)->inspect(Article::class);

        $this->assertSame('articles', $definition->table);
        $this->assertSame('id', $definition->keyName);
        $this->assertSame('Article', $definition->name());
        $this->assertSame('article', $definition->variable());
        $this->assertSame('articles', $definition->plural());

        $f = $definition->fields;

        $this->assertTrue($f['id']->primary);
        $this->assertSame('int', $f['id']->type);

        $this->assertSame(['table' => 'users', 'column' => 'id'], $f['user_id']->references);
        $this->assertSame('\\'.PostStatus::class, $f['status']->type);
        $this->assertTrue($f['status']->isEnumClass());
        $this->assertSame('array', $f['meta']->type);
        $this->assertTrue($f['meta']->nullable);
        $this->assertSame('bool', $f['published']->type);
        $this->assertTrue($f['published']->hasDefault);
        $this->assertFalse($f['published']->isRequiredOnCreate());
        $this->assertTrue($f['title']->isRequiredOnCreate());
        $this->assertSame('\\'.CarbonInterface::class, $f['published_at']->type);
        $this->assertSame('\\'.CarbonInterface::class, $f['created_at']->type);
        $this->assertTrue($f['deleted_at']->timestamp);
        $this->assertTrue($f['secret']->hidden);
        $this->assertTrue($f['slug']->unique);
        $this->assertFalse($f['user_id']->unique);   // only part of a composite unique index
        $this->assertFalse($f['title']->unique);
        $this->assertFalse($f['id']->unique);        // primary key is not treated as a unique rule
    }

    public function test_writable_and_readable_fields(): void
    {
        $definition = (new ModelInspector)->inspect(Article::class);

        $this->assertSame(
            ['user_id', 'title', 'body', 'status', 'meta', 'published', 'published_at', 'secret', 'slug'],
            array_keys($definition->writableFields()),
        );

        $readable = array_keys($definition->readableFields());
        $this->assertContains('id', $readable);
        $this->assertContains('created_at', $readable);
        $this->assertNotContains('secret', $readable);
    }

    public function test_unguarded_model_exposes_every_column_except_keys_and_timestamps(): void
    {
        $definition = (new ModelInspector)->inspect(OpenArticle::class);

        $this->assertSame(
            ['user_id', 'title', 'body', 'status', 'meta', 'published', 'published_at', 'secret', 'slug'],
            array_keys($definition->writableFields()),
        );
    }

    public function test_read_only_columns_come_from_config(): void
    {
        config(['layers.read_only_columns' => ['secret', 'status']]);

        $definition = app(ModelInspector::class)->inspect(OpenArticle::class);
        $writable = array_keys($definition->writableFields());

        $this->assertNotContains('secret', $writable);
        $this->assertNotContains('status', $writable);
        $this->assertContains('deleted_at', $writable);          // no longer in the list
        $this->assertArrayHasKey('status', $definition->readableFields()); // still returned
    }

    public function test_guarded_columns_are_not_writable(): void
    {
        $writable = array_keys((new ModelInspector)->inspect(PartlyGuardedArticle::class)->writableFields());

        $this->assertNotContains('secret', $writable);
        $this->assertContains('title', $writable);
    }

    public function test_default_model_without_fillable_exposes_every_column(): void
    {
        // Plain Model: no $fillable, $guarded = ['*'] (Laravel default).
        $model = new class extends \Illuminate\Database\Eloquent\Model
        {
            protected $table = 'articles';
        };

        $writable = array_keys((new ModelInspector)->inspect($model::class)->writableFields());

        $this->assertContains('title', $writable);
        $this->assertNotContains('id', $writable);
        $this->assertNotContains('created_at', $writable);
    }

    public function test_it_rejects_non_models(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new ModelInspector)->inspect(stdClass::class);
    }

    public function test_it_requires_the_table(): void
    {
        Schema::drop('articles');

        $this->expectException(RuntimeException::class);

        (new ModelInspector)->inspect(Article::class);
    }
}
