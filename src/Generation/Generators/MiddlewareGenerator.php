<?php

declare(strict_types=1);

namespace Toshkq93\LaravelLayers\Generation\Generators;

use Toshkq93\LaravelLayers\Generation\Blueprint;
use Toshkq93\LaravelLayers\Generation\Layer;

class MiddlewareGenerator extends Generator
{
    public function layer(): Layer
    {
        return Layer::Middleware;
    }

    public function generate(Blueprint $blueprint): array
    {
        $contents = $this->stubs->render('middleware', [
            'namespace' => $blueprint->naming->namespace(Layer::Middleware),
            'class' => $blueprint->naming->class(Layer::Middleware),
            'model' => $blueprint->model->name(),
        ]);

        return [$this->file($blueprint, $contents)];
    }
}
