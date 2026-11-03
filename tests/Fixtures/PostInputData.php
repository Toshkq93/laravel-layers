<?php

declare(strict_types=1);

namespace Toshkq93\LaravelLayers\Tests\Fixtures;

use Spatie\LaravelData\Attributes\Validation\Max;
use Toshkq93\LaravelLayers\Data\InputData;

class PostInputData extends InputData
{
    public function __construct(
        #[Max(255)]
        public string $title,
        public ?string $body,
        public bool $published = false,
    ) {}
}
