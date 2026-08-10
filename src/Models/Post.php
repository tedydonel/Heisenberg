<?php

declare(strict_types=1);

namespace Heisenberg\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * The post aggregate root (blueprint §2.3.1) — focused subset. `content_version`
 * is deliberately NOT fillable (server-only optimistic-lock counter, bumped via
 * increment). The users FK is a host concern (config), so author_id is plain here.
 *
 * Cascade soft-delete/restore (blueprint §2.3.1, §2.4): `delete()`/`restore()`
 * are overridden below to keep `blocks` and `revisions` in lockstep with the
 * post via a shared `deleted_batch_id` UUID — see the method docblocks.
 */
class Post extends Model
{
    use SoftDeletes {
        restore as private softDeletesRestore;
    }

    // page_padding_x/page_padding_y/allow_comments/featured_image_id are deliberately NOT fillable —
    // same "never mass-assignable via PostController's generic content save" posture the old
    // category_id column had (see PostTaxonomyRelationsTest's docblock): PostSettingsController
    // sets them via direct property assignment, the only path that may write them.
    protected $fillable = [
        'translation_group_id', 'author_id', 'locale',
        'title_en', 'title_fr', 'slug',
        'excerpt_en', 'excerpt_fr',
        'rendered_html_en', 'rendered_html_fr',
        'status', 'published_at', 'scheduled_at',
    ];

    protected $casts = [
        'published_at' => 'datetime',
        'scheduled_at' => 'datetime',
        'content_version' => 'integer',
        'page_padding_x' => 'integer',
        'page_padding_y' => 'integer',
        'allow_comments' => 'boolean',
    ];

    public function getTable(): string
    {
        return config('heisenberg.tables.posts', 'heisenberg_posts');
    }

    protected static function booted(): void
    {
        static::creating(function (Post $post): void {
            if (empty($post->translation_group_id)) {
                $post->translation_group_id = (string) Str::uuid();
            }
            if (empty($post->slug)) {
                $source = $post->title_en ?: ($post->title_fr ?: 'untitled');
                $post->slug = Str::slug($source) ?: 'untitled';
            }

            $post->slug = static::uniqueSlug($post->slug, $post->locale ?: 'en');
        });

        // Keep a DRAFT's slug following its title. Without this the slug was frozen at whatever
        // the title happened to be on the very first save — which, for a post named after it was
        // created, meant it stayed "untitled-post" forever.
        //
        // Draft only, deliberately: once a post is published its URL is public, and silently
        // rewriting it on a typo fix would break every inbound link. An explicitly-set slug is
        // also respected — if the caller is changing `slug` itself, that wins over the title.
        static::updating(function (Post $post): void {
            if ($post->status !== 'draft' || $post->isDirty('slug')) {
                return;
            }
            if (! $post->isDirty('title_en') && ! $post->isDirty('title_fr')) {
                return;
            }

            $source = $post->title_en ?: ($post->title_fr ?: 'untitled');
            $base = Str::slug($source) ?: 'untitled';
            if ($base === $post->getOriginal('slug')) {
                return; // already correct — don't churn a `-2` suffix onto it
            }

            $post->slug = static::uniqueSlug($base, $post->locale ?: 'en', $post->getKey());
        });
    }

    /**
     * Numeric-suffix collision handling for the `['locale', 'slug']` unique index
     * (migration 2026_07_28_000001): appends `-2`, `-3`, … until a free slug is
     * found. Checked with `withTrashed()` because the unique index itself spans
     * trashed rows too (see that migration's docblock for why) — a slug held by
     * a soft-deleted post is not up for grabs.
     */
    private static function uniqueSlug(string $slug, string $locale, int|string|null $ignoreId = null): string
    {
        $base = $slug;
        $suffix = 1;

        while (static::withTrashed()
            ->where('locale', $locale)
            ->where('slug', $slug)
            ->when($ignoreId !== null, fn ($q) => $q->whereKeyNot($ignoreId)) // a row never collides with itself
            ->exists()) {
            $suffix++;
            $slug = $base . '-' . $suffix;
        }

        return $slug;
    }

    public function blocks(): HasMany
    {
        return $this->hasMany(config('heisenberg.models.block', Block::class), 'post_id')->ordered();
    }

    /** Point-in-time snapshots of this post (blueprint §2.3.5) — newest first. */
    public function revisions(): HasMany
    {
        return $this->hasMany(config('heisenberg.models.revision', Revision::class), 'post_id')->latest();
    }

    /**
     * Categories assigned to this post (2026-08-03: BelongsToMany via the `heisenberg_category_post`
     * pivot, migration 2026_08_03_000001) — same shape as tags() below now; see Category's own
     * docblock for why this superseded the blueprint's originally-ported single `category_id`
     * BelongsTo (migration 2026_07_28_000006, now dropped by migration 2026_08_03_000002).
     *
     * NOT part of the deleted_batch_id cascade (delete()/restore() below): categories are an
     * "independent taxonom[y]" a post merely references (blueprint §2.1), not owned content the
     * way blocks/revisions are — same reasoning as tags() below. A soft-deleted post keeps its
     * category pivot rows untouched, ready to reappear on restore(); a force-deleted post's pivot
     * rows are removed by the pivot's own cascadeOnDelete() FK, not by delete()/restore() here.
     */
    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(
            config('heisenberg.models.category', Category::class),
            config('heisenberg.tables.category_post', 'heisenberg_category_post'),
            'post_id',
            'category_id'
        );
    }

    /**
     * Tags attached to this post (blueprint §2.3.1 `tags()` BelongsToMany via
     * the `heisenberg_post_tag` pivot). Same "independent taxonomy, not part
     * of the soft-delete cascade" reasoning as category() above — a
     * force-deleted post's pivot rows are removed by the pivot's own
     * cascadeOnDelete() FK (migration 2026_07_28_000005), not by
     * Post::delete()/restore(); a soft-deleted post keeps its tag
     * associations intact.
     */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(
            config('heisenberg.models.tag', Tag::class),
            config('heisenberg.tables.post_tag', 'heisenberg_post_tag'),
            'post_id',
            'tag_id'
        );
    }

    /**
     * The Post tab's featured image (Post Settings → Featured image disclosure). NULL means "no
     * featured image picked" — the inspector's dropzone trigger renders instead of the preview.
     * The image itself lives on heisenberg_public_files; this is just the FK pointer. Same
     * "independent referenced row, not owned content" posture as categories()/tags() above: a
     * featured image can be deleted without losing the post (FK nullOnDelete, see the migration
     * that adds featured_image_id), and a force-deleted post can leave its featured image
     * intact for reuse elsewhere.
     */
    public function featuredImage(): BelongsTo
    {
        return $this->belongsTo(
            config('heisenberg.models.public_file', PublicFile::class),
            'featured_image_id'
        );
    }

    /**
     * The Post tab's AUTHORED table of contents (blueprint §2.3.10 `BlogPostTocEntry`) —
     * ordered rows written wholesale by PostSettingsController::updateToc(). Distinct from the
     * `tableOfContents` capability's `source: "headings"` derivation (docs/post-template-schema.md):
     * that one is computed from the block tree at render time and needs no storage; this is the
     * editorially-curated counterpart (`source: "entries"`), and the post's TOC renders ONLY when
     * this relation is non-empty. Same "independent, not part of the soft-delete cascade" posture
     * as categories()/tags() above is NOT the case here — toc_entries FK is cascadeOnDelete at the
     * DB level (see the migration), so a hard-deleted post takes its entries with it; nothing in
     * Post::delete()/restore() needs to touch them since a soft-delete never issues that DELETE.
     */
    public function tocEntries(): HasMany
    {
        return $this->hasMany(config('heisenberg.models.toc_entry', TocEntry::class), 'post_id')->orderBy('order');
    }

    /** Atomic bump of the optimistic-lock counter (autosave, §2.3.1). */
    public function bumpContentVersion(): void
    {
        $this->increment('content_version');
    }

    /**
     * Cascade soft-delete (blueprint §2.3.1 "the cascade soft-delete mechanism",
     * §2.4): wraps in a transaction, stamps a fresh `deleted_batch_id` UUID onto
     * every currently-active block and revision belonging to this post AND onto
     * the post itself, soft-deletes the children, then soft-deletes the post.
     *
     * Why this is needed: the `blocks`/`revisions` FK cascades at the DB level
     * (`cascadeOnDelete()`), but that only fires on a genuine SQL DELETE. A
     * SoftDeletes `delete()` is an UPDATE (sets `deleted_at`) — no DB cascade
     * fires, so without this override a trashed post's children stay live
     * forever (only `forceDelete()` actually cascaded).
     *
     * `forceDeleting` is checked first and always defers straight to
     * `parent::delete()`: {@see SoftDeletes::forceDelete()} sets that flag and
     * then calls `$this->delete()`, so an unconditional override here would
     * incorrectly soft-delete the children before the post is hard-deleted,
     * and the DB-level FK cascade (which correctly removes children of any
     * trashed state) would never get the chance to run.
     */
    public function delete(): bool
    {
        if (! $this->exists || $this->forceDeleting || $this->trashed()) {
            return (bool) parent::delete();
        }

        $batchId = (string) Str::uuid();

        return (bool) $this->getConnection()->transaction(function () use ($batchId): bool {
            $now = $this->freshTimestampString();

            $this->childQuery(config('heisenberg.models.block', Block::class))
                ->update(['deleted_batch_id' => $batchId, 'deleted_at' => $now]);
            $this->childQuery(config('heisenberg.models.revision', Revision::class))
                ->update(['deleted_batch_id' => $batchId, 'deleted_at' => $now]);

            $this->deleted_batch_id = $batchId;
            $this->save();

            return (bool) parent::delete();
        });
    }

    /**
     * Cascade restore: restores the post, then restores only the blocks and
     * revisions that share the post's own `deleted_batch_id` — i.e. only the
     * children trashed in THIS post's own {@see delete()} call, via
     * `withTrashed()`. `deleted_batch_id` is deliberately left in place after
     * restore (not nulled out) — it is overwritten with a fresh UUID the next
     * time this post is soft-deleted, and until then it simply records the
     * most recent deletion batch, which is harmless.
     */
    public function restore(): bool
    {
        $batchId = $this->deleted_batch_id;

        $restored = (bool) $this->softDeletesRestore();

        if ($restored && $batchId !== null) {
            $this->childQuery(config('heisenberg.models.block', Block::class))
                ->withTrashed()->where('deleted_batch_id', $batchId)->restore();
            $this->childQuery(config('heisenberg.models.revision', Revision::class))
                ->withTrashed()->where('deleted_batch_id', $batchId)->restore();
        }

        return $restored;
    }

    /** A plain (unordered) query for a child model scoped to this post — see delete()/restore(). */
    private function childQuery(string $modelClass): Builder
    {
        return $modelClass::query()->where('post_id', $this->getKey());
    }
}
