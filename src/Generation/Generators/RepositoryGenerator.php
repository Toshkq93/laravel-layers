<?php

declare(strict_types=1);

namespace Toshkq93\LaravelLayers\Generation\Generators;

use Toshkq93\LaravelLayers\Generation\Blueprint;
use Toshkq93\LaravelLayers\Generation\Imports;
use Toshkq93\LaravelLayers\Generation\Layer;

/**
 * paginate(), find(), findOrFail(), create(), update() and delete() are
 * inherited from the base repository, so the generated class only names its
 * model and output DTO.
 */
class RepositoryGenerator extends Generator
{
    public function layer(): Layer
    {
        return Layer::Repository;
    }

    /**
     * Empty by default (no surprise queries), listing what can be loaded.
     */
    private function with(Blueprint $blueprint): string
    {
        $relations = array_keys($blueprint->model->relations);

        if ($relations === []) {
            return '';
        }

        $available = "'".implode("', '", $relations)."'";

        return "    /**\n     * Relations loaded by every query and returned in the output DTO.\n     * Available: {$available}.\n     */\n    protected array \$with = [];";
    }

    /**
     * Records addressed by a route key other than the primary key (slug)
     * are looked up by that column.
     */
    private function findBy(Blueprint $blueprint): string
    {
        $column = $blueprint->model->routeKeyName;

        if ($column === null) {
            return '';
        }

        return "    /**\n     * The routes address records by the model's route key.\n     */\n    protected ?string \$findBy = '{$column}';";
    }

    public function generate(Blueprint $blueprint): array
    {
        $naming = $blueprint->naming;
        $namespace = $naming->namespace(Layer::Repository);
        $imports = new Imports($namespace);

        $output = $imports->add($naming->fqcn(Layer::Output));

        $contents = $this->stubs->render('repository', [
            'namespace' => $namespace,
            'model' => $imports->add($blueprint->model->class),
            'output' => $output,
            'extends' => $imports->add($naming->extends(Layer::Repository)),
            'class' => $naming->class(Layer::Repository),
            // CRUD comes from the base repository. Kept empty for stubs published before 0.2.
            'methods' => '',
            'with' => implode("\n\n", array_filter([$this->findBy($blueprint), $this->with($blueprint)])),
            'imports' => $imports->render(),
        ]);

        return [$this->file($blueprint, $contents)];
    }
}
