<?php

declare(strict_types=1);

namespace Toshkq93\LaravelLayers\Scramble;

use Dedoc\Scramble\Infer\Extensions\Event\MethodCallEvent;
use Dedoc\Scramble\Infer\Extensions\MethodReturnTypeExtension;
use Dedoc\Scramble\Support\Type\ArrayType;
use Dedoc\Scramble\Support\Type\Contracts\LiteralString;
use Dedoc\Scramble\Support\Type\NullType;
use Dedoc\Scramble\Support\Type\ObjectType;
use Dedoc\Scramble\Support\Type\Type;
use Dedoc\Scramble\Support\Type\Union;
use Illuminate\Http\Resources\MissingValue;
use Illuminate\Support\Collection;
use Toshkq93\LaravelLayers\Http\Resources\DataResource;

/**
 * whenLoadedData($this->user, UserResource::class) is the related resource
 * (or an array of them for a collection relation), null for an empty single
 * relation, and missing when the relation is not loaded.
 */
class DataResourceExtension implements MethodReturnTypeExtension
{
    public function shouldHandle(ObjectType $type): bool
    {
        return $type->isInstanceOf(DataResource::class);
    }

    public function getMethodReturnType(MethodCallEvent $event): ?Type
    {
        if ($event->name !== 'whenLoadedData') {
            return null;
        }

        // UserResource::class: class-string<UserResource>, or a plain string literal.
        $resource = $event->getArg('resource', 1);
        $class = $resource instanceof LiteralString ? $resource->getValue() : null;

        if ($class === null || ! class_exists($class)) {
            return null;
        }

        $resourceType = new ObjectType($class);

        if (self::isCollection($event->getArg('value', 0))) {
            return Union::wrap([new ArrayType($resourceType), new ObjectType(MissingValue::class)]);
        }

        return Union::wrap([$resourceType, new NullType, new ObjectType(MissingValue::class)]);
    }

    private static function isCollection(Type $type): bool
    {
        foreach ($type instanceof Union ? $type->types : [$type] as $part) {
            if ($part instanceof ArrayType || ($part instanceof ObjectType && $part->isInstanceOf(Collection::class))) {
                return true;
            }
        }

        return false;
    }
}
