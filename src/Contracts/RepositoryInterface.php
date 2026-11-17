<?php

declare(strict_types=1);

namespace Toshkq93\LaravelLayers\Contracts;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * CRUD methods of a repository, implemented by EloquentRepository.
 *
 * A generated contract (PostRepositoryInterface) extends it, so services
 * and controllers can depend on the interface and tests can mock it.
 *
 * @template-covariant TOutput of OutputDataInterface
 */
interface RepositoryInterface
{
    /**
     * @return LengthAwarePaginator<int, covariant TOutput>
     */
    public function paginate(int $perPage = 15, int $page = 1): LengthAwarePaginator;

    /**
     * @return TOutput|null
     */
    public function find(int|string $id): ?OutputDataInterface;

    /**
     * @return TOutput
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     */
    public function findOrFail(int|string $id): OutputDataInterface;

    /**
     * @return TOutput
     */
    public function create(InputDataInterface $data): OutputDataInterface;

    /**
     * @return TOutput
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     */
    public function update(int|string $id, InputDataInterface $data): OutputDataInterface;

    /**
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     */
    public function delete(int|string $id): bool;
}
