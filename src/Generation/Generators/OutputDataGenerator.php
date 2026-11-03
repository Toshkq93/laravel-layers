<?php

declare(strict_types=1);

namespace Toshkq93\LaravelLayers\Generation\Generators;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Optional;
use Toshkq93\LaravelLayers\Generation\Blueprint;
use Toshkq93\LaravelLayers\Generation\GeneratedFile;
use Toshkq93\LaravelLayers\Generation\Imports;
use Toshkq93\LaravelLayers\Generation\Layer;
use Toshkq93\LaravelLayers\Schema\Relation;

/**
 * Output DTO: every readable (non-hidden) column, typed after model casts,
 * plus the model's relations, filled only when the relation is loaded
 * (no N+1, no extra queries).
 */
class OutputDataGenerator extends Generator
{
    public function layer(): Layer
    {
        return Layer::Output;
    }

    public function generate(Blueprint $blueprint): array
    {
        $naming = $blueprint->naming;
        $namespace = $naming->namespace(Layer::Output);
        $imports = new Imports($namespace);
        $extends = $imports->add($naming->extends(Layer::Output));

        $properties = [];

        foreach ($blueprint->model->readableFields() as $field) {
            $type = $field->isClassType() ? $imports->add($field->type) : $field->type;

            if ($field->nullable && $type !== 'mixed') {
                $type = '?'.$type;
            }

            [$name, $mapping] = $this->mappedProperty($blueprint, $field->name, $imports);

            $properties[] = ($mapping !== [] ? '    #['.implode(', ', $mapping)."]\n" : '')
                ."    public {$type} \${$name};";
        }

        $notes = [];

        foreach ($blueprint->model->relations as $relation) {
            $output = $naming->outputFor($relation->related);
            $self = $output === $naming->fqcn(Layer::Output);

            if (! $self && ! class_exists($output)) {
                $model = class_basename($relation->related);
                $notes[] = "Relation [{$relation->name}] skipped: {$output} does not exist. Run `php artisan make:layers {$model} --output`, then regenerate this DTO with --output --force.";

                continue;
            }

            $properties[] = $this->relation($blueprint, $relation, $imports->add($output), $imports);
        }

        $attributes = $this->classAttributes($blueprint, $imports);

        $contents = $this->stubs->render('output', [
            'namespace' => $namespace,
            'imports' => $imports->render(),
            'attributes' => $attributes,
            'class' => $naming->class(Layer::Output),
            'extends' => $extends,
            'body' => implode("\n\n", $properties),
        ]);

        $file = $this->file($blueprint, $contents);

        return [new GeneratedFile($file->layer, $file->path, $file->contents, $file->class, $notes)];
    }

    /**
     * Single:  public UserData|Optional|null $author;
     * Many:    #[DataCollectionOf(CommentData::class)] public Collection|Optional $comments;
     *
     * laravel-data's model normalizer returns a relation only when it is
     * loaded (it never lazy-loads), so:
     *  - not loaded           -> Optional -> left out of the JSON;
     *  - loaded               -> nested DTO / collection of DTOs;
     *  - loaded but empty     -> null (belongsTo with a null key) / [].
     *
     * Not #[AutoWhenLoadedLazy]: for nullable properties laravel-data
     * overwrites the lazy placeholder with null, so an unloaded relation
     * would be returned as null instead of being left out.
     */
    private function relation(Blueprint $blueprint, Relation $relation, string $output, Imports $imports): string
    {
        // Relation methods are camelCase; in snake mode the property follows the columns' style.
        // The normalizer looks relations up by both snake and camel name, so no mapping is needed.
        $property = $blueprint->naming->camelProperties() ? $relation->name : Str::snake($relation->name);
        $optional = $imports->add(Optional::class);

        if (! $relation->many) {
            return "    public {$output}|{$optional}|null \${$property};";
        }

        $collection = $imports->add(Collection::class);
        $of = $imports->add(DataCollectionOf::class)."({$output}::class)";

        return "    /** @var {$collection}<int, {$output}>|{$optional} */\n"
            ."    #[{$of}]\n"
            ."    public {$collection}|{$optional} \${$property};";
    }
}
