<?php

declare(strict_types=1);

namespace Heisenberg\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One block row (blueprint §2.3.2). `content` is a JSON column — the single
 * source of truth for structured content. No host coupling.
 */
class Block extends Model
{
    use SoftDeletes;

    protected $fillable = ['post_id', 'type', 'content', 'order'];

    protected $casts = [
        'content' => 'array',
        'order' => 'integer',
        'deleted_at' => 'datetime',
    ];

    public function getTable(): string
    {
        return config('heisenberg.tables.blocks', 'heisenberg_blocks');
    }

    public function post(): BelongsTo
    {
        return $this->belongsTo(config('heisenberg.models.post', Post::class), 'post_id');
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('order');
    }
}
