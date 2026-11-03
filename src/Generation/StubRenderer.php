<?php

declare(strict_types=1);

namespace Toshkq93\LaravelLayers\Generation;

use RuntimeException;

/**
 * Renders stubs. A stub published to the project (stubs/layers/*.stub)
 * takes precedence over the package one.
 */
final class StubRenderer
{
    public function __construct(
        private readonly ?string $customPath = null,
        private readonly string $defaultPath = __DIR__.'/../../stubs',
    ) {}

    /**
     * @param  array<string, string>  $replace
     */
    public function render(string $stub, array $replace): string
    {
        $content = file_get_contents($this->locate($stub));

        foreach ($replace as $key => $value) {
            $content = str_replace('{{ '.$key.' }}', $value, $content);
        }

        // Empty placeholders (no imports, no constructor, ...) leave runs of blank lines.
        $content = preg_replace("/\n{3,}/", "\n\n", $content);
        $content = preg_replace("/\{\n\n+/", "{\n", $content);

        return preg_replace("/\n\n+(\s*)\}/", "\n$1}", $content);
    }

    private function locate(string $stub): string
    {
        foreach (array_filter([$this->customPath, $this->defaultPath]) as $dir) {
            $path = rtrim($dir, '/').'/'.$stub.'.stub';

            if (is_file($path)) {
                return $path;
            }
        }

        throw new RuntimeException("Stub [{$stub}] not found.");
    }
}
