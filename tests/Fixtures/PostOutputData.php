<?php

declare(strict_types=1);

namespace Toshkq93\LaravelLayers\Tests\Fixtures;

use Toshkq93\LaravelLayers\Data\OutputData;

class PostOutputData extends OutputData
{
    public function __construct(
        public int $id,
        public string $title,
        public ?string $body,
        public bool $published,
    ) {}
}
