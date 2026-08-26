<?php

declare(strict_types=1);

namespace Heisenberg\Http\Controllers;

use Heisenberg\Adapters\GuestActor;
use Heisenberg\Contracts\PostCommentProvider;
use Heisenberg\Models\Comment;
use Heisenberg\Models\Post;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Public thread/submit endpoints for the native comments system
 * (routes/comments.php, `heisenberg.comments.thread` / `.store`) — the two
 * routes an actual blog visitor hits, as opposed to CommentModerationController's
 * `/editor` management surface.
 *
 * Both actions run the SAME `PostPolicy::view` authorization
 * {@see \Heisenberg\Http\Controllers\PreviewController} does — a draft stays
 * invisible to a guest (and to `thread()`/`store()` alike), while a
 * `published` post is publicly readable per PostPolicy::view()'s status
 * branch.
 *
 * The post's own `allow_comments` flag is enforced at different depths on
 * purpose. store() refuses new comments outright (403 when off). thread()
 * stays a pure data read — the full existing thread comes back alongside the
 * `allow_comments` / `can_comment` flags, so a host rendering their own
 * visitor UI can choose either posture (hide everything, or keep showing the
 * frozen thread WordPress-style). Note the bundled views make the stricter
 * choice for their own chrome: allow_comments === false omits the whole
 * comments section from preview.blade.php entirely, existing comments
 * included ({@see PreviewController::showPost} / PostPublicController::show).
 */
class CommentController
{
    public function __construct(private PostCommentProvider $comments)
    {
    }

    /** GET /heisenberg/posts/{post}/comments — {count, items, allow_comments, can_comment, max_depth}. */
    public function thread(Request $request, string $post): JsonResponse
    {
        $model = $this->findPostOrFail($post);
        $actor = $this->actor($request);
        Gate::forUser($actor)->authorize('view', $model);

        $sort = (string) $request->query('sort', 'newest');
        if (! in_array($sort, ['newest', 'oldest'], true)) {
            $sort = 'newest'; // an unrecognized value silently falls back rather than erroring
        }

        $result = $this->comments->thread($model, $sort);

        return response()->json([
            'count' => $result['count'],
            'items' => $this->serializeItems($result['items']),
            'allow_comments' => $model->allow_comments !== false,
            'can_comment' => $this->canComment($model, $request),
            'max_depth' => (int) config('heisenberg.comments.max_depth', 3),
        ]);
    }

    /** POST /heisenberg/posts/{post}/comments (throttle:20,1) — body: {parent_id?, author_name, author_email?, body}. */
    public function store(Request $request, string $post): JsonResponse
    {
        $model = $this->findPostOrFail($post);
        $actor = $this->actor($request);
        Gate::forUser($actor)->authorize('view', $model);

        if ($model->allow_comments === false) {
            return response()->json(['message' => 'Comments are disabled for this post.'], 403);
        }

        $user = $request->user();
        $isGuest = $user === null;

        if ($isGuest && ! (bool) config('heisenberg.comments.allow_guests', true)) {
            return response()->json(['message' => 'Guest comments are not allowed on this post.'], 403);
        }

        $validated = $request->validate([
            'body' => ['required', 'string', 'max:5000'],
            'parent_id' => ['nullable', 'integer'],
            'author_email' => ['nullable', 'email', 'max:190'],
            // Required for a guest (no identity to fall back to); optional for an authenticated
            // user, who falls back to their own display name below.
            'author_name' => [$isGuest ? 'required' : 'nullable', 'string', 'max:120'],
        ]);

        if ($isGuest) {
            $authorId = null;
            $authorName = $validated['author_name'];
        } else {
            $authorId = $user->getAuthIdentifier();
            // An explicitly blank name (not just an absent key) also falls back — host user
            // models are unknown here, so `name` is read null-safely (it may not exist at all).
            $provided = trim((string) ($validated['author_name'] ?? ''));
            $authorName = $provided !== '' ? $provided : ($user->name ?? 'Member');
        }

        // A moderator's own comment never queues — same posture CommentModerationController::reply()
        // takes for a moderator's reply.
        $autoApprove = Gate::forUser($actor)->allows('moderate', $this->commentClass());

        $result = $this->comments->submit($model, [
            'parent_id' => $validated['parent_id'] ?? null,
            'author_id' => $authorId,
            'author_name' => $authorName,
            'author_email' => $validated['author_email'] ?? null,
            'body' => $validated['body'],
            'auto_approve' => $autoApprove,
        ]);

        if (! $result['ok']) {
            // The Null provider (comments disabled entirely, config('heisenberg.post_template.comments_provider'))
            // reports 'disabled' with no 'error' key — distinct from a structural rejection.
            if (($result['status'] ?? null) === 'disabled') {
                return response()->json(['message' => 'Comments are not available for this post.'], 404);
            }

            $message = match ($result['error'] ?? null) {
                'max-depth' => 'This comment thread has reached its maximum reply depth.',
                'invalid-parent' => 'The comment you are replying to is not available.',
                default => 'Unable to submit comment.',
            };

            return response()->json(['message' => $message], 422);
        }

        return response()->json([
            'ok' => true,
            'status' => $result['status'],
            'comment' => $this->serializeItem($result['comment']),
        ], 201);
    }

    /**
     * Whether $request's actor could successfully POST a comment right now — the same gate
     * store() itself enforces (allow_comments, then guest policy), surfaced to the thread
     * response so a client can decide whether to render a comment form at all.
     */
    private function canComment(Post $model, Request $request): bool
    {
        if ($model->allow_comments === false) {
            return false;
        }

        if ($request->user() === null) {
            return (bool) config('heisenberg.comments.allow_guests', true);
        }

        return true;
    }

    /** @return list<array<string, mixed>> */
    private function serializeItems(array $items): array
    {
        return array_map(fn (array $item) => $this->serializeItem($item), $items);
    }

    /**
     * Formats an Item's `created_at` to ISO-8601 (the provider hands back a Carbon instance for
     * the native adapter; any \DateTimeInterface is handled the same way, anything else — a
     * custom adapter's own representation — passes through verbatim) and walks `replies`
     * recursively. Every other key is kept exactly as the provider returned it.
     *
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    private function serializeItem(array $item): array
    {
        $item['created_at'] = $this->isoDate($item['created_at'] ?? null);
        $item['replies'] = $this->serializeItems($item['replies'] ?? []);

        return $item;
    }

    private function isoDate(mixed $value): mixed
    {
        return $value instanceof \DateTimeInterface ? $value->format(DATE_ATOM) : $value;
    }

    /**
     * The acting Authenticatable, or a {@see GuestActor} stand-in — same convention every other
     * controller in this package uses (see PostSettingsController's own docblock on this).
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

    /** @return class-string<Comment> */
    private function commentClass(): string
    {
        return (string) config('heisenberg.models.comment', Comment::class);
    }

    private function findPostOrFail(string $id): Post
    {
        $class = $this->postClass();

        return $class::query()->findOrFail($id);
    }
}
