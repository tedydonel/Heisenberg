<?php

declare(strict_types=1);

namespace Heisenberg\Http\Controllers;

use Heisenberg\Adapters\GuestActor;
use Heisenberg\Models\Post;
use Heisenberg\Models\Revision;
use Heisenberg\Support\LocaleConfig;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * POST /editor/posts/{post}/translations — docs/content-translation.md §4 (Wave T2a). Body
 * `{locale, update?: bool}`. Creates (or, with `update: true`, overwrites) the SOURCE post's
 * sibling row in `locale`: same `translation_group_id`, a content deep-copy (blocks + TOC entries
 * — the same shapes PostController::replaceBlocks()/PostSettingsController::updateToc() write,
 * mirrored here rather than reused since those are private to their own controllers), draft
 * status on first create, and `translated_from_version` stamped to the source's CURRENT
 * `content_version` so `Post::isTranslationOutdated()` can later tell when the source has moved
 * on.
 *
 * Authorization is `PostPolicy::update()` on the SOURCE post — the same "owner or admins" gate
 * every other content-mutating action on a post uses (PostController::save(),
 * PostSettingsController's own actions). There is no separate "may translate" ability: producing
 * a translation IS a content-mutating action from the source author's point of view, and the
 * sibling itself is authored fresh (no pre-existing ownership to check against on first create).
 *
 * BLOCK CONTENT IS COPIED VERBATIM, no id regeneration: `Block::content` never embeds a
 * cross-post-unique id (confirmed against Revision::snapshotOf()/PostRevisionsController::show(),
 * the only other paths that duplicate a block tree — both copy `content` as-is, and restore is
 * client-driven via `hbEditor.replaceDoc()` with no server-side id remap either). An authored
 * anchor id (TOC target, heading id) is scoped to its own page/post, so a sibling row carrying
 * the same anchor strings as its source is correct, not a collision.
 */
class PostTranslationController
{
    public function store(Request $request, string $post): JsonResponse
    {
        $source = $this->findOrFail($post);
        $actor = $this->actor($request);

        Gate::forUser($actor)->authorize('update', $source);

        $locale = trim((string) $request->input('locale', ''));
        $update = $request->boolean('update');

        if (! LocaleConfig::isValid($locale)) {
            return response()->json(['message' => 'Unknown locale.'], 422);
        }

        $ownLocale = (string) ($source->locale ?: LocaleConfig::default());
        if ($locale === $ownLocale) {
            return response()->json(['message' => 'A post cannot be translated into its own locale.'], 422);
        }

        $sibling = $source->sibling($locale);

        // Existing sibling + no explicit update: never silently overwrite a translator's work.
        // The 409 hands back the sibling's own id so the client can offer "open it instead".
        if ($sibling !== null && ! $update) {
            return response()->json([
                'message' => 'A translation into that locale already exists.',
                'post_id' => $sibling->getKey(),
            ], 409);
        }

        $isCreate = $sibling === null;
        $class = $this->postClass();

        // SHARED-SLUG INVARIANT (docs/content-translation.md §1): a first-time translation uses
        // the source's EXACT slug (createSibling() below), never a numeric-suffixed copy — a
        // translation group presents as one post, one slug, locale resolved from the host's URL
        // prefix. Checked BEFORE the transaction opens (no partial writes either way): if some
        // UNRELATED post already holds that exact text in the target locale, the translation is
        // refused outright rather than silently drifting onto a different slug than its source.
        // update:true never touches slug at all (updateSibling() below), so this check is
        // create-only.
        if ($isCreate) {
            $slugTaken = $class::query()->withTrashed()
                ->where('locale', $locale)
                ->where('slug', $source->slug)
                ->exists();

            if ($slugTaken) {
                return response()->json([
                    'message' => "Translation not created: the slug \"{$source->slug}\" is already used by another post in \"{$locale}\".",
                ], 422);
            }
        }

        $result = DB::transaction(function () use ($source, $sibling, $locale, $actor, $class, $isCreate): Post {
            return $isCreate
                ? $this->createSibling($source, $locale, $class)
                : $this->updateSibling($source, $sibling, $actor);
        });

        return response()->json([
            'post_id' => $result->getKey(),
            'locale' => $result->locale,
            'status' => $result->status,
        ], $isCreate ? 201 : 200);
    }

    /**
     * First-time translation: a brand-new sibling row, always `draft`. Title/excerpt: the
     * TARGET locale's column gets a copy of the source's own-locale text (the translator/AI's
     * starting point — never empty); the OTHER column mirrors the source's own value verbatim
     * (title_en is NOT NULL regardless of a row's own locale, so a fr-target sibling still needs
     * a usable title_en — copying the source's is simpler and more honest than inventing a
     * placeholder). Slug is the source's EXACT slug, verbatim — no numeric-suffixing (see the
     * shared-slug invariant note in store(), which already rejected the call with a 422 before
     * this method ever runs if that exact text collides with an unrelated post in `$locale`).
     * Post::booted()'s `creating` hook still runs its own uniqueSlug() pass over whatever slug
     * it's handed, but since store() already proved the exact text is free, that pass is a
     * guaranteed no-op here — it exists for the OTHER caller of `new Post(...)->save()` (a
     * brand-new, unrelated post), not for this one.
     *
     * @param class-string<Post> $class
     */
    private function createSibling(Post $source, string $locale, string $class): Post
    {
        /** @var Post $sibling */
        $sibling = new $class();
        $sibling->fill([
            'translation_group_id' => $source->translation_group_id,
            'author_id' => $source->author_id,
            'locale' => $locale,
            'title_en' => $locale === 'en' ? $source->title() : $source->title_en,
            'title_fr' => $locale === 'fr' ? $source->title() : $source->title_fr,
            'slug' => $source->slug,
            'excerpt_en' => $locale === 'en' ? $source->excerptText() : $source->excerpt_en,
            'excerpt_fr' => $locale === 'fr' ? $source->excerptText() : $source->excerpt_fr,
        ]);
        // Guarded columns (see Post::$fillable's own docblock) — direct property writes, the
        // only path allowed to set them, same posture PostSettingsController uses.
        $sibling->status = 'draft';
        // A translation is the same DOCUMENT in another language — an email's sibling is an
        // email, never a blog post. `type` is guarded (DB default 'post'), so without this
        // write the sibling silently reverts to the default.
        $sibling->type = $source->type;
        $sibling->page_padding_x = $source->page_padding_x;
        $sibling->page_padding_y = $source->page_padding_y;
        $sibling->allow_comments = $source->allow_comments;
        $sibling->featured_image_id = $source->featured_image_id;
        $sibling->translated_from_version = (int) $source->content_version;
        $sibling->save();

        $this->copyBlocks($source, $sibling);
        $this->copyToc($source, $sibling);

        return $sibling->fresh();
    }

    /**
     * Re-translation: overwrite the EXISTING sibling's blocks + TOC from the source and bump its
     * `translated_from_version` — title/excerpt/slug/status are deliberately left untouched, they
     * hold the translator's own values, not a copy of the source's. A revision is captured on the
     * sibling first, mirroring PostController::save()'s own "snapshot the about-to-be-replaced
     * tree before replaceBlocks() discards it" precedent for any destructive block replace.
     */
    private function updateSibling(Post $source, Post $sibling, Authenticatable $actor): Post
    {
        $this->captureRevision($sibling, $actor);

        $this->copyBlocks($source, $sibling);
        $this->copyToc($source, $sibling);

        $sibling->translated_from_version = (int) $source->content_version;
        $sibling->save();

        return $sibling->fresh();
    }

    /**
     * Delete-then-reinsert, order rewritten from array position — exactly
     * PostController::replaceBlocks()'s own shape (that method is private there, so this mirrors
     * rather than calls it). Content JSON is copied verbatim; see this class's own docblock for
     * why no id inside it needs regenerating.
     */
    private function copyBlocks(Post $source, Post $target): void
    {
        $source->loadMissing('blocks');
        $target->blocks()->delete();

        foreach ($source->blocks->values() as $index => $block) {
            $target->blocks()->create([
                'type' => $block->type,
                'content' => $block->content,
                'order' => $index,
            ]);
        }
    }

    /**
     * Delete-then-reinsert, order rewritten from array position — exactly
     * PostSettingsController::updateToc()'s own replace-all shape.
     */
    private function copyToc(Post $source, Post $target): void
    {
        $source->loadMissing('tocEntries');
        $target->tocEntries()->delete();

        foreach ($source->tocEntries->values() as $index => $entry) {
            $target->tocEntries()->create([
                'label' => $entry->label,
                'anchor' => $entry->anchor,
                'order' => $index,
            ]);
        }
    }

    /**
     * Mirrors PostController::captureRevision()'s manual-save branch (that method is private
     * there too): skip an empty tree, snapshot, then prune manual revisions past
     * `heisenberg.revisions.keep` (null = unbounded). This endpoint never autosaves, so the
     * autosave-specific rolling-snapshot branch that method also has has no counterpart here.
     */
    private function captureRevision(Post $post, Authenticatable $actor): void
    {
        $post->loadMissing('blocks');
        if ($post->blocks->isEmpty()) {
            return; // an empty tree is not a version worth restoring
        }

        Revision::snapshotOf($post, 'manual', $actor->getAuthIdentifier());

        $revisionClass = (string) config('heisenberg.models.revision', Revision::class);
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
     * The acting Authenticatable, or a {@see GuestActor} stand-in — same convention every other
     * /editor controller uses.
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
