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
 * Lightweight per-post settings (Page layout, Discussion — inspector.blade.php's own docblock)
 * that must NOT go through PostController::update(): that endpoint unconditionally replaces the
 * post's ENTIRE block tree from `blocks` (defaulting to `[]` when absent — see its save()) and
 * enforces the optimistic content_version lock, neither of which applies to a scalar field like
 * page padding or the comments toggle. A request to either action here would otherwise need to
 * carry the full block tree just to flip one setting, or risk wiping the post's content.
 *
 * Same PostPolicy::update() authorization rationale as PostCategoryController/PostTagController —
 * see those classes' docblocks. `page_padding_x`/`page_padding_y`/`allow_comments` are deliberately
 * NOT in Post::$fillable (same posture as the old `category_id`) — set via direct property
 * assignment here, the only path that may write them.
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
}
