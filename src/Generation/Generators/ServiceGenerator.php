<?php

declare(strict_types=1);

namespace Toshkq93\LaravelLayers\Generation\Generators;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Toshkq93\LaravelLayers\Generation\Action;
use Toshkq93\LaravelLayers\Generation\Blueprint;
use Toshkq93\LaravelLayers\Generation\Imports;
use Toshkq93\LaravelLayers\Generation\Layer;

/**
 * Business logic. Works only with its own repository and DTOs.
 */
class ServiceGenerator extends Generator
{
    public function layer(): Layer
    {
        return Layer::Service;
    }

    public function generate(Blueprint $blueprint): array
    {
        $naming = $blueprint->naming;
        $namespace = $naming->namespace(Layer::Service);
        $imports = new Imports($namespace);
        $hasRepository = $blueprint->has(Layer::Repository);

        $dependencies = $hasRepository ? ['repository' => $imports->add($naming->fqcn(Layer::Repository))] : [];
        $output = $imports->add($naming->fqcn(Layer::Output));

        $methods = $this->methods($blueprint, function (Action $action) use ($blueprint, $naming, $imports, $output, $hasRepository) {
            if ($action === Action::Index) {
                $imports->add(LengthAwarePaginator::class);
            }

            return [
                'input' => $imports->add($naming->fqcn(Layer::Input, $action)),
                'output' => $output,
                'call' => $hasRepository ? $this->repositoryCall($action, $blueprint) : $this->notImplemented($imports),
            ];
        });

        $contents = $this->stubs->render('service', [
            'namespace' => $namespace,
            'class' => $naming->class(Layer::Service),
            'constructor' => $this->constructor($dependencies),
            'methods' => $methods,
            'imports' => $imports->render(),
        ]);

        return [$this->file($blueprint, $contents)];
    }
}
