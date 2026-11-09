<?php

declare(strict_types=1);

namespace Toshkq93\LaravelLayers\Generation\Generators;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Toshkq93\LaravelLayers\Generation\Action;
use Toshkq93\LaravelLayers\Generation\Blueprint;
use Toshkq93\LaravelLayers\Generation\Imports;
use Toshkq93\LaravelLayers\Generation\Layer;

/**
 * Thin controller: receives a validated (and authorized) input DTO and
 * delegates to the service — or straight to the repository when the
 * model has no service. The result goes out through the API resource,
 * or as the output DTO when the model has no resource.
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
                fn (Action $action) => $this->crudCall('service', $action, $blueprint),
            ],
            $blueprint->has(Layer::Repository) => [
                ['repository' => $imports->add($naming->fqcn(Layer::Repository))],
                fn (Action $action) => $this->crudCall('repository', $action, $blueprint),
            ],
            default => [[], fn () => $this->notImplemented($imports)],
        };

        $withResource = $blueprint->has(Layer::Resource);
        $resource = $withResource ? $imports->add($naming->fqcn(Layer::Resource)) : '';
        $output = $withResource ? '' : $imports->add($naming->fqcn(Layer::Output));

        $methods = $this->methods($blueprint, function (Action $action) use ($naming, $imports, $output, $resource, $withResource, $call) {
            match (true) {
                $action === Action::Destroy => $imports->add(Response::class),
                ! $withResource && $action === Action::Index => $imports->add(LengthAwarePaginator::class),
                $withResource && $action === Action::Index => $imports->add(AnonymousResourceCollection::class),
                $withResource && $action === Action::Store => [$imports->add(JsonResponse::class), $imports->add(Response::class)],
                default => null,
            };

            return [
                'input' => $imports->add($naming->fqcn(Layer::Input, $action)),
                'output' => $output,
                'resource' => $resource,
                'call' => $call($action),
            ];
        }, fn (Action $action) => $withResource ? "controller.resource.{$action->value}" : "controller.{$action->value}");

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
