<?php

declare(strict_types=1);

namespace Heisenberg\Http\Controllers;

use Heisenberg\Adapters\GuestActor;
use Heisenberg\Models\Category;
use Heisenberg\Models\Post;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Attach/detach a category to/from a post (Post::categories() BelongsToMany via the
 * `heisenberg_category_post` pivot, 2026-08-03 — see that method's docblock for the earlier
 * single-category shape this superseded). Same PostPolicy::update() authorization rationale as
 * PostTagController, which this now mirrors exactly — see that class's docblock.
 */
class PostCategoryController
{
    /** POST /editor/posts/{post}/categories/{category} */
    public function attach(Request $request, string $post, string $category): JsonResponse
    {
        $postModel = $this->findPostOrFail($post);
        Gate::forUser($this->actor($request))->authorize('update', $postModel);

        $categoryModel = $this->findCategoryOrFail($category);

        $postModel->categories()->syncWithoutDetaching([$categoryModel->getKey()]);

        return response()->json($this->payload($postModel->fresh(['categories'])));
    }

    /** DELETE /editor/posts/{post}/categories/{category} */
    public function detach(Request $request, string $post, string $category): JsonResponse
    {
        $postModel = $this->findPostOrFail($post);
        Gate::forUser($this->actor($request))->authorize('update', $postModel);

        $categoryModel = $this->findCategoryOrFail($category);

        $postModel->categories()->detach($categoryModel->getKey());

        return response()->json($this->payload($postModel->fresh(['categories'])));
    }

    /** @return array<string, mixed> */
    private function payload(Post $post): array
    {
        return [
            'post_id' => $post->id,
            'categories' => $post->categories->map(fn (Category $category) => [
                'id' => $category->id,
                'name_en' => $category->name_en,
                'name_fr' => $category->name_fr,
                'slug' => $category->slug,
            ])->values()->all(),
        ];
    }

    private function actor(Request $request): Authenticatable
    {
        return $request->user() ?? new GuestActor();
    }

    /** @return class-string<Post> */
    private function postClass(): string
    {
        return (string) config('heisenberg.models.post', Post::class);
    }

    /** @return class-string<Category> */
    private function categoryClass(): string
    {
        return (string) config('heisenberg.models.category', Category::class);
    }

    private function findPostOrFail(string $id): Post
    {
        $class = $this->postClass();

        return $class::query()->findOrFail($id);
    }

    private function findCategoryOrFail(string $id): Category
    {
        $class = $this->categoryClass();

        return $class::query()->findOrFail($id);
    }
}
