<?php

declare(strict_types=1);

namespace Toshkq93\LaravelLayers\Generation;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;

/**
 * Adds the model's routes to routes/api.php inside a marked block:
 *
 *     // layers:Post:start
 *     Route::apiResource('posts', PostController::class)
 *         ->middleware(PostMiddleware::class);
 *     // layers:Post:end
 *
 * With a route group configured, the blocks live inside one shared group:
 *
 *     // layers:start
 *     Route::prefix('v1')->middleware(['auth:sanctum'])->group(function () {
 *         // layers:Post:start ... // layers:Post:end
 *     });
 *     // layers:end
 *
 * The model block is skipped when present, or replaced with $force.
 */
final class RouteWriter
{
    public const CREATED = 'created';
    public const SKIPPED = 'skipped';
    public const REPLACED = 'replaced';

    private const INDENT = '    ';

    public function __construct(
        private readonly Filesystem $files,
    ) {}

    public function block(Blueprint $blueprint): string
    {
        $naming = $blueprint->naming;
        $model = $blueprint->model->name();
        $uri = $blueprint->model->plural();
        $parameter = $blueprint->routeParameter();

        $calls = [];

        if (count($blueprint->actions) < count(Action::cases())) {
            $calls[] = 'only(['.implode(', ', array_map(fn (Action $a) => "'{$a->value}'", $blueprint->actions)).'])';
        }

        // apiResource names the parameter after the singular URI with "_": blog-posts -> {blog_post}.
        if (str_replace('-', '_', Str::singular($uri)) !== $parameter) {
            $calls[] = "parameters(['{$uri}' => '{$parameter}'])";
        }

        if ($blueprint->has(Layer::Middleware)) {
            $calls[] = 'middleware('.$naming->class(Layer::Middleware).'::class)';
        }

        $route = "Route::apiResource('{$uri}', ".$naming->class(Layer::Controller).'::class)'
            .implode('', array_map(fn (string $call) => "\n".self::INDENT."->{$call}", $calls)).';';

        return "// layers:{$model}:start\n{$route}\n// layers:{$model}:end";
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
        $pattern = "/^([ \\t]*)\/\/ layers:{$model}:start.*?\/\/ layers:{$model}:end/ms";
        $block = $this->block($blueprint);
        $group = $blueprint->routeGroup;

        if (preg_match($pattern, $contents)) {
            if (! $force) {
                return ['status' => self::SKIPPED, 'path' => $path];
            }

            // Keep the block where it is (inside or outside the group) and its indentation.
            $contents = preg_replace_callback($pattern, fn (array $m) => $this->indent($block, $m[1]), $contents, 1);
            $status = self::REPLACED;
        } elseif ($group->isEmpty()) {
            $contents = rtrim($contents)."\n\n".$block."\n";
            $status = self::CREATED;
        } elseif (preg_match('/\n\}\);\n\/\/ layers:end/', $contents, $m, PREG_OFFSET_CAPTURE)) {
            $offset = $m[0][1];
            $contents = substr($contents, 0, $offset)."\n\n".$this->indent($block, self::INDENT).substr($contents, $offset);
            $status = self::CREATED;
        } else {
            $contents = rtrim($contents)."\n\n// layers:start\n".$group->opening()."\n"
                .$this->indent($block, self::INDENT)."\n});\n// layers:end\n";
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

    private function indent(string $text, string $indent): string
    {
        return implode("\n", array_map(fn (string $line) => $line === '' ? '' : $indent.$line, explode("\n", $text)));
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
