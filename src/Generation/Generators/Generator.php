<?php

declare(strict_types=1);

namespace Toshkq93\LaravelLayers\Generation\Generators;

use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;
use Toshkq93\LaravelLayers\Generation\Action;
use Toshkq93\LaravelLayers\Generation\Blueprint;
use Toshkq93\LaravelLayers\Generation\GeneratedFile;
use Toshkq93\LaravelLayers\Generation\Imports;
use Toshkq93\LaravelLayers\Generation\Layer;
use Toshkq93\LaravelLayers\Generation\StubRenderer;

abstract class Generator
{
    public function __construct(
        protected readonly StubRenderer $stubs,
    ) {}

    abstract public function layer(): Layer;

    /**
     * @return list<GeneratedFile>
     */
    abstract public function generate(Blueprint $blueprint): array;

    /**
     * Renders one method stub per action: "{layer}.{action}" by default.
     *
     * @param  callable(Action): array<string, string>  $replace
     * @param  (callable(Action): string)|null  $stub  stub name per action
     */
    protected function methods(Blueprint $blueprint, callable $replace, ?callable $stub = null): string
    {
        $methods = [];

        foreach ($blueprint->actions as $action) {
            $name = $stub ? $stub($action) : $this->layer()->value.'.'.$action->value;
            $methods[] = rtrim($this->stubs->render($name, $replace($action)));
        }

        return implode("\n\n", $methods);
    }

    protected function file(Blueprint $blueprint, string $contents, ?Action $action = null): GeneratedFile
    {
        return new GeneratedFile(
            layer: $this->layer(),
            path: $blueprint->naming->path($this->layer(), $action),
            contents: $contents,
            class: $blueprint->naming->fqcn($this->layer(), $action),
        );
    }

    /**
     * #[MapName(SnakeCaseMapper::class)] for DTOs with camelCase properties.
     */
    protected function classAttributes(Blueprint $blueprint, Imports $imports): string
    {
        if (! $blueprint->naming->camelProperties()) {
            return '';
        }

        return '#['.$imports->add(MapName::class).'('.$imports->add(SnakeCaseMapper::class).'::class)]';
    }

    /**
     * Property name for an input / output name, plus the #[MapName('...')]
     * it needs when the class mapper cannot derive the name back.
     *
     * @return array{0: string, 1: list<string>}
     */
    protected function mappedProperty(Blueprint $blueprint, string $name, Imports $imports): array
    {
        $attributes = $blueprint->naming->needsExplicitMapping($name)
            ? [$imports->add(MapName::class)."('{$name}')"]
            : [];

        return [$blueprint->naming->property($name), $attributes];
    }

    /**
     * Expression that performs an action through $this->{$property}.
     * The service and the repository share the CRUD API, so a controller
     * calls either of them the same way.
     */
    protected function crudCall(string $property, Action $action, Blueprint $blueprint): string
    {
        $perPage = $blueprint->naming->property('per_page');

        return "\$this->{$property}->".match ($action) {
            Action::Index => "paginate(\$data->{$perPage}, \$data->page)",
            Action::Show => 'findOrFail($data->id)',
            Action::Store => 'create($data)',
            Action::Update => 'update($data->id, $data)',
            Action::Destroy => 'delete($data->id)',
        };
    }

    protected function notImplemented(Imports $imports): string
    {
        return 'throw new '.$imports->add(\LogicException::class)."('Not implemented.')";
    }

    /**
     * @param  array<string, string>  $dependencies  property => short class name
     */
    protected function constructor(array $dependencies): string
    {
        if ($dependencies === []) {
            return '';
        }

        $lines = array_map(
            fn (string $class, string $property) => "        private readonly {$class} \${$property},",
            $dependencies,
            array_keys($dependencies),
        );

        return "    public function __construct(\n".implode("\n", $lines)."\n    ) {}";
    }
}
