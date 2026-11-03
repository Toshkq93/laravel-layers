<?php

declare(strict_types=1);

namespace Toshkq93\LaravelLayers\Tests\Feature;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Toshkq93\LaravelLayers\Tests\Fixtures\Post;
use Toshkq93\LaravelLayers\Tests\Fixtures\PostInputData;
use Toshkq93\LaravelLayers\Tests\Fixtures\PostOutputData;
use Toshkq93\LaravelLayers\Tests\Fixtures\PostRepository;
use Toshkq93\LaravelLayers\Tests\TestCase;

class EloquentRepositoryTest extends TestCase
{
    private PostRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('posts', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('body')->nullable();
            $table->boolean('published')->default(false);
            $table->timestamps();
        });

        $this->repository = new PostRepository;
    }

    public function test_create_returns_output_dto(): void
    {
        $post = $this->repository->create(PostInputData::from(['title' => 'Hello', 'body' => null, 'published' => true]));

        $this->assertInstanceOf(PostOutputData::class, $post);
        $this->assertSame('Hello', $post->title);
        $this->assertTrue($post->published);
        $this->assertDatabaseHas('posts', ['id' => $post->id, 'title' => 'Hello']);
    }

    public function test_find_returns_dto_or_null(): void
    {
        $id = Post::create(['title' => 'A', 'published' => false])->id;

        $this->assertInstanceOf(PostOutputData::class, $this->repository->find($id));
        $this->assertNull($this->repository->find(999));
    }

    public function test_collection_contains_only_dtos(): void
    {
        Post::create(['title' => 'A', 'published' => true]);
        Post::create(['title' => 'B', 'published' => false]);
        Post::create(['title' => 'C', 'published' => true]);

        $posts = $this->repository->published();

        $this->assertSame(['A', 'C'], $posts->pluck('title')->all());
        $this->assertContainsOnlyInstancesOf(PostOutputData::class, $posts);
    }

    public function test_paginator_contains_only_dtos(): void
    {
        foreach (range(1, 5) as $i) {
            Post::create(['title' => "Post {$i}", 'published' => true]);
        }

        $page = $this->repository->paginate(2);

        $this->assertSame(5, $page->total());
        $this->assertCount(2, $page->items());
        $this->assertContainsOnlyInstancesOf(PostOutputData::class, $page->items());
    }

    public function test_update_and_delete(): void
    {
        $id = Post::create(['title' => 'Old', 'published' => false])->id;

        $updated = $this->repository->update($id, PostInputData::from(['title' => 'New', 'body' => 'text', 'published' => true]));

        $this->assertSame('New', $updated->title);

        $this->assertTrue($this->repository->delete($id));
        $this->assertFalse($this->repository->delete($id));
        $this->assertDatabaseMissing('posts', ['id' => $id]);
    }

    public function test_missing_record_throws_not_found(): void
    {
        $this->expectException(ModelNotFoundException::class);

        $this->repository->findOrFail(999);
    }

    public function test_update_missing_record_throws_not_found(): void
    {
        $this->expectException(ModelNotFoundException::class);

        $this->repository->update(999, PostInputData::from(['title' => 'X', 'body' => null]));
    }
}
