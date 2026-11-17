<?php

declare(strict_types=1);

namespace Toshkq93\LaravelLayers\Generation;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Toshkq93\LaravelLayers\Schema\ModelDefinition;

/**
 * Orders the models of one run so that a model comes after the models it
 * belongs to (Post belongsTo User: User first). Generation does not depend
 * on the order (related classes of the same run count as existing), but the
 * output reads top-down and factories / routes follow the foreign keys.
 *
 * Only belongsTo counts: the inverse relations (User hasMany Post) would make
 * every pair a cycle. Real cycles are broken at the first model met.
 */
final class ModelOrder
{
    /**
     * @param  list<ModelDefinition>  $models
     * @return list<ModelDefinition>
     */
    public static function sort(array $models): array
    {
        $byClass = [];

        foreach ($models as $model) {
            $byClass[$model->class] = $model;
        }

        $sorted = [];
        $visiting = [];

        $visit = function (ModelDefinition $model) use (&$visit, &$sorted, &$visiting, $byClass): void {
            if (isset($sorted[$model->class]) || isset($visiting[$model->class])) {
                return;
            }

            $visiting[$model->class] = true;

            foreach ($model->relations as $relation) {
                if (is_a($relation->type, BelongsTo::class, true) && isset($byClass[$relation->related])) {
                    $visit($byClass[$relation->related]);
                }
            }

            $sorted[$model->class] = $model;
        };

        foreach ($models as $model) {
            $visit($model);
        }

        return array_values($sorted);
    }
}
