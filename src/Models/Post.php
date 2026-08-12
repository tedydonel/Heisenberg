<?php

declare(strict_types=1);

namespace Heisenberg\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
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
    //
    // `translated_from_version` (migration 2026_08_11_000002) is ALSO deliberately not fillable —
    // same posture as `content_version` itself: it is a snapshot of another row's optimistic-lock
    // counter, meaningful only when set by the translation workflow (create/update-translation,
    // Wave T2 of docs/content-translation.md) at the exact moment content is copied. Letting it
    // ride in through a generic mass-assign (e.g. PostController's save) would let arbitrary
    // content edits silently mark/unmark a row as "outdated", which is a workflow signal, not a
    // content field. Direct property write (`$post->translated_from_version = …; $post->save();`)
    // is the only path, same as `status`.
    // `type` (migration 2026_08_11_000004, docs/email-system.md §3) is ALSO deliberately not
    // fillable — see this class's own docblock. Direct property write only
    // (`$post->type = 'email'; $post->save();`); McpToolRegistry::writePost() is the one place
    // that does this today, gated to a fresh (create-only) post.
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
        'translated_from_version' => 'integer',
        'page_padding_x' => 'integer',
        'page_padding_y' => 'integer',
        'allow_comments' => 'boolean',
    ];

    public function getTable(): string
    {
        return config('heisenberg.tables.posts', 'heisenberg_posts');
    }

    /**
     * The group row for `$locale` (docs/content-translation.md §2, blueprint §2.3.1 `sibling()`).
     * Deliberately a plain query method, not an Eloquent relation with `withDefault()` (the
     * blueprint's ported shape): a `BelongsTo`/`HasOne`-with-default needs a fixed FK column to
     * join on, but "the other locale" isn't a column — it's "any other row sharing
     * `translation_group_id`, optionally filtered to one locale" — which a relation can't express
     * without hardcoding a two-locale assumption into the relation definition itself. A query
     * method also composes better once locales generalize past en/fr (§3): `sibling('de')` just
     * works, no new relation method needed per locale.
     *
     * `$locale = null` → "the other" row: the first sibling found, any locale (today that IS the
     * single other locale; with 3+ locales a caller wanting a specific one should pass it).
     * `$locale` given → that locale's row, or null if untranslated into it. Never returns `$this`.
     */
    public function sibling(?string $locale = null): ?self
    {
        if (empty($this->translation_group_id)) {
            return null;
        }

        return static::query()
            ->inGroup($this->translation_group_id)
            ->when($locale !== null, fn (Builder $q) => $q->forLocale($locale))
            ->whereKeyNot($this->getKey())
            ->first();
    }

    /**
     * Every other row in this post's translation group (any locale), excluding this row itself.
     * A query method for the same reason `sibling()` above is — with 2 locales this returns at
     * most one row (today's `sibling(null)`), but it stays correct once a group can hold 3+ rows.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, self>
     */
    public function siblings(): \Illuminate\Database\Eloquent\Collection
    {
        if (empty($this->translation_group_id)) {
            return static::query()->whereRaw('1 = 0')->get();
        }

        return static::query()
            ->inGroup($this->translation_group_id)
            ->whereKeyNot($this->getKey())
            ->get();
    }

    /** Rows whose `locale` matches `$locale` (blueprint §2.3.1). */
    public function scopeForLocale(Builder $q, string $locale): Builder
    {
        return $q->where('locale', $locale);
    }

    /** Rows sharing a `translation_group_id` (blueprint §2.3.1). */
    public function scopeInGroup(Builder $q, string $uuid): Builder
    {
        return $q->where('translation_group_id', $uuid);
    }

    /**
     * Rows authored as a blog/page document — `type = 'post'` (docs/email-system.md §3, migration
     * 2026_08_11_000004). Every listing surface Heisenberg itself lists content through (sitemap,
     * MCP `list_posts` default) uses this scope so an email document never leaks into a blog/page
     * listing by accident. A pre-migration row has `type` defaulted to `'post'` by the column's own
     * DB default, so this scope is safe to add to an existing query without a backfill.
     */
    public function scopePosts(Builder $q): Builder
    {
        return $q->where('type', 'post');
    }

    /** The `scopePosts()` counterpart — rows authored as an email document (`type = 'email'`). */
    public function scopeEmails(Builder $q): Builder
    {
        return $q->where('type', 'email');
    }

    /**
     * This row's title, own-locale column first, falling back to the other locale's column, then
     * `''` (never null — same "always a string" posture as the blueprint's title accessor).
     * `$locale` defaults to the ROW'S OWN locale (not the app/request locale) — this reads what
     * a specific translation row calls itself, not "translate this post for the current viewer".
     *
     * Named `title()` (a plain method, not `getTitleAttribute()`/`$post->title`): there is no
     * `title` column, only `title_en`/`title_fr`, so a magic accessor would silently shadow
     * nothing today but invite a future `title_de` column to break the illusion of a real
     * attribute. An explicit method makes the locale-fallback behavior visible at the call site.
     */
    public function title(?string $locale = null): string
    {
        $locale = $locale ?: (string) ($this->locale ?: 'en');
        $primary = $locale === 'fr' ? $this->title_fr : $this->title_en;
        $fallback = $locale === 'fr' ? $this->title_en : $this->title_fr;

        return (string) ($primary ?: ($fallback ?: ''));
    }

    /**
     * This row's excerpt, same own-locale-first/cross-locale-fallback posture as `title()` above
     * (mirrors `PublicFile::getAlt()`), but nullable — an excerpt has no "untitled" equivalent to
     * fall back to, so both columns empty legitimately means "no excerpt".
     *
     * Named `excerptText()`, NOT `excerpt()`: `$fillable` already carries `excerpt_en`/`excerpt_fr`
     * as real columns, and a same-named `excerpt()` method sitting next to them reads as if it
     * might be *the* `excerpt` attribute/column — it isn't, there is no bare `excerpt` column.
     * `excerptText()` makes clear this is a derived, locale-resolved value, not attribute access.
     */
    public function excerptText(?string $locale = null): ?string
    {
        $locale = $locale ?: (string) ($this->locale ?: 'en');
        $primary = $locale === 'fr' ? $this->excerpt_fr : $this->excerpt_en;
        $fallback = $locale === 'fr' ? $this->excerpt_en : $this->excerpt_fr;

        return $primary ?: ($fallback ?: null);
    }

    /**
     * True when this row's translated content has fallen behind its source. `translated_from_version`
     * (migration 2026_08_11_000002) records the source sibling's `content_version` at the moment
     * this row was last translated FROM it; null means "never machine/workflow-translated" (hand
     * authored, or pre-existing before this feature) — never outdated, there is nothing to compare.
     *
     * We do not store WHICH sibling was the source (only its version number), so the exact rule
     * is an approximation: outdated when translated_from_version is non-null AND at least one
     * sibling's current content_version exceeds it. With today's two-locale cap this is exact —
     * `sibling(null)` can only resolve to the one row that could possibly be the source. It stops
     * being exact if/when a group ever holds 3+ rows (§3, out of scope for now) and would need to
     * start tracking the source locale explicitly.
     */
    public function isTranslationOutdated(): bool
    {
        if ($this->translated_from_version === null) {
            return false;
        }

        $source = $this->sibling();

        return $source !== null && (int) $source->content_version > (int) $this->translated_from_version;
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

        // Regenerate from the title ONLY when the slug is empty. This used to also fire on every
        // DRAFT title edit (a "keep the slug following the title" feature), but that clobbered an
        // explicitly-set custom slug the next time the title changed — the slug attribute isn't
        // touched by a title-only save, so `isDirty('slug')` read false and the old title-tracking
        // branch happily overwrote it. There's no separate "is this slug custom" flag, so the
        // simplest honest rule is: once a slug exists, it only changes when the caller explicitly
        // sets it (PostController::applySlug — `slug: ''` is the caller's own way of asking for a
        // fresh, title-derived one, which lands here with an emptied `slug` right before save()).
        //
        // SHARED-SLUG INVARIANT (docs/content-translation.md §1): a translation group presents as
        // ONE post, so every sibling must carry the IDENTICAL slug — locale comes from the host's
        // URL prefix, never from the slug text. A regeneration here is, like an explicit rename via
        // PostController::applySlug(), a group-wide event, not a single-row one. Simplest correct
        // rule (documented here rather than reached for something fancier): the base string is
        // still derived from THIS row's own title — there is no separate notion of "the group's
        // canonical source title" to prefer, any sibling can trigger a regen and its own title is
        // as good a starting point as another's — but the candidate is unique-checked against the
        // UNION of this row's locale and every sibling's locale (not just this row's own), so the
        // winning string is guaranteed free everywhere it's about to land. The result is then
        // written onto every sibling directly via a plain query UPDATE, not another ->save() (that
        // would re-enter this very hook for no reason — a sibling's title is untouched, only its
        // slug column changes).
        static::updating(function (Post $post): void {
            if ((string) $post->slug !== '') {
                return;
            }

            $siblings = $post->siblings();
            $source = $post->title_en ?: ($post->title_fr ?: 'untitled');
            $base = Str::slug($source) ?: 'untitled';
            $locales = $siblings->pluck('locale')->push($post->locale ?: 'en')->unique()->all();
            $ignoreIds = $siblings->pluck($post->getKeyName())->push($post->getKey())->all();

            $post->slug = static::uniqueSlugAmongLocales($base, $locales, $ignoreIds);

            if ($siblings->isNotEmpty()) {
                static::query()
                    ->whereKey($siblings->pluck($post->getKeyName())->all())
                    ->update(['slug' => $post->slug]);
            }
        });
    }

    /**
     * Numeric-suffix collision handling for the `['locale', 'slug']` unique index
     * (migration 2026_07_28_000001): appends `-2`, `-3`, … until a free slug is
     * found in `$locale`. Thin wrapper over {@see self::uniqueSlugAmongLocales()}
     * for the (still common) single-locale case — creating a brand-new post has
     * no siblings yet, so there is only ever one locale to check.
     */
    private static function uniqueSlug(string $slug, string $locale, int|string|null $ignoreId = null): string
    {
        return static::uniqueSlugAmongLocales($slug, [$locale], $ignoreId === null ? [] : [$ignoreId]);
    }

    /**
     * Same numeric-suffix collision handling as {@see self::uniqueSlug()}, generalized to check
     * a slug across MULTIPLE locales at once (the `updating` hook's group-wide regeneration
     * above, where a candidate must be free in this row's locale AND every sibling's). Checked
     * with `withTrashed()` because the unique index itself spans trashed rows too (see the
     * migration's docblock for why) — a slug held by a soft-deleted post is not up for grabs.
     *
     * @param list<string> $locales
     * @param list<int|string> $ignoreIds rows that never collide with the candidate (the row(s)
     *   the slug is being written onto — they currently hold the OLD value, which must not count).
     */
    private static function uniqueSlugAmongLocales(string $slug, array $locales, array $ignoreIds = []): string
    {
        $base = $slug;
        $suffix = 1;

        while (static::withTrashed()
            ->whereIn('locale', $locales)
            ->where('slug', $slug)
            ->when($ignoreIds !== [], fn (Builder $q) => $q->whereKeyNot($ignoreIds))
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

    /**
     * The polymorphic SEO/meta row for this post (docs/seo-system.md §3, Wave S2a) — one row
     * per `(able_type, able_id)`, {@see \Heisenberg\Models\SeoMeta}'s own unique index. `null`
     * until the SEO/Social panel's first save creates it (PostController::applySeo()
     * `updateOrCreate`s, never a plain `create()`) — same "no row until something is actually
     * saved" posture as {@see featuredImage()}'s nullable BelongsTo. Model class is
     * config-swappable (`heisenberg.models.seo_meta`), same convention every other relation
     * here follows.
     */
    public function seoMeta(): MorphOne
    {
        return $this->morphOne(config('heisenberg.models.seo_meta', \Heisenberg\Models\SeoMeta::class), 'able');
    }

    /**
     * This post's comments (blueprint §2.3.1 `comments()`), unordered/unfiltered — the raw
     * relation. {@see \Heisenberg\Adapters\NativeCommentProvider} is what applies the
     * approved-only + nesting + sort-order rules a template actually renders; this relation
     * exists for direct model access (moderation surfaces, tests) rather than public display.
     */
    public function comments(): HasMany
    {
        return $this->hasMany(config('heisenberg.models.comment', Comment::class), 'post_id');
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
