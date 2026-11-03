<?php

declare(strict_types=1);

namespace Toshkq93\LaravelLayers\Tests\Feature;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Toshkq93\LaravelLayers\Schema\RelationInspector;
use Toshkq93\LaravelLayers\Tests\Fixtures\Article;
use Toshkq93\LaravelLayers\Tests\Fixtures\Comment;
use Toshkq93\LaravelLayers\Tests\Fixtures\User;
use Toshkq93\LaravelLayers\Tests\TestCase;

class RelationInspectorTest extends TestCase
{
    public function test_it_finds_relations(): void
    {
        $relations = (new RelationInspector)->inspect(new Article);

        // morphTo, non-relation methods, scopes and SoftDeletes methods are not listed.
        $this->assertSame(['author', 'comments', 'latestComment'], array_keys($relations));

        $this->assertSame(User::class, $relations['author']->related);
        $this->assertFalse($relations['author']->many);
        $this->assertSame(BelongsTo::class, $relations['author']->type);

        // Found without a return type.
        $this->assertSame(Comment::class, $relations['comments']->related);
        $this->assertTrue($relations['comments']->many);
        $this->assertSame(HasMany::class, $relations['comments']->type);

        $this->assertFalse($relations['latestComment']->many);
        $this->assertSame(HasOne::class, $relations['latestComment']->type);
    }

    public function test_framework_trait_relations_are_ignored(): void
    {
        // Notifiable::notifications() is a relation, but not one of the model's own.
        $this->assertSame(['articles'], array_keys((new RelationInspector)->inspect(new User)));
    }
}
