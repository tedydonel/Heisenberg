<?php

declare(strict_types=1);

namespace Heisenberg\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * A self-parenting category tree (blueprint §2.3.3 `BlogCategory`) — bilingual
 * `name_en`/`name_fr` (and `description_en`/`description_fr`) live on ONE row,
 * unlike `Post` which splits one row per locale.
 *
 * DEVIATION FROM BLUEPRINT (2026-08-03): a post now carries any number of categories —
 * `posts()` is BelongsToMany via the `heisenberg_category_post` pivot (migration
 * 2026_08_03_000001), matching `Tag::posts()`'s shape. The blueprint (§2.3.1, §2.4) documents
 * the ORIGINAL ported host app's `category_id` BelongsTo as deliberate, single-category-only —
 * see migration 2026_07_28_000006's docblock for that original reasoning — but the editor's
 * Categories field is now a Gutenberg-style multi-select checklist, which a single FK column
 * cannot back. See migration 2026_08_03_000002 for the `category_id` column's removal.
 *
 * DEVIATION FROM BLUEPRINT: adds SoftDeletes, which §2.4/§2.3.3 document the
 * legacy `blog_categories` table/model as NOT having ("no SD"). See the
 * creating migration's docblock (2026_07_28_000003) for the full reasoning
 * (package-wide consistency — every other Heisenberg content model already
 * soft-deletes, and nothing in the blueprint flags the no-SD behavior as a
 * `[TARGET]` to preserve).
 */
class Category extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'parent_id', 'name_en', 'name_fr', 'slug',
        'description_en', 'description_fr', 'order',
    ];

    protected $casts = [
        'order' => 'integer',
    ];

    public function getTable(): string
    {
        return config('heisenberg.tables.categories', 'heisenberg_categories');
    }

    protected static function booted(): void
    {
        static::creating(function (Category $category): void {
            if (empty($category->slug)) {
                $source = $category->name_en ?: ($category->name_fr ?: 'category');
                $category->slug = Str::slug($source) ?: 'category';
            }

            $category->slug = static::uniqueSlug($category->slug);
        });

        // Unlike Post, a category's slug does not auto-follow its name after
        // creation — the blueprint only documents a `creating` auto-slug hook
        // for BlogCategory/BlogTag (no draft/title-tracking concept applies
        // here). An EXPLICITLY changed slug is still deduplicated so an
        // update can never violate the unique index below — it would
        // otherwise surface as a raw QueryException instead of a friendly,
        // predictable numeric-suffix fallback.
        static::updating(function (Category $category): void {
            if (! $category->isDirty('slug')) {
                return;
            }

            $category->slug = static::uniqueSlug($category->slug, $category->getKey());
        });
    }

    /**
     * Numeric-suffix collision handling, mirroring Post::uniqueSlug() minus
     * the locale axis (a category has none) — see the creating migration's
     * docblock for why a bare `unique('slug')` (checked here via
     * withTrashed()) is the correct scope: a slug held by a soft-deleted
     * category stays reserved until the row is force-deleted.
     */
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

    public function parent(): BelongsTo
    {
        return $this->belongsTo(config('heisenberg.models.category', self::class), 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(config('heisenberg.models.category', self::class), 'parent_id');
    }

    /** Every post assigned to this category (blueprint §2.3.4-style `posts()`) — see Post::categories() (BelongsToMany). */
    public function posts(): BelongsToMany
    {
        return $this->belongsToMany(
            config('heisenberg.models.post', Post::class),
            config('heisenberg.tables.category_post', 'heisenberg_category_post'),
            'category_id',
            'post_id'
        );
    }

    /**
     * Every ancestor id up the tree — half of the cycle-prevention pair used
     * by CategoryController::update() (a category can't be re-parented onto
     * one of its own descendants; the docs/BLUEPRINT.md TaxonomyService
     * describes this same guard for `updateCategory`).
     *
     * @return array<int, int|string>
     */
    public function getAncestorIds(): array
    {
        $ids = [];
        $current = $this->parent;

        while ($current !== null) {
            if (in_array($current->getKey(), $ids, true)) {
                break; // guards against a pre-existing corrupt cycle in the data
            }
            $ids[] = $current->getKey();
            $current = $current->parent;
        }

        return $ids;
    }

    /**
     * Every descendant id down the tree — the other half of cycle prevention:
     * a category cannot be reassigned as a child of one of its own
     * descendants.
     *
     * @return array<int, int|string>
     */
    public function getDescendantIds(): array
    {
        $ids = [];

        foreach ($this->children as $child) {
            $ids[] = $child->getKey();
            $ids = array_merge($ids, $child->getDescendantIds());
        }

        return $ids;
    }
}
