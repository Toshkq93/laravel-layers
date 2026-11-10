<?php

declare(strict_types=1);

namespace Toshkq93\LaravelLayers\Schema;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use RuntimeException;

/**
 * Builds a ModelDefinition from a model class and its table in the database.
 *
 * Columns and their types come from the table (Schema::getColumns()), not
 * from the model. The model only refines them: casts (types), $hidden
 * (output), $fillable / $guarded (input), key name, timestamps and soft deletes.
 */
class ModelInspector
{
    /**
     * @param  list<string>  $readOnlyColumns  Never accepted from the client, whatever $fillable / $guarded say
     */
    public function __construct(
        private readonly TypeMapper $mapper = new TypeMapper,
        private readonly array $readOnlyColumns = ['deleted_at', 'remember_token'],
        private readonly RelationInspector $relations = new RelationInspector,
    ) {}

    /**
     * @param  class-string<Model>  $class
     */
    public function inspect(string $class): ModelDefinition
    {
        if (! class_exists($class) || ! is_subclass_of($class, Model::class)) {
            throw new InvalidArgumentException("[{$class}] is not an Eloquent model.");
        }

        /** @var Model $model */
        $model = new $class;
        $table = $model->getTable();
        $schema = Schema::connection($model->getConnectionName());

        if (! $schema->hasTable($table)) {
            throw new RuntimeException("Table [{$table}] for model [{$class}] does not exist. Run the migrations first.");
        }

        $casts = $model->getCasts();
        $timestamps = $this->timestampColumns($model);
        $foreignKeys = $this->foreignKeys($schema->getForeignKeys($table));
        $unique = $this->uniqueColumns($schema->getIndexes($table));
        $hidden = $model->getHidden();
        $fillable = $model->getFillable();
        $guardedAll = $fillable === [] && $model->getGuarded() === ['*'];

        $fields = [];

        foreach ($schema->getColumns($table) as $column) {
            $name = $column['name'];
            $cast = $casts[$name] ?? null;

            if ($cast === null && in_array($name, $timestamps, true)) {
                $cast = 'datetime';
            }

            $mapped = $this->mapper->map($column, $cast);

            $fields[$name] = new Field(
                name: $name,
                type: $mapped['type'],
                nullable: (bool) $column['nullable'],
                hasDefault: $column['default'] !== null,
                primary: $name === $model->getKeyName(),
                autoIncrement: (bool) $column['auto_increment'],
                timestamp: in_array($name, $timestamps, true),
                hidden: in_array($name, $hidden, true),
                fillable: match (true) {
                    $fillable !== [] => in_array($name, $fillable, true),
                    // No fillable list and everything guarded (Model default): treat every column as writable.
                    $guardedAll => true,
                    // $guarded = [] or a list of guarded columns.
                    default => ! in_array($name, $model->getGuarded(), true),
                },
                readOnly: in_array($name, $this->readOnlyColumns, true),
                unique: in_array($name, $unique, true),
                maxLength: $mapped['maxLength'],
                allowed: $mapped['allowed'],
                format: $mapped['format'],
                references: $foreignKeys[$name] ?? null,
                cast: $cast,
            );
        }

        return new ModelDefinition(
            class: $class,
            table: $table,
            keyName: $model->getKeyName(),
            keyType: $model->getKeyType(),
            fields: $fields,
            softDeletes: $this->usesSoftDeletes($model),
            relations: $this->relations->inspect($model),
            routeKeyName: $this->routeKeyName($model, $fields),
        );
    }

    /**
     * getRouteKeyName() when it differs from the primary key and is a real
     * column of the table; otherwise records are addressed by the key.
     *
     * @param  array<string, Field>  $fields
     */
    private function routeKeyName(Model $model, array $fields): ?string
    {
        $name = $model->getRouteKeyName();

        return $name !== $model->getKeyName() && isset($fields[$name]) ? $name : null;
    }

    private function usesSoftDeletes(Model $model): bool
    {
        return in_array(SoftDeletes::class, class_uses_recursive($model), true);
    }

    /** @return list<string> */
    private function timestampColumns(Model $model): array
    {
        $columns = [];

        if ($model->usesTimestamps()) {
            $columns[] = $model->getCreatedAtColumn();
            $columns[] = $model->getUpdatedAtColumn();
        }

        if ($this->usesSoftDeletes($model)) {
            $columns[] = $model->getDeletedAtColumn();
        }

        return array_values(array_filter($columns));
    }

    /**
     * Columns with their own unique index. Composite unique indexes
     * (a, b) cannot be expressed as a rule on one property and are skipped;
     * the primary key is unique by definition and never sent by the client.
     *
     * @return list<string>
     */
    private function uniqueColumns(array $indexes): array
    {
        $columns = [];

        foreach ($indexes as $index) {
            if ($index['unique'] && ! $index['primary'] && count($index['columns']) === 1) {
                $columns[] = $index['columns'][0];
            }
        }

        return $columns;
    }

    /**
     * Single-column foreign keys indexed by local column.
     *
     * @return array<string, array{table: string, column: string}>
     */
    private function foreignKeys(array $foreignKeys): array
    {
        $result = [];

        foreach ($foreignKeys as $fk) {
            if (count($fk['columns']) === 1 && count($fk['foreign_columns']) === 1) {
                $result[$fk['columns'][0]] = [
                    'table' => $fk['foreign_table'],
                    'column' => $fk['foreign_columns'][0],
                ];
            }
        }

        return $result;
    }
}
