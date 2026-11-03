<?php

declare(strict_types=1);

namespace Toshkq93\LaravelLayers\Schema;

/**
 * One model attribute (table column) with everything generators need
 * to render a DTO property and its validation.
 */
final class Field
{
    public const FORMAT_UUID = 'uuid';
    public const FORMAT_DATE = 'date';
    public const FORMAT_DATETIME = 'datetime';
    public const FORMAT_TIME = 'time';
    public const FORMAT_NUMERIC = 'numeric';

    /**
     * @param  string  $type  PHP type without nullability: int, string, bool, float, array, mixed or a FQCN
     * @param  list<string>  $allowed  Allowed values for DB enum columns
     * @param  array{table: string, column: string}|null  $references  Foreign key target
     */
    public function __construct(
        public readonly string $name,
        public readonly string $type,
        public readonly bool $nullable = false,
        public readonly bool $hasDefault = false,
        public readonly bool $primary = false,
        public readonly bool $autoIncrement = false,
        public readonly bool $timestamp = false,
        public readonly bool $hidden = false,
        public readonly bool $fillable = true,
        public readonly ?int $maxLength = null,
        public readonly array $allowed = [],
        public readonly ?string $format = null,
        public readonly ?array $references = null,
        public readonly ?string $cast = null,
        public readonly bool $readOnly = false,
        public readonly bool $unique = false,
    ) {}

    /** Whether the client may send this field (store / update DTOs). */
    public function isWritable(): bool
    {
        return ! $this->primary && ! $this->timestamp && ! $this->autoIncrement && ! $this->readOnly && $this->fillable;
    }

    /** Whether the field is exposed in the output DTO. */
    public function isReadable(): bool
    {
        return ! $this->hidden;
    }

    /** Required on create: not nullable and the DB has no default. */
    public function isRequiredOnCreate(): bool
    {
        return ! $this->nullable && ! $this->hasDefault;
    }

    public function isClassType(): bool
    {
        return str_contains($this->type, '\\');
    }

    public function isEnumClass(): bool
    {
        return $this->isClassType() && enum_exists($this->type);
    }
}
