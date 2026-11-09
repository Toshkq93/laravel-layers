<?php

declare(strict_types=1);

namespace Toshkq93\LaravelLayers\Generation;

/**
 * Shared group every generated resource is placed in (config layers.routes.group):
 *
 *     Route::prefix('v1')->middleware(['auth:sanctum'])->name('v1.')->group(function () { ... });
 */
final class RouteGroup
{
    /**
     * @param  list<string>  $middleware
     */
    public function __construct(
        public readonly ?string $prefix = null,
        public readonly array $middleware = [],
        public readonly ?string $as = null,
    ) {}

    /**
     * @param  array{prefix?: ?string, middleware?: string|list<string>|null, as?: ?string}|null  $config
     */
    public static function fromConfig(?array $config): self
    {
        $config ??= [];
        $prefix = trim((string) ($config['prefix'] ?? ''), '/');
        $as = (string) ($config['as'] ?? '');

        return new self(
            $prefix === '' ? null : $prefix,
            array_values(array_filter((array) ($config['middleware'] ?? []), fn ($m) => is_string($m) && $m !== '')),
            $as === '' ? null : $as,
        );
    }

    public function isEmpty(): bool
    {
        return $this->prefix === null && $this->middleware === [] && $this->as === null;
    }

    /** Route name prefix: 'v1.' (or ''). */
    public function namePrefix(): string
    {
        return $this->as ?? '';
    }

    /** Route::prefix('v1')->middleware(['auth:sanctum'])->name('v1.')->group(function () { */
    public function opening(): string
    {
        $calls = [];

        if ($this->prefix !== null) {
            $calls[] = 'prefix('.self::quote($this->prefix).')';
        }

        if ($this->middleware !== []) {
            $calls[] = 'middleware(['.implode(', ', array_map(self::quote(...), $this->middleware)).'])';
        }

        if ($this->as !== null) {
            $calls[] = 'name('.self::quote($this->as).')';
        }

        $calls[] = 'group(function () {';

        return 'Route::'.implode('->', $calls);
    }

    public static function quote(string $value): string
    {
        // Backslashes matter only before a quote or at the end: 'App\Http\Foo' stays readable.
        $value = preg_replace("/(\\\\+)(?='|$)/", '$1$1', $value);

        return "'".str_replace("'", "\\'", $value)."'";
    }
}
