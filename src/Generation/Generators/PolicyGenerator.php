<?php

declare(strict_types=1);

namespace Toshkq93\LaravelLayers\Generation\Generators;

use Illuminate\Contracts\Auth\Authenticatable;
use Toshkq93\LaravelLayers\Generation\Blueprint;
use Toshkq93\LaravelLayers\Generation\Imports;
use Toshkq93\LaravelLayers\Generation\Layer;

/**
 * Policy whose abilities are checked from the input DTOs' authorize().
 * The user is nullable so guests reach the policy instead of being denied
 * up front; tighten the checks as needed.
 */
class PolicyGenerator extends Generator
{
    public function layer(): Layer
    {
        return Layer::Policy;
    }

    public function generate(Blueprint $blueprint): array
    {
        $naming = $blueprint->naming;
        $namespace = $naming->namespace(Layer::Policy);
        $imports = new Imports($namespace);
        $imports->add(Authenticatable::class);

        $methods = $this->methods($blueprint, fn () => ['keyType' => $blueprint->routeKeyType()]);

        $contents = $this->stubs->render('policy', [
            'namespace' => $namespace,
            'class' => $naming->class(Layer::Policy),
            'model' => $blueprint->model->name(),
            'methods' => $methods,
            'imports' => $imports->render(),
        ]);

        return [$this->file($blueprint, $contents)];
    }
}
