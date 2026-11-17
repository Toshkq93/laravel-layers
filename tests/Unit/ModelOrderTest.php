<?php

declare(strict_types=1);

namespace Toshkq93\LaravelLayers\Tests\Unit;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use PHPUnit\Framework\TestCase;
use Toshkq93\LaravelLayers\Generation\ModelOrder;
use Toshkq93\LaravelLayers\Schema\ModelDefinition;
use Toshkq93\LaravelLayers\Schema\Relation;

class ModelOrderTest extends TestCase
{
    /** @param array<string, array{0: string, 1: class-string}> $relations name => [related, type] */
    private function model(string $class, array $relations = []): ModelDefinition
    {
        return new ModelDefinition(
            class: $class,
            table: strtolower($class).'s',
            keyName: 'id',
            keyType: 'int',
            fields: [],
            relations: array_map(fn (array $r) => new Relation('r', $r[0], $r[1] === HasMany::class, $r[1]), $relations),
        );
    }

    /** @param list<ModelDefinition> $models */
    private function classes(array $models): array
    {
        return array_map(fn (ModelDefinition $m) => $m->class, ModelOrder::sort($models));
    }

    public function test_models_come_after_the_models_they_belong_to(): void
    {
        $comment = $this->model('Comment', ['post' => ['Post', BelongsTo::class], 'user' => ['User', BelongsTo::class]]);
        $post = $this->model('Post', ['user' => ['User', BelongsTo::class], 'comments' => ['Comment', HasMany::class]]);
        $user = $this->model('User', ['posts' => ['Post', HasMany::class]]);

        $this->assertSame(['User', 'Post', 'Comment'], $this->classes([$comment, $post, $user]));
    }

    public function test_unrelated_models_keep_their_order_and_cycles_end(): void
    {
        $a = $this->model('A', ['b' => ['B', BelongsTo::class]]);
        $b = $this->model('B', ['a' => ['A', BelongsTo::class]]);
        $c = $this->model('C', ['outside' => ['Elsewhere', BelongsTo::class]]);

        $this->assertSame(['C', 'B', 'A'], $this->classes([$c, $a, $b]));
    }
}
