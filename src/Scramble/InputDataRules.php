<?php

declare(strict_types=1);

namespace Toshkq93\LaravelLayers\Scramble;

use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Spatie\LaravelData\Attributes\FromRouteParameter;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Attributes\FromRouteParameterProperty;
use Spatie\LaravelData\Support\DataConfig;
use Spatie\LaravelData\Support\DataProperty;
use Throwable;
use Toshkq93\LaravelLayers\Contracts\InputDataInterface;

/**
 * Validation rules of an input DTO, as laravel-data builds them for a
 * request, without a request:
 *
 *  - properties filled from the route ({post} -> $id) are left out, they
 *    are path parameters, not request fields;
 *  - properties with a default value ($page = 1) are optional;
 *  - rules that read the route (Unique ignoring the current record) get a
 *    route with placeholder parameters.
 */
final class InputDataRules
{
    /**
     * @param  class-string<Data&InputDataInterface>  $class
     * @param  list<string>  $routeParameters
     * @return array<string, array<int, mixed>>
     */
    public static function for(string $class, array $routeParameters = []): array
    {
        $properties = app(DataConfig::class)->getDataClass($class)->properties;
        $fromRoute = [];
        $optional = [];
        $integers = [];
        $payload = [];

        foreach ($properties as $property) {
            /** @var DataProperty $property */
            $name = $property->inputMappedName ?? $property->name;

            if (self::fromRoute($class, $property->name)) {
                $fromRoute[] = $name;

                continue;
            }

            // Without the key in the payload laravel-data leaves out the rules of defaulted properties.
            $payload[$name] = null;

            if ($property->hasDefaultValue) {
                $optional[] = $name;
            }

            if (self::isInteger($class, $property->name)) {
                $integers[] = $name;
            }
        }

        $rules = self::withRoute($routeParameters, fn () => $class::getValidationRules($payload));

        foreach ($fromRoute as $name) {
            unset($rules[$name]);
        }

        foreach ($optional as $name) {
            if (isset($rules[$name])) {
                $rules[$name] = ['sometimes', ...array_values(array_filter((array) $rules[$name], fn ($rule) => $rule !== 'required'))];
            }
        }

        // laravel-data validates int properties as "numeric": documented as integers.
        foreach ($integers as $name) {
            if (isset($rules[$name])) {
                $rules[$name] = array_map(fn ($rule) => $rule === 'numeric' ? 'integer' : $rule, (array) $rules[$name]);
            }
        }

        return array_map(fn ($rule) => array_values((array) $rule), $rules);
    }

    /**
     * Filled from the route ({post} -> $id). Read with reflection: the shape of
     * DataProperty::$attributes differs between laravel-data versions.
     */
    private static function fromRoute(string $class, string $property): bool
    {
        $reflection = new \ReflectionProperty($class, $property);

        return $reflection->getAttributes(FromRouteParameter::class, \ReflectionAttribute::IS_INSTANCEOF) !== []
            || $reflection->getAttributes(FromRouteParameterProperty::class, \ReflectionAttribute::IS_INSTANCEOF) !== [];
    }

    private static function isInteger(string $class, string $property): bool
    {
        $type = (new \ReflectionProperty($class, $property))->getType();
        $names = $type instanceof \ReflectionUnionType
            ? $type->getTypes()
            : [$type];
        $names = array_map(fn ($t) => $t instanceof \ReflectionNamedType ? $t->getName() : null, $names);

        return in_array('int', $names, true) && ! in_array('float', $names, true) && ! in_array('string', $names, true);
    }

    /**
     * The callback's rules, or none when they cannot be built.
     *
     * @param  list<string>  $parameters
     * @param  callable(): array<string, mixed>  $callback
     * @return array<string, mixed>
     */
    private static function withRoute(array $parameters, callable $callback): array
    {
        $request = app('request');
        $resolver = $request->getRouteResolver();
        $route = (new Route(['GET'], '/'.implode('/', array_map(fn ($p) => '{'.$p.'}', $parameters)), []))->bind(Request::create('/'));

        foreach ($parameters as $parameter) {
            $route->setParameter($parameter, '1');
        }

        $request->setRouteResolver(fn () => $route);

        try {
            return $callback();
        } catch (Throwable) {
            return [];
        } finally {
            $request->setRouteResolver($resolver);
        }
    }
}
