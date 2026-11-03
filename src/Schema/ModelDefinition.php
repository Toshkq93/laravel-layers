<?php

declare(strict_types=1);

namespace Toshkq93\LaravelLayers\Schema;

use Illuminate\Support\Str;

/**
 * Everything known about a model after inspection.
 */
final class ModelDefinition
{
    /**
     * @param  class-string  $class
     * @param  array<string, Field>  $fields  keyed by column name, in table order
     */
    public function __construct(
        public readonly string $class,
        public readonly string $table,
        public readonly string $keyName,
        public readonly string $keyType,
        public readonly array $fields,
        public readonly bool $softDeletes = false,
        /** @var array<string, Relation> keyed by relation name */
        public readonly array $relations = [],
    ) {}

    /** Post */
    public function name(): string
    {
        return class_basename($this->class);
    }

    /** post — used for route parameters and variables */
    public function variable(): string
    {
        return Str::camel($this->name());
    }

    /** posts — used for the route URI */
    public function plural(): string
    {
        return Str::kebab(Str::pluralStudly($this->name()));
    }

    public function key(): Field
    {
        return $this->fields[$this->keyName];
    }

    /** @return array<string, Field> */
    public function writableFields(): array
    {
        return array_filter($this->fields, fn (Field $field) => $field->isWritable());
    }

    /** @return array<string, Field> */
    public function readableFields(): array
    {
        return array_filter($this->fields, fn (Field $field) => $field->isReadable());
    }
}
