<?php

declare(strict_types=1);

namespace Toshkq93\LaravelLayers\Generation\Generators;

use DateTimeInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Toshkq93\LaravelLayers\Generation\Blueprint;
use Toshkq93\LaravelLayers\Generation\GeneratedFile;
use Toshkq93\LaravelLayers\Generation\Imports;
use Toshkq93\LaravelLayers\Generation\Layer;
use Toshkq93\LaravelLayers\Http\Resources\DataResource;

/**
 * API resource around the output DTO: the response contract, field by field.
 *
 * Keys are the column names (snake_case), values are read from the DTO.
 * Dates are formatted like laravel-data does (DATE_ATOM), so the JSON is
 * the same as when the DTO itself is returned. Relations go through the
 * related model's resource and appear only when loaded.
 */
class ResourceGenerator extends Generator
{
    public function layer(): Layer
    {
        return Layer::Resource;
    }

    public function generate(Blueprint $blueprint): array
    {
        $naming = $blueprint->naming;
        $namespace = $naming->namespace(Layer::Resource);
        $imports = new Imports($namespace);
        $imports->add(Request::class);

        $fields = [];

        foreach ($blueprint->model->readableFields() as $field) {
            $value = '$this->'.$naming->property($field->name);

            if ($field->isClassType() && is_a($field->type, DateTimeInterface::class, true)) {
                $value .= ($field->nullable ? '?->' : '->').'format(DATE_ATOM)';
            }

            $fields[] = "            '{$field->name}' => {$value},";
        }

        $notes = [];

        foreach ($blueprint->model->relations as $relation) {
            $output = $naming->outputFor($relation->related);
            $resource = $naming->resourceFor($relation->related);
            $self = $output === $naming->fqcn(Layer::Output);

            // The output DTO has no such property: nothing to return.
            if (! $self && ! class_exists($output)) {
                continue;
            }

            if (! $self && ! class_exists($resource)) {
                $model = class_basename($relation->related);
                $notes[] = "Relation [{$relation->name}] skipped in the resource: {$resource} does not exist. Run `php artisan make:layers {$model} --resource`, then regenerate this resource with --resource --force.";

                continue;
            }

            $property = $naming->camelProperties() ? $relation->name : Str::snake($relation->name);
            $class = $self ? $naming->class(Layer::Resource) : $imports->add($resource);

            $fields[] = "            '".Str::snake($relation->name)."' => \$this->whenLoadedData(\$this->{$property}, {$class}::class),";
        }

        $contents = $this->stubs->render('resource', [
            'namespace' => $namespace,
            'class' => $naming->class(Layer::Resource),
            'extends' => $imports->add($naming->extends(Layer::Resource) ?? DataResource::class),
            'output' => $imports->add($naming->fqcn(Layer::Output)),
            'fields' => implode("\n", $fields),
            'imports' => $imports->render(),
        ]);

        $file = $this->file($blueprint, $contents);

        return [new GeneratedFile($file->layer, $file->path, $file->contents, $file->class, $notes)];
    }
}
