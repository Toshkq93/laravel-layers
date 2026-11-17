<?php

declare(strict_types=1);

namespace Toshkq93\LaravelLayers\Tests\Feature;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use LogicException;
use Toshkq93\LaravelLayers\Contracts\InputDataInterface;
use Toshkq93\LaravelLayers\Contracts\OutputDataInterface;
use Toshkq93\LaravelLayers\Contracts\RepositoryInterface;
use Toshkq93\LaravelLayers\Services\CrudService;
use Toshkq93\LaravelLayers\Tests\TestCase;

class CrudServiceTest extends TestCase
{
    public function test_restore_needs_a_soft_deletes_repository(): void
    {
        $service = new class(new class implements RepositoryInterface
        {
            public function paginate(int $perPage = 15, int $page = 1): LengthAwarePaginator
            {
                throw new LogicException;
            }

            public function find(int|string $id): ?OutputDataInterface
            {
                return null;
            }

            public function findOrFail(int|string $id): OutputDataInterface
            {
                throw new LogicException;
            }

            public function create(InputDataInterface $data): OutputDataInterface
            {
                throw new LogicException;
            }

            public function update(int|string $id, InputDataInterface $data): OutputDataInterface
            {
                throw new LogicException;
            }

            public function delete(int|string $id): bool
            {
                return true;
            }
        }) extends CrudService {};

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('does not implement '.\Toshkq93\LaravelLayers\Contracts\SoftDeletesRepositoryInterface::class);

        $service->restore(1);
    }
}
