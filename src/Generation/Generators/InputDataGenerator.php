<?php

declare(strict_types=1);

namespace Toshkq93\LaravelLayers\Generation\Generators;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Gate;
use Spatie\LaravelData\Attributes\FromRouteParameter;
use Spatie\LaravelData\Attributes\Hidden;
use Spatie\LaravelData\Attributes\Validation\Date;
use Spatie\LaravelData\Attributes\Validation\Exists;
use Spatie\LaravelData\Attributes\Validation\In;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Attributes\Validation\Numeric;
use Spatie\LaravelData\Attributes\Validation\Unique;
use Spatie\LaravelData\Attributes\Validation\Uuid;
use Spatie\LaravelData\Optional;
use Spatie\LaravelData\Support\Validation\References\RouteParameterReference;
use Toshkq93\LaravelLayers\Generation\Action;
use Toshkq93\LaravelLayers\Generation\Blueprint;
use Toshkq93\LaravelLayers\Generation\Imports;
use Toshkq93\LaravelLayers\Generation\Layer;
use Toshkq93\LaravelLayers\Schema\Field;

/**
 * One input DTO per action:
 *  - Index:   page / per_page
 *  - Show:    id from the route
 *  - Store:   writable fields; required unless nullable or defaulted in DB
 *  - Update:  id from the route + every writable field optional (partial update)
 *  - Destroy: id from the route
 * With a gate available, authorize() checks the matching policy ability.
 */
class InputDataGenerator extends Generator
{
    public function layer(): Layer
    {
        return Layer::Input;
    }

    public function generate(Blueprint $blueprint): array
    {
        $files = [];

        foreach ($blueprint->actions as $action) {
            $namespace = $blueprint->naming->namespace(Layer::Input);
            $imports = new Imports($namespace);
            $extends = $imports->add($blueprint->naming->extends(Layer::Input));

            $blocks = match ($action) {
                Action::Index => $this->pagination($blueprint, $imports),
                Action::Show, Action::Destroy => [$this->id($blueprint, $imports)],
                Action::Store => $this->fields($blueprint, $imports, partial: false),
                Action::Update => [$this->id($blueprint, $imports), ...$this->fields($blueprint, $imports, partial: true)],
            };

            if ($blueprint->has(Layer::Policy)) {
                $blocks[] = $this->authorize($blueprint, $action, $imports);
            }

            $attributes = $this->classAttributes($blueprint, $imports);

            $contents = $this->stubs->render('input', [
                'namespace' => $namespace,
                'imports' => $imports->render(),
                'attributes' => $attributes,
                'class' => $blueprint->naming->class(Layer::Input, $action),
                'extends' => $extends,
                'body' => implode("\n\n", array_filter($blocks)),
            ]);

            $files[] = $this->file($blueprint, $contents, $action);
        }

        return $files;
    }

    /** @return list<string> */
    private function pagination(Blueprint $blueprint, Imports $imports): array
    {
        $min = $imports->add(Min::class);
        $max = $imports->add(Max::class);

        [$perPage, $mapping] = $this->mappedProperty($blueprint, 'per_page', $imports);

        return [
            $this->property(["{$min}(1)"], 'int', 'page', '1'),
            $this->property([...$mapping, "{$min}(1)", "{$max}(100)"], 'int', $perPage, '15'),
        ];
    }

    /**
     * The key comes from the route ({post}) and is hidden from toArray(),
     * so it never ends up in the attributes passed to create()/update().
     * With a custom route key (getRouteKeyName()) it holds that column's
     * value, and the repository looks records up by it ($findBy).
     */
    private function id(Blueprint $blueprint, Imports $imports): string
    {
        $attributes = [
            $imports->add(FromRouteParameter::class)."('{$blueprint->routeParameter()}')",
            $imports->add(Hidden::class),
        ];

        return $this->property($attributes, $blueprint->routeKeyType(), 'id');
    }

    /** @return list<string> */
    private function fields(Blueprint $blueprint, Imports $imports, bool $partial): array
    {
        $properties = [];

        foreach ($blueprint->model->writableFields() as $field) {
            $optional = $partial || ! $field->isRequiredOnCreate() && ! $field->nullable;
            [$name, $mapping] = $this->mappedProperty($blueprint, $field->name, $imports);

            $properties[] = $this->property(
                [...$mapping, ...$this->rules($blueprint, $field, $imports, update: $partial)],
                $this->type($field, $imports, $optional),
                $name,
            );
        }

        return $properties;
    }

    /**
     * Validation beyond what laravel-data infers from the type
     * (required / nullable / sometimes, integer, boolean, array, enum).
     *
     * @return list<string>
     */
    private function rules(Blueprint $blueprint, Field $field, Imports $imports, bool $update): array
    {
        $rules = [];

        if ($field->maxLength !== null) {
            $rules[] = $imports->add(Max::class)."({$field->maxLength})";
        }

        if ($field->allowed !== []) {
            $rules[] = $imports->add(In::class).'('.$this->export($field->allowed).')';
        }

        $format = match ($field->format) {
            Field::FORMAT_UUID => Uuid::class,
            Field::FORMAT_DATE, Field::FORMAT_DATETIME => Date::class,
            Field::FORMAT_NUMERIC => Numeric::class,
            default => null,
        };

        if ($format !== null) {
            $rules[] = $imports->add($format);
        }

        if ($field->references !== null) {
            $rules[] = $imports->add(Exists::class)."('{$field->references['table']}', '{$field->references['column']}')";
        }

        if ($field->unique) {
            $rules[] = $this->unique($blueprint, $field, $imports, $update);
        }

        return $rules;
    }

    /**
     * Store: Unique('posts', 'slug').
     * Update: the record being updated may keep its own value, so its key
     * (taken from the route) is ignored.
     * Soft-deleted rows are not excluded: the DB index still counts them.
     */
    private function unique(Blueprint $blueprint, Field $field, Imports $imports, bool $update): string
    {
        $table = $blueprint->model->table;
        $rule = $imports->add(Unique::class)."('{$table}', '{$field->name}'";

        if ($update) {
            $reference = $imports->add(RouteParameterReference::class);
            // The route holds the route key (slug), so the row is matched by that column.
            $rule .= ", ignore: new {$reference}('{$blueprint->routeParameter()}'), ignoreColumn: '{$blueprint->model->routeKey()}'";
        }

        return $rule.')';
    }

    private function type(Field $field, Imports $imports, bool $optional): string
    {
        if ($field->type === 'mixed') {
            return 'mixed';
        }

        // Dates arrive as strings and are validated with #[Date]; Eloquent casts them on save.
        $type = match (true) {
            ltrim($field->type, '\\') === CarbonInterface::class => 'string',
            $field->isClassType() => $imports->add($field->type),
            default => $field->type,
        };

        if (! $optional) {
            return $field->nullable ? '?'.$type : $type;
        }

        return implode('|', array_filter([$type, $imports->add(Optional::class), $field->nullable ? 'null' : null]));
    }

    private function authorize(Blueprint $blueprint, Action $action, Imports $imports): string
    {
        $model = $imports->add($blueprint->model->class);

        // No injected Request: older laravel-data 4.x releases call authorize() without the
        // container, so the route key is read through the request() helper instead.
        $arguments = $action->needsId()
            ? "[{$model}::class, request()->route('{$blueprint->routeParameter()}')]"
            : "{$model}::class";

        return rtrim($this->stubs->render('input.authorize', [
            // Kept for published stubs; authorize() takes no parameters.
            'parameters' => '',
            'gate' => $imports->add(Gate::class),
            'ability' => $action->ability(),
            'arguments' => $arguments,
        ]));
    }

    /**
     * @param  list<string>  $attributes
     */
    private function property(array $attributes, string $type, string $name, ?string $default = null): string
    {
        $lines = [];

        if ($attributes !== []) {
            $lines[] = '    #['.implode(', ', $attributes).']';
        }

        $lines[] = "    public {$type} \${$name}".($default !== null ? " = {$default}" : '').';';

        return implode("\n", $lines);
    }

    /** @param list<string> $values */
    private function export(array $values): string
    {
        return '['.implode(', ', array_map(fn (string $v) => "'".addcslashes($v, "'\\")."'", $values)).']';
    }
}
