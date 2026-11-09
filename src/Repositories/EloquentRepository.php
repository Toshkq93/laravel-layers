<?php

declare(strict_types=1);

namespace Toshkq93\LaravelLayers\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Toshkq93\LaravelLayers\Contracts\InputDataInterface;
use Toshkq93\LaravelLayers\Contracts\OutputDataInterface;
use Toshkq93\LaravelLayers\Data\OutputData;

/**
 * Base repository on top of Eloquent.
 *
 * Gives every repository the CRUD methods (paginate, find, findOrFail,
 * create, update, delete); a concrete repository only names its model and
 * output DTO and adds its own queries.
 *
 * The model never leaves the repository: every public method of a concrete
 * repository should return OutputData, a Collection of OutputData,
 * a paginator of OutputData, or a scalar.
 *
 * @template TModel of Model
 * @template TOutput of OutputData
 */
abstract class EloquentRepository
{
    /**
     * Relations eager-loaded by every query of this repository. They end up
     * in the output DTO (its relation properties are filled only for loaded
     * relations). Load per query with $this->query()->with(...) instead when
     * only some queries need them.
     *
     * @var list<string>
     */
    protected array $with = [];

    /**
     * Column paginate() sorts by; null = the model's primary key.
     */
    protected ?string $orderBy = null;

    /** 'asc' or 'desc'. */
    protected string $orderDirection = 'asc';

    /**
     * @return class-string<TModel>
     */
    abstract protected function model(): string;

    /**
     * @return class-string<TOutput>
     */
    abstract protected function output(): string;

    /**
     * @return LengthAwarePaginator<int, TOutput>
     */
    public function paginate(int $perPage = 15, int $page = 1): LengthAwarePaginator
    {
        return $this->toPaginator(
            $this->paginateQuery()->paginate(perPage: $perPage, page: $page),
        );
    }

    /**
     * @return TOutput|null
     */
    public function find(int|string $id): ?OutputDataInterface
    {
        return $this->findOne($id);
    }

    /**
     * @return TOutput
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     */
    public function findOrFail(int|string $id): OutputDataInterface
    {
        return $this->findOneOrFail($id);
    }

    /**
     * @return TOutput
     */
    public function create(InputDataInterface $data): OutputDataInterface
    {
        return $this->createFrom($data);
    }

    /**
     * @return TOutput
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     */
    public function update(int|string $id, InputDataInterface $data): OutputDataInterface
    {
        return $this->updateFrom($id, $data);
    }

    public function delete(int|string $id): bool
    {
        return $this->deleteOne($id);
    }

    /**
     * @return Builder<TModel>
     */
    protected function query(): Builder
    {
        return ($this->model())::query()->with($this->with);
    }

    /**
     * Query behind paginate(): override to add filters or another sort.
     *
     * @return Builder<TModel>
     */
    protected function paginateQuery(): Builder
    {
        $query = $this->query();

        return $query->orderBy(
            $this->orderBy ?? $query->getModel()->getQualifiedKeyName(),
            $this->orderDirection,
        );
    }

    /**
     * @return TOutput|null
     */
    protected function toOutput(?Model $model): ?OutputDataInterface
    {
        if ($model === null) {
            return null;
        }

        return ($this->output())::from($model);
    }

    /**
     * @param  iterable<TModel>  $models
     * @return Collection<int, TOutput>
     */
    protected function toCollection(iterable $models): Collection
    {
        return Collection::make($models)
            ->map(fn (Model $model) => $this->toOutput($model))
            ->values();
    }

    /**
     * @return LengthAwarePaginator<int, TOutput>
     */
    protected function toPaginator(LengthAwarePaginator $paginator): LengthAwarePaginator
    {
        return $paginator->through(fn (Model $model) => $this->toOutput($model));
    }

    /**
     * @return TOutput|null
     */
    protected function findOne(int|string $id): ?OutputDataInterface
    {
        return $this->toOutput($this->query()->find($id));
    }

    /**
     * @return TOutput
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     */
    protected function findOneOrFail(int|string $id): OutputDataInterface
    {
        return $this->toOutput($this->query()->findOrFail($id));
    }

    /**
     * The model is re-read after insert: Eloquent does not know column
     * defaults applied by the database (status = 'draft', published = false),
     * and the output DTO must contain every column. $with relations are
     * loaded so the response looks like the one from find().
     *
     * @return TOutput
     */
    protected function createFrom(InputDataInterface $data): OutputDataInterface
    {
        $model = $this->query()->create($data->toArray())->refresh();

        return $this->toOutput($this->with === [] ? $model : $model->load($this->with));
    }

    /**
     * Only attributes present in the DTO are updated: properties left as
     * Optional and #[Hidden] ones (e.g. the route id) are not in toArray().
     *
     * @return TOutput
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     */
    protected function updateFrom(int|string $id, InputDataInterface $data): OutputDataInterface
    {
        $model = $this->query()->findOrFail($id);

        $model->update($data->toArray());

        // A changed foreign key (user_id) leaves the loaded relation stale: reload it.
        return $this->toOutput($this->with === [] ? $model : $model->refresh());
    }

    protected function deleteOne(int|string $id): bool
    {
        return (bool) $this->query()->whereKey($id)->delete();
    }
}
