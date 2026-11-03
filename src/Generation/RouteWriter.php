<?php

declare(strict_types=1);

namespace Toshkq93\LaravelLayers\Generation;

use Illuminate\Filesystem\Filesystem;

/**
 * Adds the model's routes to routes/api.php inside a marked block:
 *
 *     // layers:Post:start
 *     Route::middleware(PostMiddleware::class)->group(function () { ... });
 *     // layers:Post:end
 *
 * The block is skipped when present, or replaced with $force.
 */
final class RouteWriter
{
    public const CREATED = 'created';
    public const SKIPPED = 'skipped';
    public const REPLACED = 'replaced';

    public function __construct(
        private readonly Filesystem $files,
    ) {}

    public function block(Blueprint $blueprint): string
    {
        $naming = $blueprint->naming;
        $model = $blueprint->model->name();
        $controller = $naming->class(Layer::Controller);
        $uri = $blueprint->model->plural();
        $parameter = $blueprint->routeParameter();
        $hasMiddleware = $blueprint->has(Layer::Middleware);
        $indent = $hasMiddleware ? '    ' : '';

        $routes = [];

        foreach ($blueprint->actions as $action) {
            $path = $action->needsId() ? "{$uri}/{{$parameter}}" : $uri;
            $routes[] = "{$indent}Route::{$action->httpMethod()}('{$path}', [{$controller}::class, '{$action->value}'])->name('{$blueprint->routeName($action)}');";
        }

        $body = implode("\n", $routes);

        if ($hasMiddleware) {
            $middleware = $naming->class(Layer::Middleware);
            $body = "Route::middleware({$middleware}::class)->group(function () {\n{$body}\n});";
        }

        return "// layers:{$model}:start\n{$body}\n// layers:{$model}:end";
    }

    /**
     * @return array{status: string, path: string}
     */
    public function write(Blueprint $blueprint, string $path, bool $force = false): array
    {
        $contents = $this->files->exists($path)
            ? $this->files->get($path)
            : "<?php\n\nuse Illuminate\\Support\\Facades\\Route;\n";

        $model = preg_quote($blueprint->model->name(), '/');
        $pattern = "/\/\/ layers:{$model}:start.*?\/\/ layers:{$model}:end/s";
        $block = $this->block($blueprint);

        if (preg_match($pattern, $contents)) {
            if (! $force) {
                return ['status' => self::SKIPPED, 'path' => $path];
            }

            $contents = preg_replace($pattern, str_replace(['\\', '$'], ['\\\\', '\\$'], $block), $contents);
            $status = self::REPLACED;
        } else {
            $contents = rtrim($contents)."\n\n".$block."\n";
            $status = self::CREATED;
        }

        $contents = $this->addImports($contents, array_filter([
            'Illuminate\\Support\\Facades\\Route',
            $blueprint->naming->fqcn(Layer::Controller),
            $blueprint->has(Layer::Middleware) ? $blueprint->naming->fqcn(Layer::Middleware) : null,
        ]));

        $this->files->ensureDirectoryExists(dirname($path));
        $this->files->put($path, $contents);

        return ['status' => $status, 'path' => $path];
    }

    /**
     * @param  list<string>  $classes
     */
    private function addImports(string $contents, array $classes): string
    {
        $missing = array_filter($classes, fn (string $class) => ! preg_match('/^use '.preg_quote($class, '/').';$/m', $contents));

        if ($missing === []) {
            return $contents;
        }

        $lines = implode("\n", array_map(fn (string $class) => "use {$class};", $missing));

        // After the last top-level use statement, or right after <?php.
        if (preg_match_all('/^use [^;]+;$/m', $contents, $matches, PREG_OFFSET_CAPTURE)) {
            $last = end($matches[0]);
            $offset = $last[1] + strlen($last[0]);

            return substr($contents, 0, $offset)."\n".$lines.substr($contents, $offset);
        }

        return preg_replace('/^<\?php\s*/', "<?php\n\n{$lines}\n\n", $contents, 1);
    }

    /**
     * Laravel 11+ skeletons do not load routes/api.php until it is registered
     * in bootstrap/app.php. Adds `api:` next to `web:` when possible.
     *
     * @return bool|null true = registered now, false = could not register, null = already registered
     */
    public function ensureRegistered(string $bootstrapPath, string $routesPath, string $basePath): ?bool
    {
        if (! $this->files->exists($bootstrapPath)) {
            return false;
        }

        $contents = $this->files->get($bootstrapPath);
        $relative = ltrim(substr($routesPath, strlen(rtrim($basePath, '/'))), '/');

        if (str_contains($contents, basename($routesPath)) && preg_match('/\bapi\s*:/', $contents)) {
            return null;
        }

        $pattern = "/^([ \\t]*)web:[ \\t]*__DIR__[ \\t]*\\.[ \\t]*'[^']+',[ \\t]*$/m";

        if (! preg_match($pattern, $contents, $m)) {
            return false;
        }

        $line = "{$m[1]}api: __DIR__.'/../{$relative}',";
        $contents = preg_replace($pattern, '$0'."\n".str_replace(['\\', '$'], ['\\\\', '\\$'], $line), $contents, 1);
        $this->files->put($bootstrapPath, $contents);

        return true;
    }
}
