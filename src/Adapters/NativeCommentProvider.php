<?php

declare(strict_types=1);

namespace Heisenberg\Adapters;

use Heisenberg\Contracts\PostCommentProvider;
use Heisenberg\Models\Comment;
use Heisenberg\Models\Post;
use Illuminate\Support\Collection;

/**
 * Default comments adapter (2026-08-11), reading/writing {@see \Heisenberg\Models\Comment}
 * (`heisenberg_comments`, `config('heisenberg.tables.comments')`). Bound at
 * `heisenberg.post_template.comments_provider` in place of {@see NullPostCommentProvider} —
 * see that class and `PostCommentProvider`'s docblock for the "bind your own to opt out"
 * story.
 */
class NativeCommentProvider implements PostCommentProvider
{
    public function thread(Post $post, string $sortOrder = 'newest'): array
    {
        /** @var Collection<int, Comment> $approved */
        $approved = Comment::query()
            ->where('post_id', $post->getKey())
            ->approved()
            ->orderBy('created_at', 'asc')
            ->get();

        // Group by parent_id in PHP (one query total) rather than N+1 querying children —
        // a null parent_id groups under the string key '' since array/Collection group keys
        // coerce null to '', so it's looked up explicitly below via ?? '' on both sides.
        $byParent = $approved->groupBy(fn (Comment $c) => $c->parent_id ?? '');

        $topLevel = $byParent->get('', collect());
        if ($sortOrder === 'newest') {
            $topLevel = $topLevel->sortByDesc(fn (Comment $c) => $c->created_at)->values();
        }
        // 'oldest' needs no re-sort — $approved was already fetched oldest-first.

        $items = $topLevel
            ->map(fn (Comment $c) => $this->toItem($c, $byParent))
            ->values()
            ->all();

        return [
            'count' => $approved->count(),
            'items' => $items,
        ];
    }

    public function count(Post $post): int
    {
        return Comment::query()
            ->where('post_id', $post->getKey())
            ->approved()
            ->count();
    }

    public function submit(Post $post, array $input): array
    {
        $parentId = $input['parent_id'] ?? null;
        $maxDepth = (int) config('heisenberg.comments.max_depth', 3);

        if ($parentId !== null) {
            $parent = Comment::query()->find($parentId);

            if ($parent === null || (int) $parent->post_id !== (int) $post->getKey()) {
                return ['ok' => false, 'status' => 'rejected', 'error' => 'invalid-parent'];
            }

            if (in_array($parent->status, [Comment::STATUS_SPAM, Comment::STATUS_TRASH], true)) {
                return ['ok' => false, 'status' => 'rejected', 'error' => 'invalid-parent'];
            }

            if ($parent->depth() + 1 >= $maxDepth) {
                return ['ok' => false, 'status' => 'rejected', 'error' => 'max-depth'];
            }
        }

        $autoApprove = (bool) ($input['auto_approve'] ?? config('heisenberg.comments.auto_approve', false));

        $comment = new Comment([
            'post_id' => $post->getKey(),
            'parent_id' => $parentId,
            'author_id' => $input['author_id'] ?? null,
            'author_name' => $input['author_name'],
            'author_email' => $input['author_email'] ?? null,
            'body' => $input['body'],
        ]);
        $comment->status = $autoApprove ? Comment::STATUS_APPROVED : Comment::STATUS_PENDING;
        $comment->save();

        return [
            'ok' => true,
            'status' => $comment->status,
            'comment' => $this->toItem($comment, collect()),
        ];
    }

    /**
     * @param Collection<string|int, Collection<int, Comment>> $byParent
     * @return array{id: int|string, parent_id: int|string|null, author_name: string, body: string, created_at: mixed, replies: list<mixed>}
     */
    private function toItem(Comment $comment, Collection $byParent): array
    {
        $children = $byParent->get($comment->getKey(), collect());

        return [
            'id' => $comment->getKey(),
            'parent_id' => $comment->parent_id,
            'author_name' => $comment->author_name,
            'body' => $comment->body,
            'created_at' => $comment->created_at,
            'replies' => $children
                ->map(fn (Comment $c) => $this->toItem($c, $byParent))
                ->values()
                ->all(),
        ];
    }
}
