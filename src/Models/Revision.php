<?php

declare(strict_types=1);

namespace Heisenberg\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;

/**
 * A point-in-time snapshot of a post: its block tree (`content_blocks`), rendered
 * HTML, title and excerpt in both locales, plus who took the snapshot and when
 * (blueprint §2.3.5 `BlogRevision`). `Post::content_version` is a bare counter —
 * it cannot answer "what did this look like before"; this table can.
 *
 * `content_blocks` is an intentionally independent copy of the block tree: a
 * revision must still be inspectable/restorable even if the live `blocks` rows
 * it was snapshotted from have since changed or been deleted.
 *
 * Participates in the same `deleted_batch_id` cascade soft-delete/restore
 * mechanism as `blocks` — see {@see Post::delete()} / {@see Post::restore()}.
 *
 * NOT wired into any save/autosave/publish controller flow — that is owned by
 * a different layer. {@see snapshotOf()} is the model-level primitive a future
 * controller calls; nothing in this package invokes it yet.
 */
class Revision extends Model
{
    use SoftDeletes;

    /** Mirrors blueprint §2.3.5's `BlogRevision::TYPES`. */
    public const TYPES = ['manual', 'auto_save', 'restore'];

    protected $fillable = [
        'post_id', 'content_blocks',
        'rendered_html_en', 'rendered_html_fr',
        'title_en', 'title_fr',
        'excerpt_en', 'excerpt_fr',
        'author_id', 'revision_type',
    ];

    protected $casts = [
        'content_blocks' => 'array',
        'deleted_at' => 'datetime',
    ];

    protected $attributes = [
        'revision_type' => 'manual',
    ];

    public function getTable(): string
    {
        return config('heisenberg.tables.revisions', 'heisenberg_post_revisions');
    }

    public function post(): BelongsTo
    {
        return $this->belongsTo(config('heisenberg.models.post', Post::class), 'post_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(config('heisenberg.user_model', \App\Models\User::class), 'author_id');
    }

    /**
     * Snapshot a post's current state (its live, ordered block tree + rendered
     * HTML/title/excerpt) into a new revision row. Pure model-level primitive —
     * no controller in this package calls it; a future save/autosave/publish
     * flow does.
     *
     * @param 'manual'|'auto_save'|'restore' $type
     */
    public static function snapshotOf(Post $post, string $type = 'manual', int|string|null $authorId = null): self
    {
        $blocks = $post->relationLoaded('blocks') ? $post->blocks : $post->blocks()->get();

        $revisionClass = config('heisenberg.models.revision', self::class);

        return $revisionClass::create([
            'post_id' => $post->getKey(),
            'content_blocks' => $blocks instanceof Collection
                ? $blocks->map(static fn (Block $block): array => [
                    'id' => $block->getKey(),
                    'type' => $block->type,
                    'content' => $block->content,
                    'order' => $block->order,
                ])->values()->all()
                : [],
            'rendered_html_en' => $post->rendered_html_en,
            'rendered_html_fr' => $post->rendered_html_fr,
            'title_en' => $post->title_en,
            'title_fr' => $post->title_fr,
            'excerpt_en' => $post->excerpt_en,
            'excerpt_fr' => $post->excerpt_fr,
            'author_id' => $authorId,
            'revision_type' => in_array($type, self::TYPES, true) ? $type : 'manual',
        ]);
    }
}
