<?php

declare(strict_types=1);

namespace Toshkq93\LaravelLayers\Generation\Generators;

use Toshkq93\LaravelLayers\Generation\Blueprint;
use Toshkq93\LaravelLayers\Generation\Imports;
use Toshkq93\LaravelLayers\Generation\Layer;
use Toshkq93\LaravelLayers\Services\CrudService;

/**
 * Business logic. The CRUD methods are inherited from the base service,
 * which delegates them to the model's repository; the generated class
 * only passes the typed repository to it.
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

        $contents = $this->stubs->render('service', [
            'namespace' => $namespace,
            'class' => $naming->class(Layer::Service),
            'extends' => $imports->add($naming->extends(Layer::Service) ?? CrudService::class),
            'repository' => $imports->add($naming->fqcn(Layer::Repository)),
            'output' => $imports->add($naming->fqcn(Layer::Output)),
            'imports' => $imports->render(),
        ]);

        return [$this->file($blueprint, $contents)];
    }
}
