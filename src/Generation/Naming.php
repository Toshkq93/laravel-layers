<?php

declare(strict_types=1);

namespace Toshkq93\LaravelLayers\Generation;

use Illuminate\Support\Str;
use InvalidArgumentException;
use Toshkq93\LaravelLayers\Schema\ModelDefinition;

/**
 * Class names, namespaces and file paths of every layer for one model,
 * derived from config('layers.layers').
 */
final class Naming
{
    public const PROPERTIES_CAMEL = 'camel';

    public const PROPERTIES_SNAKE = 'snake';

    /**
     * @param  array<string, array{namespace: string, suffix?: string, extends?: string|null}>  $layers
     * @param  'camel'|'snake'  $properties
     */
    public function __construct(
        private readonly ModelDefinition $model,
        private readonly array $layers,
        private readonly string $appNamespace,
        private readonly string $appPath,
        private readonly string $properties = self::PROPERTIES_CAMEL,
        private readonly ?string $basePath = null,
    ) {
        if (! in_array($properties, [self::PROPERTIES_CAMEL, self::PROPERTIES_SNAKE], true)) {
            throw new InvalidArgumentException("Unknown property naming [{$properties}]. Use 'camel' or 'snake'.");
        }
    }

    public function camelProperties(): bool
    {
        return $this->properties === self::PROPERTIES_CAMEL;
    }

    /** PHP property name for an input / output name (column, query parameter). */
    public function property(string $name): string
    {
        return $this->camelProperties() ? Str::camel($name) : $name;
    }

    /**
     * Whether the class-level SnakeCaseMapper cannot restore $name from its
     * property, so the property needs its own #[MapName('...')]:
     * iso_3166 -> iso3166 -> iso3166.
     */
    public function needsExplicitMapping(string $name): bool
    {
        return $this->camelProperties() && Str::snake($this->property($name)) !== $name;
    }

    public function namespace(Layer $layer): string
    {
        $namespace = trim($this->config($layer)['namespace'], '\\');

        // Input DTOs are grouped per model: App\Data\Input\Post\StorePostData
        return $layer->isPerAction() ? $namespace.'\\'.$this->model->name() : $namespace;
    }

    public function class(Layer $layer, ?Action $action = null): string
    {
        $suffix = $this->config($layer)['suffix'] ?? '';

        if ($layer->isPerAction()) {
            if ($action === null) {
                throw new InvalidArgumentException("Layer [{$layer->value}] requires an action.");
            }

            return Str::studly($action->value).$this->model->name().$suffix;
        }

        return $this->model->name().$suffix;
    }

    /** @return class-string */
    public function fqcn(Layer $layer, ?Action $action = null): string
    {
        return $this->namespace($layer).'\\'.$this->class($layer, $action);
    }

    /**
     * Output DTO of any model (for relations): App\Models\User -> App\Data\Output\UserData.
     *
     * @return class-string
     */
    public function outputFor(string $modelClass): string
    {
        return $this->namespace(Layer::Output).'\\'.class_basename($modelClass).$this->suffix(Layer::Output);
    }

    public function suffix(Layer $layer): string
    {
        return $this->config($layer)['suffix'] ?? '';
    }

    public function extends(Layer $layer): ?string
    {
        $extends = $this->config($layer)['extends'] ?? null;

        return $extends ? ltrim($extends, '\\') : null;
    }

    public function path(Layer $layer, ?Action $action = null): string
    {
        // Layers outside app/ (factories, tests) declare their directory explicitly.
        if (isset($this->config($layer)['path'])) {
            $dir = $this->config($layer)['path'];

            if (! str_starts_with($dir, '/')) {
                $dir = rtrim($this->basePath ?? dirname($this->appPath), '/').'/'.$dir;
            }

            return rtrim($dir, '/').'/'.$this->class($layer, $action).'.php';
        }

        $namespace = $this->namespace($layer);
        $root = trim($this->appNamespace, '\\');

        if ($namespace !== $root && ! str_starts_with($namespace, $root.'\\')) {
            throw new InvalidArgumentException("Namespace [{$namespace}] is outside of the application namespace [{$root}].");
        }

        $relative = str_replace('\\', '/', substr($namespace, strlen($root) + 1));

        return rtrim($this->appPath, '/').($relative !== '' ? '/'.$relative : '').'/'.$this->class($layer, $action).'.php';
    }

    /** @return array{namespace: string, suffix?: string, extends?: string|null} */
    private function config(Layer $layer): array
    {
        return $this->layers[$layer->value]
            ?? throw new InvalidArgumentException("Layer [{$layer->value}] is not configured in config/layers.php.");
    }
}
