<?php

declare(strict_types=1);

namespace Toshkq93\LaravelLayers\Tests\Fixtures;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
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

    public function paginate(int $perPage = 15): LengthAwarePaginator
    {
        return $this->toPaginator($this->query()->orderBy('id')->paginate($perPage));
    }

    public function find(int $id): ?PostOutputData
    {
        return $this->findOne($id);
    }

    public function create(PostInputData $data): PostOutputData
    {
        return $this->createFrom($data);
    }

    public function findOrFail(int $id): PostOutputData
    {
        return $this->findOneOrFail($id);
    }

    public function update(int $id, PostInputData $data): PostOutputData
    {
        return $this->updateFrom($id, $data);
    }

    public function delete(int $id): bool
    {
        return $this->deleteOne($id);
    }
}
