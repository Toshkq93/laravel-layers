<?php

declare(strict_types=1);

namespace Toshkq93\LaravelLayers\Schema;

use Carbon\CarbonInterface;
use UnitEnum;

/**
 * Maps a DB column (as returned by Schema::getColumns()) and an optional
 * Eloquent cast to a PHP type and validation metadata.
 *
 * The cast wins over the column type, because it is what the model actually
 * hands to the DTO.
 */
class TypeMapper
{
    private const INTEGER = ['integer', 'int', 'int2', 'int4', 'int8', 'bigint', 'smallint', 'mediumint', 'tinyint', 'serial', 'bigserial', 'smallserial', 'year'];

    private const FLOAT = ['float', 'float4', 'float8', 'double', 'double precision', 'real'];

    private const DECIMAL = ['decimal', 'numeric', 'money'];

    private const BOOLEAN = ['boolean', 'bool'];

    private const JSON = ['json', 'jsonb'];

    private const DATETIME = ['datetime', 'timestamp', 'timestamptz', 'timestamp without time zone', 'timestamp with time zone'];

    private const TIME = ['time', 'timetz', 'time without time zone', 'time with time zone'];

    /**
     * @param  array{name: string, type_name: string, type: string, nullable: bool, default: mixed, auto_increment: bool}  $column
     * @return array{type: string, maxLength: ?int, allowed: list<string>, format: ?string}
     */
    public function map(array $column, ?string $cast = null): array
    {
        $result = $this->fromColumn($column);

        if ($cast !== null) {
            $result = $this->applyCast($result, $cast);
        }

        return $result;
    }

    /**
     * @return array{type: string, maxLength: ?int, allowed: list<string>, format: ?string}
     */
    private function fromColumn(array $column): array
    {
        $typeName = strtolower((string) $column['type_name']);
        $fullType = strtolower((string) $column['type']);

        $result = ['type' => 'string', 'maxLength' => null, 'allowed' => [], 'format' => null];

        // tinyint(1) is how MySQL and SQLite store booleans.
        if ($fullType === 'tinyint(1)' || in_array($typeName, self::BOOLEAN, true)) {
            return ['type' => 'bool'] + $result;
        }

        if (in_array($typeName, self::INTEGER, true)) {
            return ['type' => 'int'] + $result;
        }

        if (in_array($typeName, self::FLOAT, true)) {
            return ['type' => 'float'] + $result;
        }

        // Decimals come back from PDO as strings; keep them as numeric strings to avoid precision loss.
        if (in_array($typeName, self::DECIMAL, true)) {
            return ['format' => Field::FORMAT_NUMERIC] + $result;
        }

        if (in_array($typeName, self::JSON, true)) {
            return ['type' => 'array'] + $result;
        }

        if ($typeName === 'uuid') {
            return ['format' => Field::FORMAT_UUID] + $result;
        }

        if ($typeName === 'date') {
            return ['format' => Field::FORMAT_DATE] + $result;
        }

        if (in_array($typeName, self::DATETIME, true)) {
            return ['format' => Field::FORMAT_DATETIME] + $result;
        }

        if (in_array($typeName, self::TIME, true)) {
            return ['format' => Field::FORMAT_TIME] + $result;
        }

        if ($typeName === 'enum' && preg_match('/^enum\((.*)\)$/i', (string) $column['type'], $m)) {
            preg_match_all("/'((?:[^'\\\\]|\\\\.|'')*)'/", $m[1], $values);

            return ['allowed' => array_map(fn ($v) => str_replace("''", "'", $v), $values[1])] + $result;
        }

        if (preg_match('/^(?:var)?char(?:acter)?(?: varying)?\((\d+)\)/', $fullType, $m)
            || preg_match('/^(?:nvarchar|nchar|bpchar)\((\d+)\)/', $fullType, $m)) {
            return ['maxLength' => (int) $m[1]] + $result;
        }

        return $result;
    }

    /**
     * @param  array{type: string, maxLength: ?int, allowed: list<string>, format: ?string}  $result
     * @return array{type: string, maxLength: ?int, allowed: list<string>, format: ?string}
     */
    private function applyCast(array $result, string $cast): array
    {
        [$base, $argument] = array_pad(explode(':', $cast, 2), 2, null);

        // Only namespaced names are cast classes: class_exists('datetime') is true because of \DateTime.
        if (str_contains($base, '\\') && (class_exists($base) || enum_exists($base))) {
            $class = ltrim($base, '\\');

            if (is_subclass_of($class, UnitEnum::class)) {
                return ['type' => '\\'.$class, 'maxLength' => null, 'allowed' => [], 'format' => null];
            }

            // AsArrayObject, AsCollection, AsEncryptedCollection, AsEnumCollection, ...
            if (str_starts_with($class, 'Illuminate\\Database\\Eloquent\\Casts\\As')
                && (str_contains($class, 'Collection') || str_contains($class, 'ArrayObject'))) {
                return ['type' => 'array', 'maxLength' => null, 'allowed' => [], 'format' => null];
            }

            // Custom cast classes: we cannot know the resulting type.
            return ['type' => 'mixed', 'maxLength' => null, 'allowed' => [], 'format' => null];
        }

        $base = strtolower($base);

        // encrypted:array, encrypted:collection, encrypted:object
        if ($base === 'encrypted' && $argument !== null) {
            $base = strtolower($argument);
        }

        return match ($base) {
            'int', 'integer', 'timestamp' => ['type' => 'int', 'format' => null] + $result,
            'real', 'float', 'double' => ['type' => 'float', 'format' => null] + $result,
            'decimal' => ['type' => 'string', 'format' => Field::FORMAT_NUMERIC] + $result,
            'bool', 'boolean' => ['type' => 'bool', 'format' => null] + $result,
            'array', 'json', 'object', 'collection' => ['type' => 'array', 'format' => null] + $result,
            'date', 'immutable_date' => ['type' => '\\'.CarbonInterface::class, 'format' => Field::FORMAT_DATE] + $result,
            'datetime', 'immutable_datetime', 'custom_datetime', 'immutable_custom_datetime' => ['type' => '\\'.CarbonInterface::class, 'format' => Field::FORMAT_DATETIME] + $result,
            'string', 'encrypted', 'hashed' => ['type' => 'string'] + $result,
            default => $result,
        };
    }
}
