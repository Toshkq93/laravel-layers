<?php

declare(strict_types=1);

namespace Toshkq93\LaravelLayers\Tests\Fixtures;

enum PostStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
}
