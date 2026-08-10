<?php

declare(strict_types=1);

namespace Heisenberg\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row of a post's AUTHORED table of contents — the editorial counterpart to the
 * `tableOfContents` capability's `source: "headings"` derivation (docs/post-template-schema.md,
 * `source: "entries"`). Loosely mirrors blueprint §2.3.10's `BlogPostTocEntry` (same purpose,
 * same `post()`/`order` shape) but is a deliberately scoped-down first cut: a single `label`
 * rather than `label_en`/`label_fr`, `anchor` rather than `target`, and no `num` — bilingual
 * labels can be added later without a breaking migration if a host needs them.
 *
 * Written only by {@see \Heisenberg\Http\Controllers\PostSettingsController::updateToc()}, which
 * replaces a post's whole set on every save (delete then re-insert in submitted order). `order`
 * is fillable (the controller sets it from the submitted array's index) but is never itself part
 * of the validated request body — a client posts `{label, anchor}` pairs in the order it wants;
 * it never picks an `order` value directly.
 */
class TocEntry extends Model
{
    protected $fillable = [
        'post_id', 'label', 'anchor', 'order',
    ];

    protected $casts = [
        'order' => 'integer',
    ];

    public function getTable(): string
    {
        return config('heisenberg.tables.toc_entries', 'heisenberg_post_toc_entries');
    }

    public function post(): BelongsTo
    {
        return $this->belongsTo(config('heisenberg.models.post', Post::class), 'post_id');
    }
}
