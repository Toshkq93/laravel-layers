<?php

declare(strict_types=1);

namespace Toshkq93\LaravelLayers\Generation\Generators;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Response;
use Toshkq93\LaravelLayers\Generation\Action;
use Toshkq93\LaravelLayers\Generation\Blueprint;
use Toshkq93\LaravelLayers\Generation\Imports;
use Toshkq93\LaravelLayers\Generation\Layer;

/**
 * Thin controller: receives a validated (and authorized) input DTO and
 * delegates to the service — or straight to the repository when the
 * model has no service.
 */
class ControllerGenerator extends Generator
{
    public function layer(): Layer
    {
        return Layer::Controller;
    }

    public function generate(Blueprint $blueprint): array
    {
        $naming = $blueprint->naming;
        $namespace = $naming->namespace(Layer::Controller);
        $imports = new Imports($namespace);

        [$dependencies, $call] = match (true) {
            $blueprint->has(Layer::Service) => [
                ['service' => $imports->add($naming->fqcn(Layer::Service))],
                fn (Action $action) => "\$this->service->{$action->value}(\$data)",
            ],
            $blueprint->has(Layer::Repository) => [
                ['repository' => $imports->add($naming->fqcn(Layer::Repository))],
                fn (Action $action) => $this->repositoryCall($action, $blueprint),
            ],
            default => [[], fn () => $this->notImplemented($imports)],
        };

        $output = $imports->add($naming->fqcn(Layer::Output));

        $methods = $this->methods($blueprint, function (Action $action) use ($naming, $imports, $output, $call) {
            match ($action) {
                Action::Index => $imports->add(LengthAwarePaginator::class),
                Action::Destroy => $imports->add(Response::class),
                default => null,
            };

            return [
                'input' => $imports->add($naming->fqcn(Layer::Input, $action)),
                'output' => $output,
                'call' => $call($action),
            ];
        });

        $extends = $naming->extends(Layer::Controller);
        $extends = $extends && class_exists($extends) ? ' extends '.$imports->add($extends) : '';

        $contents = $this->stubs->render('controller', [
            'namespace' => $namespace,
            'class' => $naming->class(Layer::Controller),
            'extends' => $extends,
            'constructor' => $this->constructor($dependencies),
            'methods' => $methods,
            'imports' => $imports->render(),
        ]);

        return [$this->file($blueprint, $contents)];
    }
}
