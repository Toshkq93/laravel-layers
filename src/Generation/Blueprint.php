<?php

declare(strict_types=1);

namespace Toshkq93\LaravelLayers\Generation;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Throwable;
use Toshkq93\LaravelLayers\Schema\ModelDefinition;

/**
 * What to generate: model, selected layers and actions.
 *
 * A layer is "available" when it is generated in this run or its class
 * already exists — so layers can be generated in several runs and the
 * generated code still wires itself together.
 */
final class Blueprint
{
    /**
     * @param  list<Layer>  $layers
     * @param  list<Action>  $actions
     */
    public function __construct(
        public readonly ModelDefinition $model,
        public readonly Naming $naming,
        public readonly array $layers,
        public readonly array $actions,
    ) {}

    public function generates(Layer $layer): bool
    {
        return in_array($layer, $this->layers, true);
    }

    public function has(Layer $layer, ?Action $action = null): bool
    {
        if ($this->generates($layer) && ($action === null || in_array($action, $this->actions, true))) {
            return true;
        }

        if (! $layer->isClass()) {
            return false;
        }

        if ($layer->isPerAction()) {
            $actions = $action ? [$action] : $this->actions;

            foreach ($actions as $a) {
                if (! class_exists($this->naming->fqcn($layer, $a))) {
                    return false;
                }
            }

            return true;
        }

        return class_exists($this->naming->fqcn($layer));
    }

    public function keyType(): string
    {
        return in_array($this->model->keyType, ['int', 'integer'], true) ? 'int' : 'string';
    }

    /** Route parameter name: {post} */
    public function routeParameter(): string
    {
        return $this->model->variable();
    }

    /** posts.index */
    public function routeName(Action $action): string
    {
        return $this->model->plural().'.'.$action->value;
    }

    /**
     * Factory of the table a foreign key points to (users -> UserFactory).
     *
     * Used only when it really builds a model of that table: the factory
     * class exists, extends Factory, its model class exists, is an Eloquent
     * model and uses the referenced table. Otherwise the generated code
     * would fail only when the tests run.
     *
     * @return class-string<Factory>|null
     */
    public function relatedFactory(string $table): ?string
    {
        $namespace = $this->naming->namespace(Layer::Factory);
        $class = $namespace.'\\'.Str::studly(Str::singular($table)).$this->naming->suffix(Layer::Factory);

        if (! class_exists($class) || ! is_subclass_of($class, Factory::class)) {
            return null;
        }

        try {
            $model = $class::new()->modelName();
        } catch (Throwable) {
            return null;
        }

        if (! class_exists($model) || ! is_subclass_of($model, Model::class)) {
            return null;
        }

        return (new $model)->getTable() === $table ? $class : null;
    }

    /**
     * Adds layers the selected ones cannot compile without:
     * controller / service / repository work with input and output DTOs.
     */
    public function withImplicitLayers(): self
    {
        $layers = $this->layers;
        $needsData = $this->generates(Layer::Controller) || $this->generates(Layer::Service) || $this->generates(Layer::Repository);

        $needs = [];

        if ($needsData) {
            array_push($needs, Layer::Input, Layer::Output);
        }

        // Tests create their records with the model factory.
        if ($this->generates(Layer::Test)) {
            $needs[] = Layer::Factory;
        }

        foreach ($needs as $layer) {
            if (! in_array($layer, $layers, true) && ! $this->has($layer)) {
                $layers[] = $layer;
            }
        }

        $ordered = array_values(array_filter(Layer::cases(), fn (Layer $l) => in_array($l, $layers, true)));

        return new self($this->model, $this->naming, $ordered, $this->actions);
    }
}
