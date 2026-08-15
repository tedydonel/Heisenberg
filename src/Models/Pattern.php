<?php

declare(strict_types=1);

namespace Heisenberg\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A reusable block composition saved from the toolbar's save-as-block icon
 * (toolbar-composition.md §2/§4/§8). The saved shape is a self-contained
 * container block with its innerBlocks subtree — exactly what duplicateBlock
 * produces in-place — so insertion is a duplicate run on a fresh id set,
 * with the source block's own attributes/supports preserved.
 *
 * `blocks` is the JSON shape the editor's save payload uses (id, name,
 * attributes, supports, innerBlocks, schemaVersion — the full model the
 * runtime carries). The server treats the column as opaque: it writes
 * whatever the client sent (after a JSON-shape sanity check) and reads it
 * back verbatim. The runtime's normalizeModel turns every entry into a
 * usable live block on insert, which is why this model intentionally
 * exposes no per-field accessors — there is no business reason for one and
 * adding one would only risk the saved shape drifting from the live one.
 *
 * `name` is unique across this install (the migration's `unique` index) so
 * the toolbar's save prompt can refuse a name that's already taken instead
 * of silently overwriting someone else's pattern, and so the Blocks tab's
 * search has a stable key to match against.
 */
class Pattern extends Model
{
    protected $fillable = [
        'name', 'blocks',
    ];

    protected $casts = [
        'blocks' => 'array',
    ];

    public function getTable(): string
    {
        return config('heisenberg.tables.patterns', 'heisenberg_patterns');
    }
}