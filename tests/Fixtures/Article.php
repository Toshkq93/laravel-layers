<?php

declare(strict_types=1);

namespace Toshkq93\LaravelLayers\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Article extends Model
{
    use SoftDeletes;

    protected $fillable = ['user_id', 'title', 'body', 'status', 'meta', 'published', 'published_at', 'secret', 'code_2fa', 'slug'];

    protected $hidden = ['secret'];

    protected function casts(): array
    {
        return [
            'status' => PostStatus::class,
            'meta' => 'array',
            'published' => 'boolean',
            'published_at' => 'datetime',
        ];
    }

    // Relation with a return type.
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    // Relation without a return type: found by the $this->hasMany( call.
    public function comments()
    {
        return $this->hasMany(Comment::class);
    }

    public function latestComment(): HasOne
    {
        return $this->hasOne(Comment::class)->latestOfMany();
    }

    // Polymorphic: related model unknown, skipped.
    public function imageable(): MorphTo
    {
        return $this->morphTo();
    }

    // Not relations: must not be picked up (or called).
    public function summary(): string
    {
        return 'summary';
    }

    public function explode()
    {
        throw new \LogicException('must not be called');
    }

    public function scopePublished($query)
    {
        return $query->where('published', true);
    }
}
