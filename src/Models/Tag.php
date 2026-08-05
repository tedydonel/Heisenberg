<?php

declare(strict_types=1);

namespace Heisenberg\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * A flat tag (blueprint §2.3.4 `BlogTag`) — no parent, bilingual `name_en`/
 * `name_fr` on one row (same as Category). BelongsToMany `Post` via the
 * `heisenberg_post_tag` pivot (blueprint §2.3.1, §2.4) — contrast with
 * Category, which is a per-post BelongsTo (see `Post::category()`), not a
 * pivot. See Category's docblock for the fuller many-to-many-vs-one reasoning
 * and the SoftDeletes deviation from the blueprint's "(no SD)" annotation
 * (identical rationale applies here).
 */
class Tag extends Model
{
    use SoftDeletes;

    protected $fillable = ['name_en', 'name_fr', 'slug'];

    public function getTable(): string
    {
        return config('heisenberg.tables.tags', 'heisenberg_tags');
    }

    protected static function booted(): void
    {
        static::creating(function (Tag $tag): void {
            if (empty($tag->slug)) {
                $source = $tag->name_en ?: ($tag->name_fr ?: 'tag');
                $tag->slug = Str::slug($source) ?: 'tag';
            }

            $tag->slug = static::uniqueSlug($tag->slug);
        });

        // Same rationale as Category::booted() — no auto-reslug-on-name-change,
        // but an explicitly changed slug is still deduplicated.
        static::updating(function (Tag $tag): void {
            if (! $tag->isDirty('slug')) {
                return;
            }

            $tag->slug = static::uniqueSlug($tag->slug, $tag->getKey());
        });
    }

    /** Mirrors Category::uniqueSlug() — see that docblock and the migration's for the reasoning. */
    private static function uniqueSlug(string $slug, int|string|null $ignoreId = null): string
    {
        $base = $slug;
        $suffix = 1;

        while (static::withTrashed()
            ->where('slug', $slug)
            ->when($ignoreId !== null, fn ($q) => $q->whereKeyNot($ignoreId))
            ->exists()) {
            $suffix++;
            $slug = $base . '-' . $suffix;
        }

        return $slug;
    }

    /** Every post carrying this tag (blueprint §2.3.4 `posts()`). */
    public function posts(): BelongsToMany
    {
        return $this->belongsToMany(
            config('heisenberg.models.post', Post::class),
            config('heisenberg.tables.post_tag', 'heisenberg_post_tag'),
            'tag_id',
            'post_id'
        );
    }
}
