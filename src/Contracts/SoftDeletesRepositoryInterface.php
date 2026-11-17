<?php

declare(strict_types=1);

namespace Toshkq93\LaravelLayers\Contracts;

/**
 * restore() and forceDelete() of a repository whose model uses SoftDeletes.
 * EloquentRepository has both methods, so a repository only declares the
 * interface.
 *
 * @template-covariant TOutput of OutputDataInterface
 */
interface SoftDeletesRepositoryInterface
{
    /**
     * @return TOutput
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     */
    public function restore(int|string $id): OutputDataInterface;

    /**
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     */
    public function forceDelete(int|string $id): bool;
}
