<?php

declare(strict_types=1);

namespace Heisenberg\Http\Controllers;

use Heisenberg\Adapters\GuestActor;
use Heisenberg\Models\Post;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Move-to-trash / restore / trashed-listing for the post aggregate — the HTTP surface behind the
 * editor's "Move to trash" button (inspector/post-title-summary.blade.php's `data-hb-post-trash`)
 * AND a standalone JSON API a host can build its own trash screen against. Same GuestActor/
 * PostPolicy/LocalDevRoleGate idiom as PostSettingsController's own docblock — these routes sit
 * behind config('heisenberg.middleware.editor') (unauthenticated by default in local dev), so
 * PostPolicy's LocalDevRoleGate wrapper is what actually grants a GuestActor anything there.
 *
 * trash()/restore() delegate straight to {@see Post::delete()}/{@see Post::restore()} — the
 * model's OWN cascade (blocks + revisions trashed/restored together via a shared
 * deleted_batch_id, see that class's own docblock). Nothing here reimplements the cascade;
 * categories/tags/TOC entries/comments are untouched either way (Post's own docblocks explain
 * each relation's posture).
 *
 * Deliberately NO force-delete endpoint here: the editor has no "permanently delete" affordance
 * to wire one to (only "Move to trash" exists), and PostPolicy has no forceDelete ability
 * convention to reuse — inventing an unrecoverable HTTP endpoint with nothing in the product
 * exercising it would be dead, untested surface. A host that genuinely wants hard deletion
 * already has the database: `Post::withTrashed()->find($id)->forceDelete()` from their own code,
 * same as any other Eloquent SoftDeletes model.
 */
class PostTrashController
{
    /** DELETE /editor/posts/{post} — move to trash (soft delete). Cascades to blocks/revisions. */
    public function trash(Request $request, string $post): JsonResponse
    {
        $model = $this->findPostOrFail($post);
        Gate::forUser($this->actor($request))->authorize('delete', $model);

        $model->delete();
        $model->refresh();

        return response()->json([
            'id' => $model->getKey(),
            'trashed' => true,
            'deleted_at' => $model->deleted_at?->toIso8601String(),
        ]);
    }

    /** POST /editor/posts/{post}/restore — undo trash(). Restores the same batch's blocks/revisions. */
    public function restore(Request $request, string $post): JsonResponse
    {
        $model = $this->findPostOrFail($post, withTrashed: true);
        Gate::forUser($this->actor($request))->authorize('restore', $model);

        if (! $model->trashed()) {
            return response()->json(['message' => 'This post is not trashed.'], 422);
        }

        $model->restore();

        return response()->json(['id' => $model->getKey(), 'trashed' => false]);
    }

    /** GET /editor/posts/trashed — id/title/slug/type/deleted_at/author for every trashed post, most-recently-trashed first. */
    public function trashed(Request $request): JsonResponse
    {
        $class = $this->postClass();
        Gate::forUser($this->actor($request))->authorize('viewTrashed', $class);

        $posts = $class::query()->onlyTrashed()->orderByDesc('deleted_at')->get();

        return response()->json([
            'posts' => $posts->map(static fn (Post $p): array => [
                'id' => $p->getKey(),
                'title' => (string) ($p->title_en ?? ''),
                'slug' => (string) ($p->slug ?? ''),
                'type' => (string) ($p->type ?? 'post'),
                'deleted_at' => $p->deleted_at?->toIso8601String(),
                'author' => $p->author_id,
            ])->values()->all(),
        ]);
    }

    /**
     * The acting Authenticatable, or a {@see GuestActor} stand-in — same convention every other
     * /editor controller uses (see PostController's own docblock).
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

    private function findPostOrFail(string $id, bool $withTrashed = false): Post
    {
        $class = $this->postClass();
        $query = $withTrashed ? $class::query()->withTrashed() : $class::query();

        return $query->findOrFail($id);
    }
}
