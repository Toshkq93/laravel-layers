<?php

declare(strict_types=1);

namespace Toshkq93\LaravelLayers\Tests\Feature;

use Illuminate\Database\Schema\Blueprint as Table;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Toshkq93\LaravelLayers\Generation\Action;
use Toshkq93\LaravelLayers\Generation\Blueprint;
use Toshkq93\LaravelLayers\Generation\Layer;
use Toshkq93\LaravelLayers\Generation\Naming;
use Toshkq93\LaravelLayers\Schema\ModelInspector;
use Toshkq93\LaravelLayers\Tests\Fixtures\Post;
use Toshkq93\LaravelLayers\Tests\Fixtures\User;
use Toshkq93\LaravelLayers\Tests\TestCase;

class RelatedFactoryTest extends TestCase
{
    private const NS = 'RelatedFactoryFixtures';

    public static function setUpBeforeClass(): void
    {
        $ns = self::NS;
        $user = User::class;

        eval(<<<PHP
            namespace {$ns};

            use Illuminate\\Database\\Eloquent\\Factories\\Factory;

            // Builds a model of the "users" table: usable.
            class UserFactory extends Factory
            {
                protected \$model = \\{$user}::class;
                public function definition(): array { return []; }
            }

            // No \$model: resolved by convention to App\\Models\\Ghost, which does not exist.
            class GhostFactory extends Factory
            {
                public function definition(): array { return []; }
            }

            // Builds a User although its name says "teams".
            class TeamFactory extends Factory
            {
                protected \$model = \\{$user}::class;
                public function definition(): array { return []; }
            }

            // Not a factory at all.
            class TagFactory
            {
            }
            PHP);
    }

    public static function tables(): array
    {
        return [
            'factory of the referenced model' => ['users', self::NS.'\\UserFactory'],
            'model class does not exist' => ['ghosts', null],
            'model uses another table' => ['teams', null],
            'class is not a factory' => ['tags', null],
            'no factory class' => ['comments', null],
        ];
    }

    #[DataProvider('tables')]
    public function test_related_factory(string $table, ?string $expected): void
    {
        Schema::create('posts', fn (Table $t) => $t->id());

        $layers = config('layers.layers');
        $layers['factory']['namespace'] = self::NS;

        $definition = (new ModelInspector)->inspect(Post::class);
        $blueprint = new Blueprint($definition, new Naming($definition, $layers, 'App\\', '/tmp/app'), [Layer::Factory], Action::cases());

        $this->assertSame($expected, $blueprint->relatedFactory($table));
    }
}
