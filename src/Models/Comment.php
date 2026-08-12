<?php

declare(strict_types=1);

namespace Heisenberg\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A post comment (blueprint §2.3.6 `BlogComment`, scoped down for this first cut — no
 * `meta` json, no editor-reply/feature/editorPick flags, no reaction counts; those are
 * moderation-surface concerns for a later agent to add on top of this table, not storage
 * this migration needs to reserve speculatively). Backs {@see \Heisenberg\Contracts\PostCommentProvider}
 * via {@see \Heisenberg\Adapters\NativeCommentProvider}, the default binding at
 * `heisenberg.post_template.comments_provider` (docs/post-template-schema.md).
 *
 * `status` is deliberately NOT fillable — same "never mass-assignable via a generic save"
 * posture {@see Post} takes with `allow_comments`/`featured_image_id`: a comment is always
 * created `pending` (or `approved`, when auto-approval applies) by direct property
 * assignment inside {@see \Heisenberg\Adapters\NativeCommentProvider::submit()}, and only a
 * moderation surface (a later agent's HTTP layer, gated by the `comments.moderate`
 * ability — config/heisenberg.php `roles`) may transition it afterward. Nothing upstream
 * of that surface should ever be able to self-approve a comment by slipping `status` into
 * a mass-assigned array.
 *
 * `author_id` has no FK constraint (see the migration's docblock) — it is a plain nullable
 * pointer into the host's own users table, looked up by the host when rendering a
 * moderation UI, never joined by this package. A NULL `author_id` with a populated
 * `author_name`/`author_email` is a guest comment; a non-null `author_id` is a registered
 * host user who may also have overridden `author_name` (both are always stored, even for
 * a registered user, so a thread renders correctly even if the user is later deleted).
 */
class Comment extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_SPAM = 'spam';
    public const STATUS_TRASH = 'trash';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_APPROVED,
        self::STATUS_SPAM,
        self::STATUS_TRASH,
    ];

    protected $fillable = [
        'post_id', 'parent_id', 'author_id', 'author_name', 'author_email', 'body',
    ];

    protected $casts = [
        'post_id' => 'integer',
        'parent_id' => 'integer',
        'author_id' => 'integer',
    ];

    public function getTable(): string
    {
        return config('heisenberg.tables.comments', 'heisenberg_comments');
    }

    public function post(): BelongsTo
    {
        return $this->belongsTo(config('heisenberg.models.post', Post::class), 'post_id');
    }

    /** The comment this one is a reply to, or null for a top-level comment. */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /** Direct replies, oldest first — matches the nesting order {@see \Heisenberg\Adapters\NativeCommentProvider::thread()} builds. */
    public function replies(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('created_at', 'asc');
    }

    /** @param Builder<Comment> $query */
    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_APPROVED);
    }

    /** Nesting depth: 0 for a top-level comment, incrementing once per ancestor. */
    public function depth(): int
    {
        $depth = 0;
        $node = $this;

        while ($node->parent_id !== null) {
            $node = $node->parent;
            if ($node === null) {
                break; // orphaned parent_id (shouldn't happen under the FK, but don't loop forever)
            }
            $depth++;
        }

        return $depth;
    }
}
