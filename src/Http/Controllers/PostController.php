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
 * intent is validated in applyTransition() (legal edge + actor tier) before
 * either column is written; autosave payloads skip transitions entirely.
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
            $request, $existing, $subject, $actor, $requestedStatus, &$conflict, &$transitionFailure
        ) {
            if ($existing === null) {
                $post = $subject;
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

            if ($requestedStatus !== null && $requestedStatus !== '' && $requestedStatus !== $post->status) {
                $transitionFailure = $this->applyTransition($post, $actor, (string) $requestedStatus, $request);
                if ($transitionFailure !== null) {
                    return null; // reject the whole save — no partial lifecycle state
                }
            }

            // Revisions, when they land, must snapshot the OLD tree here — before
            // replaceBlocks() discards it.

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
     * @return array{status: int, message: string}|null
     */
    private function applyTransition(Post $post, Authenticatable $actor, string $target, Request $request): ?array
    {
        $transitions = (array) config('heisenberg.lifecycle.transitions', []);
        $current = (string) $post->status;
        $allowed = (array) ($transitions[$current] ?? []);

        if (! in_array($target, $allowed, true)) {
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
     * The mass-assignable content fields this endpoint will ever write.
     * Deliberately excludes `author_id` (ownership never changes via a
     * content save) and status/published_at/scheduled_at (the lifecycle
     * guard in applyTransition() is the ONLY path that may set them).
     *
     * @return array<string, mixed>
     */
    private function contentAttributes(SavePostRequest $request): array
    {
        $attributes = $request->only(['title_en', 'title_fr', 'slug', 'locale', 'excerpt_en', 'excerpt_fr']);

        // title_en is NOT NULL with no DB default, and the web middleware nulls '' — normalize
        // a sent-but-empty title to the canvas placeholder. An absent key leaves the column alone.
        if (array_key_exists('title_en', $attributes) && trim((string) $attributes['title_en']) === '') {
            $attributes['title_en'] = (string) __('heisenberg::editor.canvas.ph_untitled_post');
        }

        return $attributes;
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
                'published_at' => $post->published_at?->toIso8601String(),
                'scheduled_at' => $post->scheduled_at?->toIso8601String(),
                'content_version' => $post->content_version,
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
