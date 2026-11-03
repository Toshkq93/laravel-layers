<?php

declare(strict_types=1);

namespace Toshkq93\LaravelLayers\Contracts;

/**
 * Data returned from the repository layer.
 *
 * Repositories never leak Eloquent models: they return an implementation
 * of this interface (or a collection / paginator of them).
 */
interface OutputDataInterface
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(): array;
}
