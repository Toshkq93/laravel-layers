<?php

declare(strict_types=1);

namespace Toshkq93\LaravelLayers\Generation;

/**
 * Collects `use` statements for a generated file and returns short names.
 */
final class Imports
{
    /** @var array<string, string> short name => FQCN */
    private array $uses = [];

    public function __construct(
        private readonly string $namespace,
    ) {}

    public function add(string $class): string
    {
        $class = ltrim($class, '\\');
        $short = class_basename($class);
        $namespace = str_contains($class, '\\') ? substr($class, 0, strrpos($class, '\\')) : '';

        if (isset($this->uses[$short]) && $this->uses[$short] !== $class) {
            // Name clash: fall back to the fully qualified name.
            return '\\'.$class;
        }

        if ($namespace !== $this->namespace) {
            $this->uses[$short] = $class;
        }

        return $short;
    }

    public function render(): string
    {
        $classes = array_values($this->uses);
        sort($classes);

        return implode("\n", array_map(fn (string $class) => "use {$class};", $classes));
    }
}
