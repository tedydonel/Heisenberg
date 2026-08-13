<?php

declare(strict_types=1);

namespace Heisenberg\Http\Controllers;

use Heisenberg\Adapters\GuestActor;
use Heisenberg\Models\Post;
use Heisenberg\Models\TocEntry;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Lightweight per-post settings (Page layout, Discussion, Featured image — inspector.blade.php's
 * own docblock) that must NOT go through PostController::update(): that endpoint unconditionally
 * replaces the post's ENTIRE block tree from `blocks` (defaulting to `[]` when absent — see its
 * save()) and enforces the optimistic content_version lock, neither of which applies to a scalar
 * field like page padding, the comments toggle, or a featured-image FK. A request to any action
 * here would otherwise need to carry the full block tree just to flip one setting, or risk
 * wiping the post's content.
 *
 * Same PostPolicy::update() authorization rationale as PostCategoryController/PostTagController —
 * see those classes' docblocks. `page_padding_x`/`page_padding_y`/`allow_comments`/
 * `featured_image_id` are deliberately NOT in Post::$fillable (same posture as the old
 * `category_id`) — set via direct property assignment here, the only path that may write them.
 */
class PostSettingsController
{
    private const PADDING_MIN = 0;
    private const PADDING_MAX = 400;

    /** PUT /editor/posts/{post}/layout — body: { page_padding_x, page_padding_y } (px). */
    public function updateLayout(Request $request, string $post): JsonResponse
    {
        $model = $this->findPostOrFail($post);
        Gate::forUser($this->actor($request))->authorize('update', $model);

        $validated = $request->validate([
            'page_padding_x' => ['required', 'integer', 'min:' . self::PADDING_MIN, 'max:' . self::PADDING_MAX],
            'page_padding_y' => ['required', 'integer', 'min:' . self::PADDING_MIN, 'max:' . self::PADDING_MAX],
        ]);

        $model->page_padding_x = $validated['page_padding_x'];
        $model->page_padding_y = $validated['page_padding_y'];
        $model->save();

        return response()->json([
            'post_id' => $model->id,
            'page_padding_x' => $model->page_padding_x,
            'page_padding_y' => $model->page_padding_y,
        ]);
    }

    /** PUT /editor/posts/{post}/discussion — body: { allow_comments }. */
    public function updateDiscussion(Request $request, string $post): JsonResponse
    {
        $model = $this->findPostOrFail($post);
        Gate::forUser($this->actor($request))->authorize('update', $model);

        $validated = $request->validate([
            'allow_comments' => ['required', 'boolean'],
        ]);

        $model->allow_comments = $validated['allow_comments'];
        $model->save();

        return response()->json([
            'post_id' => $model->id,
            'allow_comments' => $model->allow_comments,
        ]);
    }

    /**
     * PUT /editor/posts/{post}/featured-image — body: { featured_image_id | null }. Mirrors
     * updateLayout()'s posture: same direct-property write rather than going through
     * PostController::update() (which would wipe the block tree trying to save one scalar FK).
     * The id, when present, must point at a real PublicFile row — `exists` rejects dead ids so
     * the FK is never violated despite the nullOnDelete() cascade at the database level.
     *
     * Single-row model (docs/content-translation.md §0): a featured image illustrates the
     * article, and there is now only ever ONE row for it — the group-wide propagation this
     * endpoint used to do across sibling rows is simply a fact now (nothing else to write it
     * onto).
     */
    public function updateFeaturedImage(Request $request, string $post): JsonResponse
    {
        $model = $this->findPostOrFail($post);
        Gate::forUser($this->actor($request))->authorize('update', $model);

        $validated = $request->validate([
            'featured_image_id' => ['nullable', 'integer', 'exists:' . $this->publicFilesTable() . ',id'],
        ]);

        $model->featured_image_id = $validated['featured_image_id'];
        $model->save();

        return response()->json([
            'post_id' => $model->id,
            'featured_image_id' => $model->featured_image_id,
        ]);
    }

    /**
     * PUT /editor/posts/{post}/toc — body: { entries: [{label, anchor}, ...] }. Replace-all
     * semantics inside a transaction: every existing row is deleted and the submitted list is
     * re-inserted with `order` set to its array index, so the client never has to reconcile ids —
     * it just posts the list it wants. An empty `entries` array is a legal, deliberate way to
     * remove the post's TOC (the render side treats "no rows" as "not authored"; see
     * TocEntry/Post::tocEntries()'s own docblocks). `anchor` is server-validated against
     * `/^[A-Za-z][\w-]*$/` — a legal CSS/HTML id — because that's what a #fragment link and the
     * heading's own `id` attribute both need; sanitizing a client-derived slug into that shape is
     * the client's job (the modal's "Load from headings" flow), not this endpoint's.
     */
    public function updateToc(Request $request, string $post): JsonResponse
    {
        $model = $this->findPostOrFail($post);
        Gate::forUser($this->actor($request))->authorize('update', $model);

        $validated = $request->validate([
            'entries' => ['present', 'array'],
            'entries.*.label' => ['required', 'string', 'max:160'],
            'entries.*.anchor' => ['required', 'string', 'max:160', 'regex:/^[A-Za-z][\w-]*$/'],
        ]);

        $entries = array_values($validated['entries']);

        $model->getConnection()->transaction(function () use ($model, $entries): void {
            $model->tocEntries()->delete();
            foreach ($entries as $index => $entry) {
                $model->tocEntries()->create([
                    'label' => $entry['label'],
                    'anchor' => $entry['anchor'],
                    'order' => $index,
                ]);
            }
        });

        return response()->json([
            'post_id' => $model->id,
            'entries' => $model->tocEntries()->get(['label', 'anchor'])->map(fn (TocEntry $e) => [
                'label' => $e->label,
                'anchor' => $e->anchor,
            ])->values()->all(),
        ]);
    }

    /**
     * The acting Authenticatable, or a {@see GuestActor} stand-in — same convention every other
     * /editor controller uses (see PostController's own docblock on this).
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

    private function findPostOrFail(string $id): Post
    {
        $class = $this->postClass();

        return $class::query()->findOrFail($id);
    }

    private function publicFilesTable(): string
    {
        return (string) config('heisenberg.tables.public_files', 'heisenberg_public_files');
    }
}
