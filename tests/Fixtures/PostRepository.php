<?php

declare(strict_types=1);

namespace Toshkq93\LaravelLayers\Tests\Fixtures;

use Illuminate\Support\Collection;
use Toshkq93\LaravelLayers\Repositories\EloquentRepository;

/**
 * @extends EloquentRepository<Post, PostOutputData>
 */
class PostRepository extends EloquentRepository
{
    protected function model(): string
    {
        return Post::class;
    }

    protected function output(): string
    {
        return PostOutputData::class;
    }

    /** @return Collection<int, PostOutputData> */
    public function published(): Collection
    {
        return $this->toCollection($this->query()->where('published', true)->orderBy('id')->get());
    }
}
