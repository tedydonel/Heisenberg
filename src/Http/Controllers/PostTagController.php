<?php

declare(strict_types=1);

namespace Heisenberg\Http\Controllers;

use Heisenberg\Adapters\GuestActor;
use Heisenberg\Models\Post;
use Heisenberg\Models\Tag;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Attach/detach a tag to/from a post (Post::tags() BelongsToMany via the
 * `heisenberg_post_tag` pivot). Same PostPolicy::update() authorization
 * rationale as PostCategoryController — see that class's docblock.
 */
class PostTagController
{
    /** POST /editor/posts/{post}/tags/{tag} */
    public function attach(Request $request, string $post, string $tag): JsonResponse
    {
        $postModel = $this->findPostOrFail($post);
        Gate::forUser($this->actor($request))->authorize('update', $postModel);

        $tagModel = $this->findTagOrFail($tag);

        $postModel->tags()->syncWithoutDetaching([$tagModel->getKey()]);

        return response()->json($this->payload($postModel->fresh(['tags'])));
    }

    /** DELETE /editor/posts/{post}/tags/{tag} */
    public function detach(Request $request, string $post, string $tag): JsonResponse
    {
        $postModel = $this->findPostOrFail($post);
        Gate::forUser($this->actor($request))->authorize('update', $postModel);

        $tagModel = $this->findTagOrFail($tag);

        $postModel->tags()->detach($tagModel->getKey());

        return response()->json($this->payload($postModel->fresh(['tags'])));
    }

    /** @return array<string, mixed> */
    private function payload(Post $post): array
    {
        return [
            'post_id' => $post->id,
            'tags' => $post->tags->map(fn (Tag $tag) => [
                'id' => $tag->id,
                'name_en' => $tag->name_en,
                'name_fr' => $tag->name_fr,
                'slug' => $tag->slug,
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

    /** @return class-string<Tag> */
    private function tagClass(): string
    {
        return (string) config('heisenberg.models.tag', Tag::class);
    }

    private function findPostOrFail(string $id): Post
    {
        $class = $this->postClass();

        return $class::query()->findOrFail($id);
    }

    private function findTagOrFail(string $id): Tag
    {
        $class = $this->tagClass();

        return $class::query()->findOrFail($id);
    }
}
