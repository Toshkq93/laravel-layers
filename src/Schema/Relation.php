<?php

declare(strict_types=1);

namespace Toshkq93\LaravelLayers\Schema;

/**
 * A model relation: name of the relation method, related model and
 * whether it returns one record or a collection.
 */
final class Relation
{
    /**
     * @param  class-string<\Illuminate\Database\Eloquent\Model>  $related
     * @param  class-string<\Illuminate\Database\Eloquent\Relations\Relation>  $type
     */
    public function __construct(
        public readonly string $name,
        public readonly string $related,
        public readonly bool $many,
        public readonly string $type,
    ) {}
}
