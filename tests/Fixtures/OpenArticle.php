<?php

declare(strict_types=1);

namespace Toshkq93\LaravelLayers\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

/** Unguarded model: no $fillable, $guarded = []. */
class OpenArticle extends Model
{
    protected $table = 'articles';

    protected $guarded = [];
}
