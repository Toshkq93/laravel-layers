<?php

declare(strict_types=1);

namespace Toshkq93\LaravelLayers\Generation\Generators;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Toshkq93\LaravelLayers\Generation\Action;
use Toshkq93\LaravelLayers\Generation\Blueprint;
use Toshkq93\LaravelLayers\Generation\Imports;
use Toshkq93\LaravelLayers\Generation\Layer;
use Toshkq93\LaravelLayers\Schema\Field;

/**
 * Feature tests for the generated API, one or two per action.
 * Records and payloads come from the model factory.
 */
class TestGenerator extends Generator
{
    public function layer(): Layer
    {
        return Layer::Test;
    }

    public function generate(Blueprint $blueprint): array
    {
        $naming = $blueprint->naming;
        $model = $blueprint->model;
        $namespace = $naming->namespace(Layer::Test);
        $imports = new Imports($namespace);
        $imports->add(RefreshDatabase::class);

        $factory = $imports->add($naming->fqcn(Layer::Factory));
        $sample = $this->sampleField($blueprint);
        $required = array_keys(array_filter($model->writableFields(), fn (Field $f) => $f->isRequiredOnCreate()));
        $unique = array_key_first(array_filter($model->writableFields(), fn (Field $f) => $f->unique));

        $methods = $this->methods($blueprint, function (Action $action) use ($blueprint, $model, $imports, $factory, $sample, $required, $unique) {
            $replace = [
                'factory' => $factory,
                'route' => $blueprint->routeName($action),
                'variable' => $model->variable(),
                'singular' => Str::snake($model->name()),
                'plural' => Str::snake(Str::pluralStudly($model->name())),
                'table' => $model->table,
                'key' => $model->keyName,
                'missingKey' => $blueprint->keyType() === 'int' ? '0' : "'missing'",
            ];

            $uniqueTest = fn (string $stub) => $unique === null
                ? ''
                : rtrim($this->stubs->render($stub, $replace + ['field' => $unique]));

            return $replace + match ($action) {
                Action::Store => [
                    'assertions' => $sample ? "\n            ->assertJsonPath('{$sample}', \$payload['{$sample}'])" : '',
                    'validation' => $required === [] ? '' : rtrim($this->stubs->render('test.store.validation', $replace + [
                        'fields' => "'".implode("', '", $required)."'",
                    ])),
                    'unique' => $uniqueTest('test.store.unique'),
                ],
                Action::Update => [
                    'payload' => $sample
                        ? $imports->add(Arr::class)."::only({$factory}::new()->raw(), ['{$sample}'])"
                        : '[]',
                    'assertions' => $sample ? "\n            ->assertJsonPath('{$sample}', \$payload['{$sample}'])" : '',
                    'database' => $sample
                        ? "\n\n        \$this->assertDatabaseHas('{$model->table}', [\n            '{$model->keyName}' => \${$model->variable()}->getKey(),\n            '{$sample}' => \$payload['{$sample}'],\n        ]);"
                        : '',
                    'unique' => $uniqueTest('test.update.unique'),
                ],
                Action::Destroy => [
                    'deleted' => $model->softDeletes ? 'assertSoftDeleted' : 'assertModelMissing',
                ],
                default => [],
            };
        });

        $extends = $imports->add($naming->extends(Layer::Test) ?? \Illuminate\Foundation\Testing\TestCase::class);

        $contents = $this->stubs->render('test', [
            'namespace' => $namespace,
            'imports' => $imports->render(),
            'class' => $naming->class(Layer::Test),
            'extends' => $extends,
            'model' => $model->name(),
            'methods' => $methods,
        ]);

        return [$this->file($blueprint, $contents)];
    }

    /**
     * A plain string column to assert on: present in the request and the
     * response and compared as is (no enum, date or numeric conversion).
     */
    private function sampleField(Blueprint $blueprint): ?string
    {
        foreach ($blueprint->model->writableFields() as $field) {
            if ($field->type === 'string' && $field->isReadable() && $field->format === null
                && $field->allowed === [] && $field->references === null && $field->cast !== 'hashed'
                && ! str_contains($field->name, 'password')) {
                return $field->name;
            }
        }

        return null;
    }
}
