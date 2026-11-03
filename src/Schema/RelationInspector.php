<?php

declare(strict_types=1);

namespace Toshkq93\LaravelLayers\Schema;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\Relation as EloquentRelation;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use Throwable;

/**
 * Finds the relations of a model, the way laravel-ide-helper does:
 *
 *  1. public, non-static methods without required parameters, declared by
 *     the model or its own traits (not by Eloquent itself);
 *  2. whose return type is a Relation, or — without a return type — whose
 *     body calls $this->hasMany(...), $this->belongsTo(...), etc.;
 *  3. the method is called on a fresh instance: building a relation does
 *     not query the database, and gives the related model and the type.
 *
 * morphTo is skipped: its related model is only known per record.
 */
class RelationInspector
{
    private const BUILDERS = 'hasOne|hasMany|belongsTo|belongsToMany|morphOne|morphMany|morphToMany|morphedByMany|hasOneThrough|hasManyThrough';

    private const SINGLE = [BelongsTo::class, HasOne::class, MorphOne::class, HasOneThrough::class];

    private const MANY = [HasMany::class, MorphMany::class, BelongsToMany::class, HasManyThrough::class];

    /**
     * @return array<string, Relation> keyed by relation name
     */
    public function inspect(Model $model): array
    {
        $relations = [];
        $hidden = $model->getHidden();

        foreach ((new ReflectionClass($model))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if (! $this->isCandidate($method) || in_array($method->getName(), $hidden, true)) {
                continue;
            }

            $relation = $this->build($model, $method);

            if ($relation !== null) {
                $relations[$relation->name] = $relation;
            }
        }

        ksort($relations);

        return $relations;
    }

    private function isCandidate(ReflectionMethod $method): bool
    {
        if ($method->isStatic() || $method->isAbstract() || $method->getNumberOfRequiredParameters() > 0) {
            return false;
        }

        // Eloquent's own methods are never user relations. Trait methods are reported as
        // declared by the model itself, so framework traits (Notifiable::notifications(),
        // SoftDeletes, ...) are recognised by the file they live in.
        if (str_starts_with($method->getDeclaringClass()->getName(), 'Illuminate\\')
            || str_contains((string) $method->getFileName(), DIRECTORY_SEPARATOR.'Illuminate'.DIRECTORY_SEPARATOR)) {
            return false;
        }

        $type = $method->getReturnType();

        if ($type instanceof ReflectionNamedType && ! $type->isBuiltin()) {
            return is_a($type->getName(), EloquentRelation::class, true);
        }

        if ($type !== null) {
            return false;
        }

        return (bool) preg_match('/\$this\s*->\s*(?:'.self::BUILDERS.')\s*\(/', $this->body($method));
    }

    private function build(Model $model, ReflectionMethod $method): ?Relation
    {
        try {
            $relation = $method->invoke(new ($model::class));
        } catch (Throwable) {
            return null;
        }

        if (! $relation instanceof EloquentRelation || $relation instanceof MorphTo) {
            return null;
        }

        $many = match (true) {
            $this->isAny($relation, self::SINGLE) => false,
            $this->isAny($relation, self::MANY) => true,
            default => null,
        };

        if ($many === null) {
            return null;
        }

        return new Relation(
            name: $method->getName(),
            related: $relation->getRelated()::class,
            many: $many,
            type: $relation::class,
        );
    }

    /** @param list<class-string> $classes */
    private function isAny(object $object, array $classes): bool
    {
        foreach ($classes as $class) {
            if ($object instanceof $class) {
                return true;
            }
        }

        return false;
    }

    private function body(ReflectionMethod $method): string
    {
        $file = $method->getFileName();

        if ($file === false || ! is_file($file)) {
            return '';
        }

        $lines = file($file);

        return implode('', array_slice($lines, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1));
    }
}
