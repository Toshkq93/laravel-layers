<?php

declare(strict_types=1);

namespace Toshkq93\LaravelLayers\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

class Post extends Model
{
    protected $fillable = ['title', 'body', 'published'];

    protected $casts = ['published' => 'boolean'];
}
