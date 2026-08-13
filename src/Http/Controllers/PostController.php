<?php

declare(strict_types=1);

namespace Heisenberg\Http\Controllers;

use Heisenberg\Adapters\GuestActor;
use Heisenberg\Http\Requests\SavePostRequest;
use Heisenberg\Models\Post;
use Heisenberg\Policies\PostPolicy;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * HTTP save/load layer for the post + block-tree aggregate. Every action
 * authorizes via {@see PostPolicy}, then persists inside a single transaction.
 *
 * CONCURRENCY: the client echoes the `content_version` it loaded; save() locks
 * the row FOR UPDATE, compares versions, and 409s on a mismatch — nothing is
 * written when stale. A successful write ends with Post::bumpContentVersion().
 *
 * LIFECYCLE: status/published_at/scheduled_at are never mass-assigned — a status
 * intent is validated in applyTransition() before either column is written, and an
 * explicit published_at edit is validated in applyPublishedAt() (same `editors`-tier
 * gate as a publish transition, since the displayed post date is publishing-adjacent).
 * Both are the ONLY writers of published_at; autosave payloads skip both entirely.
 *
 * SLUG: also never mass-assigned (contentAttributes() deliberately excludes it) —
 * applySlug() is the only writer after creation, validating shape + per-locale
 * uniqueness before the write (a 422, not Post::uniqueSlug()'s silent `-2` suffix).
 * update()-only, non-autosave; Post::booted()'s `updating` hook still regenerates
 * from the title, but ONLY when the slug is empty (see that method's docblock) —
 * `slug: ''` is applySlug()'s own way of asking for a fresh, title-derived one.
 *
 * SEO: a separate `SeoMeta` row (docs/seo-system.md §3), never part of $post's own
 * $fillable — applySeo() `updateOrCreate`s it from the request's `seo` map, non-autosave
 * only, same "next explicit save only" posture as slug/published_at. See its own docblock.
 *
 * TIMEZONE: the editor works entirely in `config('app.timezone')` wall-clock time — a
 * `datetime-local` value (e.g. "2026-08-12T11:13") is neither UTC nor the visiting
 * browser's zone, it is that literal wall clock IN THE APP'S CONFIGURED ZONE. Every hop
 * must honor this: applyTransition()/applyPublishedAt() parse the incoming naive string
 * with Carbon::parse()/Request::date() (no explicit $tz — PHP's default zone is already
 * app.timezone, set by LoadConfiguration's date_default_timezone_set()), and payload()
 * below echoes published_at/scheduled_at back with the SAME naive "Y-m-d\TH:i" format
 * EditorController::show() seeds the page with — never toIso8601String()/toISOString(),
 * whose embedded offset gets silently reinterpreted through the BROWSER's own zone by
 * `new Date(iso)` client-side (see post-meta-live-script.blade.php's own docblock). If
 * the host's app.timezone differs from the viewer's browser zone, the editor still shows
 * app-timezone wall clock consistently — that is the documented, predictable behavior.
 */
class PostController
{
    public function __construct(private PostPolicy $policy)
    {
    }

    public function show(Request $request, string $post): JsonResponse
    {
        $model = $this->findOrFail($post);
        $actor = $this->actor($request);

        Gate::forUser($actor)->authorize('view', $model);

        return response()->json($this->payload($model->loadMissing('blocks')));
    }

    public function store(SavePostRequest $request): JsonResponse
    {
        return $this->save($request, null);
    }

    public function update(SavePostRequest $request, string $post): JsonResponse
    {
        return $this->save($request, $this->findOrFail($post));
    }

    private function save(SavePostRequest $request, ?Post $existing): JsonResponse
    {
        $actor = $this->actor($request);
        $class = $this->postClass();

        // A new row is owned by its creator, so an authors-tier actor can save their own
        // post; GuestActor's null identifier makes this a no-op for local dev.
        $subject = $existing ?? new $class(['author_id' => $actor->getAuthIdentifier()]);
        if ($existing === null) {
            // A fresh model doesn't reflect the DB column default until re-read; without this,
            // a same-request lifecycle transition would see a null status and reject every edge.
            $subject->status = 'draft';
        }

        Gate::forUser($actor)->authorize('update', $subject);

        $isAutosave = $request->boolean('autosave');
        $requestedStatus = $isAutosave ? null : $request->input('status');

        $conflict = false;
        $transitionFailure = null;

        $post = DB::transaction(function () use (
            $request, $existing, $subject, $actor, $requestedStatus, $isAutosave, &$conflict, &$transitionFailure
        ) {
            if ($existing === null) {
                $post = $subject;
                // docs/email-system.md §3/§7-E3: `type` is create-only — a document never
                // changes type via this (or any) write path, same posture McpToolRegistry::
                // writePost() already established for its own create_post tool. Direct property
                // assignment, same "not mass-assigned" reasoning Post::$fillable's own docblock
                // gives for status/translated_from_version. An update request that happens to
                // carry `type` (e.g. an unmodified client echo) simply never reaches here.
                if ($request->filled('type')) {
                    $post->type = (string) $request->input('type');
                }
                // contentAttributes() normalises a blank/null title to the placeholder — see there.
                $post->fill($this->contentAttributes($request));
                $post->save();
            } else {
                // Row lock for the transaction's lifetime — the optimistic-concurrency gate:
                // nothing can read-then-write between the version check and our write.
                $post = $existing->newQuery()->lockForUpdate()->findOrFail($existing->getKey());

                if ((int) $request->input('content_version') !== (int) $post->content_version) {
                    $conflict = true;

                    return null;
                }

                $post->fill($this->contentAttributes($request));
                $post->save();
            }

            // Slug + published_at are set on $post here (property only, not yet the row's last
            // word) so that whichever save actually persists them below — applyTransition()'s own
            // $post->save() when a transition also rides this request, or the isDirty() catch-all
            // a few lines down otherwise — writes the FULL, final in-memory state in one UPDATE.
            // Order matters for published_at specifically: it must land before applyTransition()
            // runs so a preset/backdated date is already non-null when that method's own
            // stamp-only-when-null publish logic checks it (see that method's docblock).
            if (! $isAutosave) {
                if ($existing !== null && $request->has('slug')) {
                    $transitionFailure = $this->applySlug($post, (string) $request->input('slug'));
                    if ($transitionFailure !== null) {
                        return null;
                    }
                }
                if ($request->has('published_at')) {
                    $transitionFailure = $this->applyPublishedAt($post, $actor, $request->input('published_at'));
                    if ($transitionFailure !== null) {
                        return null;
                    }
                }
                // SEO/Social panel (docs/seo-system.md §3) — same gate posture as slug/
                // published_at above: the actor already passed the post-update authorization at
                // the top of save(), and a SeoMeta row isn't a separately-authorized resource, so
                // no extra Gate check runs here. Never fails (no shape left to reject —
                // SavePostRequest already validated it), so unlike applySlug()/applyPublishedAt()
                // it has no failure tuple to propagate.
                if ($request->has('seo')) {
                    $this->applySeo($post, (array) $request->input('seo', []));
                }
            }

            // `scheduled -> scheduled` isn't an edge (it's not even listed under 'scheduled' in
            // the transitions map — see applyTransition()'s $isReschedule exemption below), but
            // it IS a legitimate reschedule: the Summary's datetime field stays editable on an
            // already-scheduled post, and a changed scheduled_at must actually persist rather
            // than silently no-op just because the status string itself didn't move.
            $isReschedule = $requestedStatus === 'scheduled' && $post->status === 'scheduled';
            if ($requestedStatus !== null && $requestedStatus !== '' && ($requestedStatus !== $post->status || $isReschedule)) {
                $transitionFailure = $this->applyTransition($post, $actor, (string) $requestedStatus, $request, $isReschedule);
                if ($transitionFailure !== null) {
                    return null; // reject the whole save — no partial lifecycle state
                }
            }

            // Catch-all persist for a slug/published_at edit that rode this request WITHOUT a
            // status transition (applyTransition() above already saved when one happened — this
            // is a no-op UPDATE in that case, since $post has nothing left dirty).
            if ($post->isDirty()) {
                $post->save();
            }

            // Snapshot the OLD tree before replaceBlocks() discards it — this is the
            // revision history. Creates are skipped (there is no old tree yet).
            if ($existing !== null) {
                $this->captureRevision($post, $actor, $request->boolean('autosave'));
            }

            $this->replaceBlocks($post, (array) $request->input('blocks', []));
            $post->bumpContentVersion();

            return $post->fresh(['blocks']);
        });

        if ($conflict) {
            return response()->json([
                'message' => 'This post was changed elsewhere — reload and try again.',
            ], 409);
        }

        if ($transitionFailure !== null) {
            return response()->json(['message' => $transitionFailure['message']], $transitionFailure['status']);
        }

        return response()->json($this->payload($post), $existing === null ? 201 : 200);
    }

    /**
     * Validate + apply a requested status transition. Returns null on
     * success; an error tuple otherwise. Two distinct failure modes:
     *  - 422: the edge itself isn't legal from the post's current status
     *    (config('heisenberg.lifecycle.transitions')) — a data problem.
     *  - 403: the edge is legal, but this actor lacks the tier the TARGET
     *    status requires (config('heisenberg.lifecycle.role_permissions'),
     *    via PostPolicy::transitionAllowed()) — an authorization problem.
     *
     * $isReschedule (save()'s `scheduled -> scheduled` case) skips the edge check — the
     * transitions map has no self-loop to check against — but still runs the SAME tier
     * gate a first `-> scheduled` transition does, so rescheduling stays `editors`-only.
     *
     * @return array{status: int, message: string}|null
     */
    private function applyTransition(Post $post, Authenticatable $actor, string $target, Request $request, bool $isReschedule = false): ?array
    {
        $transitions = (array) config('heisenberg.lifecycle.transitions', []);
        $current = (string) $post->status;
        $allowed = (array) ($transitions[$current] ?? []);

        if (! $isReschedule && ! in_array($target, $allowed, true)) {
            return ['status' => 422, 'message' => "Cannot move a post from \"{$current}\" to \"{$target}\"."];
        }

        if (! $this->policy->transitionAllowed($actor, $target)) {
            return ['status' => 403, 'message' => 'You are not authorized to move this post to that status.'];
        }

        $post->status = $target;
        if ($target === 'published' && $post->published_at === null) {
            $post->published_at = now();
        }
        if ($target === 'scheduled') {
            $post->scheduled_at = $request->date('scheduled_at') ?? $post->scheduled_at;
        }
        $post->save();

        return null;
    }

    /**
     * Validate + apply an explicit slug edit — update()-only, non-autosave (see save()'s
     * caller). `slug === ''` (trimmed) asks for a fresh, title-derived one: setting the
     * in-memory attribute to '' and leaving it there for Post::booted()'s `updating` hook,
     * which now regenerates from the title precisely when the slug is empty — the same
     * generator creation already uses, not a second copy of it (that hook ALSO propagates the
     * regenerated slug to every sibling — see its own docblock — so an emptied slug needs no
     * extra propagation logic here). A non-empty slug must match the shape Str::slug() actually
     * emits (lowercase, digits, single hyphens, no leading/trailing/doubled ones).
     *
     * SHARED-SLUG INVARIANT (docs/content-translation.md §1): a translation group presents as
     * ONE post with one slug — locale comes from the host's URL prefix, not the slug text — so
     * a rename here is validated and applied to the WHOLE group, not just this row. The slug
     * must be free among this post's OWN locale (excluding itself) AND, independently, among
     * EVERY SIBLING's own locale (excluding that sibling) — checked BEFORE any write, because
     * Post::uniqueSlug() would otherwise silently suffix a collision (`-2`, `-3`, …) rather than
     * reject it: fine for an auto-derived slug, wrong for a value the user typed on purpose. Any
     * single collision fails the WHOLE request (naming which language blocked it) with no
     * partial write — this method only ever mutates in-memory attributes and explicitly saves
     * the siblings itself; $post's own save is left to save()'s existing catch-all so the
     * post's other dirty attributes land in the same UPDATE.
     *
     * @return array{status:int, message:string}|null
     */
    private function applySlug(Post $post, string $rawSlug): ?array
    {
        $slug = trim($rawSlug);

        if ($slug === '') {
            $post->slug = '';

            return null;
        }

        if (preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug) !== 1) {
            return ['status' => 422, 'message' => 'Slug can only contain lowercase letters, numbers and hyphens.'];
        }

        $exists = $post->newQuery()->withTrashed()
            ->where('locale', $post->locale ?: 'en')
            ->where('slug', $slug)
            ->whereKeyNot($post->getKey())
            ->exists();

        if ($exists) {
            return ['status' => 422, 'message' => 'That slug is already in use.'];
        }

        $siblings = $post->siblings();
        foreach ($siblings as $sibling) {
            $siblingConflict = $sibling->newQuery()->withTrashed()
                ->where('locale', $sibling->locale)
                ->where('slug', $slug)
                ->whereKeyNot($sibling->getKey())
                ->exists();

            if ($siblingConflict) {
                return ['status' => 422, 'message' => "That slug is already in use in the \"{$sibling->locale}\" translation."];
            }
        }

        $post->slug = $slug;
        foreach ($siblings as $sibling) {
            $sibling->slug = $slug;
            $sibling->save();
        }

        return null;
    }

    /**
     * Validate + apply an explicit published_at edit — the second (and, alongside
     * applyTransition()'s first-publish stamp, only other) writer of this column. Gated by
     * the SAME `editors` tier a publish transition itself requires
     * (PostPolicy::transitionAllowed('published')): the displayed post date is
     * publishing-adjacent, not ordinary content, regardless of whether a transition is
     * riding the same request. Both past and future dates are accepted — this backdates or
     * presets the post's date, it doesn't schedule anything (that's the Summary's separate
     * `scheduled` status + scheduled_at). `null`/`''` clears it back to unset.
     *
     * @return array{status:int, message:string}|null
     */
    private function applyPublishedAt(Post $post, Authenticatable $actor, ?string $raw): ?array
    {
        if (! $this->policy->transitionAllowed($actor, 'published')) {
            return ['status' => 403, 'message' => 'You are not authorized to set this post\'s date.'];
        }

        $post->published_at = ($raw !== null && $raw !== '') ? \Illuminate\Support\Carbon::parse($raw) : null;

        return null;
    }

    /**
     * Validate + apply the SEO/Social panel's fields (docs/seo-system.md §3) — the localized
     * keys (`meta_title`, `meta_description`, `og_title`, `og_description`, `focus_keyphrase`)
     * map to the POST ROW'S OWN locale columns: the split-row model means the FR sibling's own
     * panel edits its OWN `_fr` columns, never the other locale's (same posture `title_en`/
     * `title_fr` already have — a locale row only ever writes its own half). `robots_index`/
     * `robots_follow` compose into the single `robots` column ('index'|'noindex', ', ',
     * 'follow'|'nofollow') — the shape {@see \Heisenberg\Services\SeoAnalyzer} and the preview's
     * own head logic already parse. Only keys actually PRESENT in `$seo` are touched (mirrors
     * `contentAttributes()`'s "an absent key leaves the column alone" posture) — a partial
     * payload from a future AI/API caller never clobbers fields it didn't mean to touch.
     * `updateOrCreate` on `(able_type, able_id)` is SeoMeta's only write path (see its own
     * docblock); nothing here mass-assigns a `SeoMeta` a caller-supplied `id`.
     */
    private function applySeo(Post $post, array $seo): void
    {
        $locale = $post->locale === 'fr' ? 'fr' : 'en';

        $attributes = [];
        foreach (['meta_title', 'meta_description', 'og_title', 'og_description', 'focus_keyphrase'] as $field) {
            if (array_key_exists($field, $seo)) {
                $value = $seo[$field];
                $attributes["{$field}_{$locale}"] = ($value === null || $value === '') ? null : (string) $value;
            }
        }
        foreach (['og_image', 'canonical_url', 'schema_type'] as $field) {
            if (array_key_exists($field, $seo)) {
                $value = $seo[$field];
                $attributes[$field] = ($value === null || $value === '') ? null : (string) $value;
            }
        }

        if (array_key_exists('robots_index', $seo) || array_key_exists('robots_follow', $seo)) {
            $current = (string) ($post->seoMeta?->robots ?: 'index, follow');
            $index = array_key_exists('robots_index', $seo) ? (bool) $seo['robots_index'] : ! str_contains($current, 'noindex');
            $follow = array_key_exists('robots_follow', $seo) ? (bool) $seo['robots_follow'] : ! str_contains($current, 'nofollow');
            $attributes['robots'] = ($index ? 'index' : 'noindex') . ', ' . ($follow ? 'follow' : 'nofollow');
        }
        if (array_key_exists('in_sitemap', $seo)) {
            $attributes['in_sitemap'] = (bool) $seo['in_sitemap'];
        }

        if ($attributes === []) {
            return;
        }

        $class = $this->seoMetaClass();
        $class::query()->updateOrCreate(
            ['able_type' => $post->getMorphClass(), 'able_id' => $post->getKey()],
            $attributes,
        );
    }

    /**
     * The saved SeoMeta state, echoed in the save response (payload()'s docblock) so
     * panel-seo-social.blade.php's own script can resync its "last confirmed" snapshot after
     * every save — same "the server's echo is the only thing a live panel trusts post-save"
     * posture `post.slug`/`post.status` already have. Deliberately reads the RAW own-locale
     * columns (not {@see \Heisenberg\Models\SeoMeta}'s cross-locale-fallback accessors the
     * SEED payload uses, EditorController::postSeo()): right after a save the own-locale column
     * holds exactly what the user just submitted, including an intentionally emptied field — the
     * fallback accessor would silently show the OTHER locale's stale text instead of the blank
     * the user just asked for, which is correct for a first-load SEED (a starting point) but
     * wrong for an ECHO (the truth about what this save just did).
     *
     * @return array<string, mixed>
     */
    private function seoPayload(Post $post): array
    {
        $locale = $post->locale === 'fr' ? 'fr' : 'en';
        $seo = $this->seoMetaClass()::query()
            ->where('able_type', $post->getMorphClass())
            ->where('able_id', $post->getKey())
            ->first();
        $robots = (string) ($seo?->robots ?? 'index, follow');

        return [
            'meta_title' => (string) ($seo?->{"meta_title_{$locale}"} ?? ''),
            'meta_description' => (string) ($seo?->{"meta_description_{$locale}"} ?? ''),
            'og_title' => (string) ($seo?->{"og_title_{$locale}"} ?? ''),
            'og_description' => (string) ($seo?->{"og_description_{$locale}"} ?? ''),
            'focus_keyphrase' => (string) ($seo?->{"focus_keyphrase_{$locale}"} ?? ''),
            'og_image' => (string) ($seo?->og_image ?? ''),
            'canonical_url' => (string) ($seo?->canonical_url ?? ''),
            'robots_index' => ! str_contains($robots, 'noindex'),
            'robots_follow' => ! str_contains($robots, 'nofollow'),
            'in_sitemap' => (bool) ($seo?->in_sitemap ?? true),
        ];
    }

    /** @return class-string<\Heisenberg\Models\SeoMeta> */
    private function seoMetaClass(): string
    {
        return (string) config('heisenberg.models.seo_meta', \Heisenberg\Models\SeoMeta::class);
    }

    /**
     * The mass-assignable content fields this endpoint will ever write.
     * Deliberately excludes `author_id` (ownership never changes via a
     * content save), `slug` (applySlug() is the only path that may set it
     * after creation) and status/published_at/scheduled_at (the lifecycle
     * guard in applyTransition(), plus applyPublishedAt() for an explicit
     * date edit, are the ONLY paths that may set them).
     *
     * @return array<string, mixed>
     */
    private function contentAttributes(SavePostRequest $request): array
    {
        $attributes = $request->only(['title_en', 'title_fr', 'locale', 'excerpt_en', 'excerpt_fr']);

        // title_en is NOT NULL with no DB default, and the web middleware nulls '' — normalize
        // a sent-but-empty title to the canvas placeholder. An absent key leaves the column alone.
        if (array_key_exists('title_en', $attributes) && trim((string) $attributes['title_en']) === '') {
            $attributes['title_en'] = (string) __('heisenberg::editor.canvas.ph_untitled_post');
        }

        return $attributes;
    }

    /**
     * Snapshot the post's CURRENT (about-to-be-replaced) block tree into the
     * revisions table. An autosave keeps ONE rolling snapshot per post (each
     * replaces the previous `auto_save` row); manual saves accumulate, pruned
     * to `heisenberg.revisions.keep` when set (null = unbounded, the config's
     * as-built default). Runs inside save()'s transaction, on the row-locked
     * model whose relation still holds the old rows.
     */
    private function captureRevision(Post $post, Authenticatable $actor, bool $autosave): void
    {
        $post->loadMissing('blocks');
        if ($post->blocks->isEmpty()) {
            return; // an empty tree is not a version worth restoring
        }

        $revisionClass = (string) config('heisenberg.models.revision', \Heisenberg\Models\Revision::class);

        if ($autosave) {
            $revisionClass::query()
                ->where('post_id', $post->getKey())
                ->where('revision_type', 'auto_save')
                ->forceDelete();
        }

        \Heisenberg\Models\Revision::snapshotOf($post, $autosave ? 'auto_save' : 'manual', $actor->getAuthIdentifier());

        $keep = config('heisenberg.revisions.keep');
        if ($keep === null) {
            return; // unbounded history — the config's as-built default
        }
        $stale = $revisionClass::query()
            ->where('post_id', $post->getKey())
            ->where('revision_type', '!=', 'auto_save')
            ->orderByDesc('id')
            ->skip(max(1, (int) $keep))->take(100)
            ->pluck('id');
        if ($stale->isNotEmpty()) {
            $revisionClass::query()->whereKey($stale->all())->forceDelete();
        }
    }

    /**
     * Replace the post's blocks transactionally: delete-then-reinsert (soft
     * delete) — acceptable per BlockPersistenceTest's own roadmap note.
     * `order` is rewritten from array position on every save, so the
     * payload's array order is the single source of truth.
     */
    private function replaceBlocks(Post $post, array $blocks): void
    {
        $post->blocks()->delete();

        foreach (array_values($blocks) as $index => $block) {
            if (! is_array($block)) {
                continue; // SavePostRequest/BlocksPayloadService already rejected this shape
            }

            $post->blocks()->create([
                'type' => $this->blockType($block),
                'content' => $block,
                'order' => $index,
            ]);
        }
    }

    /** `heisenberg/paragraph` -> `paragraph`, mirroring BlockPersistenceTest's own fixtures. */
    private function blockType(array $block): string
    {
        $name = (string) ($block['name'] ?? '');

        return str_contains($name, '/') ? substr($name, strrpos($name, '/') + 1) : $name;
    }

    /**
     * The shape the client runtime consumes to rehydrate a document: each block
     * exactly as stored, in `order`.
     *
     * @return array<string, mixed>
     */
    private function payload(Post $post): array
    {
        return [
            'post' => [
                'id' => $post->id,
                'translation_group_id' => $post->translation_group_id,
                'author_id' => $post->author_id,
                'locale' => $post->locale,
                'title_en' => $post->title_en,
                'title_fr' => $post->title_fr,
                'slug' => $post->slug,
                'excerpt_en' => $post->excerpt_en,
                'excerpt_fr' => $post->excerpt_fr,
                'status' => $post->status,
                // Naive app-timezone wall clock — SAME format + zone rule as
                // EditorController::show()'s seed; see this class's own TIMEZONE docblock.
                'published_at' => $post->published_at?->format('Y-m-d\TH:i'),
                'scheduled_at' => $post->scheduled_at?->format('Y-m-d\TH:i'),
                'content_version' => $post->content_version,
                'seo' => $this->seoPayload($post),
            ],
            'blocks' => $post->blocks->map(fn ($block) => $block->content)->values()->all(),
        ];
    }

    /**
     * The acting Authenticatable, or a {@see GuestActor} stand-in for "no logged-in
     * user" — decided by PostPolicy's LocalDevRoleGate wrapper (local env only).
     */
    private function actor(Request $request): Authenticatable
    {
        return $request->user() ?? new GuestActor();
    }

    /** @return class-string<Post> */
    private function postClass(): string
    {
        return (string) config('heisenberg.models.post', Post::class);
    }

    private function findOrFail(string $id): Post
    {
        $class = $this->postClass();

        return $class::query()->findOrFail($id);
    }
}
