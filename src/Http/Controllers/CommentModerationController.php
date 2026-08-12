<?php

declare(strict_types=1);

namespace Heisenberg\Http\Controllers;

use Heisenberg\Adapters\GuestActor;
use Heisenberg\Contracts\PostCommentProvider;
use Heisenberg\Models\Comment;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Moderation surface for the native comments system (routes/comments.php,
 * `heisenberg.comments.moderation.*`): the management page plus the JSON
 * list/status/delete/reply endpoints it drives. Every action here — including
 * `page()` — authorizes the `comments.moderate` ability (CommentPolicy,
 * admin/editor tiers by default) FIRST, so a non-moderator actor gets a 403
 * rather than ever reaching the view or the data underneath it.
 */
class CommentModerationController
{
    public function __construct(private PostCommentProvider $comments)
    {
    }

    /**
     * GET /editor/comments — the moderation management page. A thin, self-contained
     * server-rendered page (same posture as media/index.blade.php) driving index()/update()/
     * destroy()/reply() below entirely over fetch(); this action's only job is the gate check
     * (a non-moderator actor gets 403 here exactly like every other action in this controller)
     * plus handing the view the URL templates it needs so its script never hardcodes a path —
     * same `__ID__` placeholder convention EditorController::sharedViewData() uses.
     */
    public function page(Request $request): View
    {
        Gate::forUser($this->actor($request))->authorize('moderate', $this->commentClass());

        return view('heisenberg::comments.index', [
            'dataUrl' => route('heisenberg.comments.moderation.index'),
            'updateUrlTemplate' => route('heisenberg.comments.moderation.update', ['comment' => '__ID__']),
            'destroyUrlTemplate' => route('heisenberg.comments.moderation.destroy', ['comment' => '__ID__']),
            'replyUrlTemplate' => route('heisenberg.comments.moderation.reply', ['comment' => '__ID__']),
            'postPreviewUrlTemplate' => route('heisenberg.editor.preview.post', ['post' => '__ID__']),
        ]);
    }

    /**
     * GET /editor/comments/data — the management UI's list. Query params: `status`
     * (default 'pending'; a Comment::STATUSES value or 'all'), `post_id`, `q` (matches
     * `body`/`author_name` LIKE), `page`. `counts` totals every status for the current
     * `post_id`/`q` filter (unaffected by `status` itself, so the UI's tabs can show every
     * count at once without four separate requests).
     */
    public function index(Request $request): JsonResponse
    {
        Gate::forUser($this->actor($request))->authorize('moderate', $this->commentClass());

        $status = (string) $request->query('status', Comment::STATUS_PENDING);
        if ($status !== 'all' && ! in_array($status, Comment::STATUSES, true)) {
            $status = Comment::STATUS_PENDING;
        }

        $postId = $request->query('post_id');
        $q = trim((string) $request->query('q', ''));

        $class = $this->commentClass();
        $scoped = $class::query();
        if ($postId !== null && $postId !== '') {
            $scoped->where('post_id', (int) $postId);
        }
        if ($q !== '') {
            $scoped->where(function ($query) use ($q): void {
                $query->where('body', 'like', '%' . $q . '%')
                    ->orWhere('author_name', 'like', '%' . $q . '%');
            });
        }

        $counts = (clone $scoped)
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $list = clone $scoped;
        if ($status !== 'all') {
            $list->where('status', $status);
        }

        $perPage = (int) config('heisenberg.comments.per_page', 50);
        $paginator = $list->with('post')
            ->withCount('replies')
            ->orderByDesc('created_at')
            ->paginate($perPage);

        $rows = collect($paginator->items())->map(fn (Comment $c) => [
            'id' => $c->id,
            'post_id' => $c->post_id,
            'post_title' => ($c->post?->title_en) ?: 'Untitled',
            'parent_id' => $c->parent_id,
            'author_name' => $c->author_name,
            'author_email' => $c->author_email,
            'author_id' => $c->author_id,
            'body' => $c->body,
            'status' => $c->status,
            'created_at' => optional($c->created_at)->format(DATE_ATOM),
            'reply_count' => $c->replies_count,
        ])->values()->all();

        return response()->json([
            'data' => $rows,
            'counts' => [
                'pending' => (int) ($counts[Comment::STATUS_PENDING] ?? 0),
                'approved' => (int) ($counts[Comment::STATUS_APPROVED] ?? 0),
                'spam' => (int) ($counts[Comment::STATUS_SPAM] ?? 0),
                'trash' => (int) ($counts[Comment::STATUS_TRASH] ?? 0),
            ],
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    /** PATCH /editor/comments/{comment} — body: {status}. Direct property write, same posture as Comment's own docblock. */
    public function update(Request $request, string $comment): JsonResponse
    {
        Gate::forUser($this->actor($request))->authorize('moderate', $this->commentClass());

        $model = $this->findCommentOrFail($comment);

        $validated = $request->validate([
            'status' => ['required', 'string', 'in:' . implode(',', Comment::STATUSES)],
        ]);

        $model->status = $validated['status'];
        $model->save();

        return response()->json(['id' => $model->id, 'status' => $model->status]);
    }

    /** DELETE /editor/comments/{comment} — hard delete; replies cascade via the FK. */
    public function destroy(Request $request, string $comment): JsonResponse
    {
        Gate::forUser($this->actor($request))->authorize('moderate', $this->commentClass());

        $model = $this->findCommentOrFail($comment);
        $model->delete();

        return response()->json(['deleted' => true]);
    }

    /**
     * POST /editor/comments/{comment}/reply — body: {body}. Creates an already-approved reply
     * to $comment via the SAME provider `submit()` path store() uses, so a moderator reply gets
     * the identical structural validation (same-post/not-spam-trash/max-depth) a visitor's
     * comment does. If $comment itself is still `pending`, it is approved first — replying to a
     * comment is, in practice, a moderator engaging with it, and leaving it queued while its own
     * reply is already live would be a confusing, inconsistent state for a thread to be in.
     */
    public function reply(Request $request, string $comment): JsonResponse
    {
        $actor = $this->actor($request);
        Gate::forUser($actor)->authorize('moderate', $this->commentClass());

        $parent = $this->findCommentOrFail($comment);

        $validated = $request->validate([
            'body' => ['required', 'string', 'max:5000'],
        ]);

        if ($parent->status === Comment::STATUS_PENDING) {
            $parent->status = Comment::STATUS_APPROVED;
            $parent->save();
        }

        $user = $request->user();
        // Same author-name resolution as CommentController::store(); a GuestActor only ever
        // reaches here via the local-dev bypass, where 'Moderator' is the right stand-in name.
        $authorName = $user !== null ? (trim((string) ($user->name ?? '')) ?: 'Moderator') : 'Moderator';

        $result = $this->comments->submit($parent->post, [
            'parent_id' => $parent->id,
            'author_id' => $user?->getAuthIdentifier(),
            'author_name' => $authorName,
            'body' => $validated['body'],
            'auto_approve' => true,
        ]);

        if (! $result['ok']) {
            $message = match ($result['error'] ?? null) {
                'max-depth' => 'This comment thread has reached its maximum reply depth.',
                'invalid-parent' => 'This comment can no longer be replied to.',
                default => 'Unable to submit reply.',
            };

            return response()->json(['message' => $message], 422);
        }

        return response()->json($this->serializeItem($result['comment']), 201);
    }

    /**
     * Formats an Item's `created_at` to ISO-8601 — same helper CommentController carries, kept
     * duplicated rather than shared to avoid a trait for two call sites across two small
     * controllers.
     *
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    private function serializeItem(array $item): array
    {
        $item['created_at'] = $item['created_at'] instanceof \DateTimeInterface
            ? $item['created_at']->format(DATE_ATOM)
            : ($item['created_at'] ?? null);
        $item['replies'] = array_map(fn (array $child) => $this->serializeItem($child), $item['replies'] ?? []);

        return $item;
    }

    /**
     * The acting Authenticatable, or a {@see GuestActor} stand-in — same convention every other
     * controller in this package uses (see PostSettingsController's own docblock on this).
     */
    private function actor(Request $request): Authenticatable
    {
        return $request->user() ?? new GuestActor();
    }

    /** @return class-string<Comment> */
    private function commentClass(): string
    {
        return (string) config('heisenberg.models.comment', Comment::class);
    }

    private function findCommentOrFail(string $id): Comment
    {
        $class = $this->commentClass();

        return $class::query()->findOrFail($id);
    }
}
