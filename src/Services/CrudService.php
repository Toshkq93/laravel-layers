<?php

declare(strict_types=1);

namespace Toshkq93\LaravelLayers\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Toshkq93\LaravelLayers\Contracts\InputDataInterface;
use Toshkq93\LaravelLayers\Contracts\OutputDataInterface;
use Toshkq93\LaravelLayers\Data\OutputData;
use Toshkq93\LaravelLayers\Repositories\EloquentRepository;

/**
 * Base service: the CRUD methods delegate to the repository.
 *
 * Business logic goes into the concrete service: override a method
 * (calling parent:: or $this->repository) or add new ones.
 *
 *     // @extends CrudService<PostRepository, PostData>
 *     class PostService extends CrudService
 *     {
 *         public function __construct(PostRepository $repository)
 *         {
 *             parent::__construct($repository);
 *         }
 *     }
 *
 * @template TRepository of EloquentRepository
 * @template TOutput of OutputData
 */
abstract class CrudService
{
    /**
     * @param  TRepository  $repository
     */
    public function __construct(
        protected readonly EloquentRepository $repository,
    ) {}

    /**
     * @return LengthAwarePaginator<int, TOutput>
     */
    public function paginate(int $perPage = 15, int $page = 1): LengthAwarePaginator
    {
        return $this->repository->paginate($perPage, $page);
    }

    /**
     * @return TOutput|null
     */
    public function find(int|string $id): ?OutputDataInterface
    {
        return $this->repository->find($id);
    }

    /**
     * @return TOutput
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     */
    public function findOrFail(int|string $id): OutputDataInterface
    {
        return $this->repository->findOrFail($id);
    }

    /**
     * @return TOutput
     */
    public function create(InputDataInterface $data): OutputDataInterface
    {
        return $this->repository->create($data);
    }

    /**
     * @return TOutput
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     */
    public function update(int|string $id, InputDataInterface $data): OutputDataInterface
    {
        return $this->repository->update($id, $data);
    }

    public function delete(int|string $id): bool
    {
        return $this->repository->delete($id);
    }
}
