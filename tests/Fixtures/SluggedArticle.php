<?php

declare(strict_types=1);

namespace Toshkq93\LaravelLayers\Tests\Fixtures;

/**
 * The articles table addressed by slug in the routes.
 */
class SluggedArticle extends Article
{
    protected $table = 'articles';

    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}
