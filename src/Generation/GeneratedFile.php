<?php

declare(strict_types=1);

namespace Toshkq93\LaravelLayers\Generation;

final class GeneratedFile
{
    /**
     * @param  list<string>  $notes  Hints for the developer, printed by the command
     */
    public function __construct(
        public readonly Layer $layer,
        public readonly string $path,
        public readonly string $contents,
        public readonly ?string $class = null,
        public readonly array $notes = [],
    ) {}
}
