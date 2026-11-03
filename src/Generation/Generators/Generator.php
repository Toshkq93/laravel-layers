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
     * Renders one method stub per action: "{layer}.{action}".
     *
     * @param  callable(Action): array<string, string>  $replace
     */
    protected function methods(Blueprint $blueprint, callable $replace): string
    {
        $methods = [];

        foreach ($blueprint->actions as $action) {
            $methods[] = rtrim($this->stubs->render($this->layer()->value.'.'.$action->value, $replace($action)));
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
     * Expression that performs an action on the repository:
     * shared by the service and by a controller without a service.
     */
    protected function repositoryCall(Action $action, Blueprint $blueprint): string
    {
        $perPage = $blueprint->naming->property('per_page');

        return match ($action) {
            Action::Index => "\$this->repository->paginate(\$data->{$perPage}, \$data->page)",
            Action::Show => '$this->repository->findOrFail($data->id)',
            Action::Store => '$this->repository->create($data)',
            Action::Update => '$this->repository->update($data->id, $data)',
            Action::Destroy => '$this->repository->delete($data->id)',
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
