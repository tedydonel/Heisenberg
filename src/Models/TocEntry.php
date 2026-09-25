<?php

declare(strict_types=1);

namespace Heisenberg\Models;

use Heisenberg\Http\Controllers\PostSettingsController;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row of a post's AUTHORED table of contents — the editorial counterpart to the
 * `tableOfContents` capability's `source: "headings"` derivation (docs/post-template-schema.md,
 * `source: "entries"`). Loosely mirrors blueprint §2.3.10's `BlogPostTocEntry`: `anchor` rather
 * than `target`, and no `num`.
 *
 * The label is TRANSLATABLE (docs/content-translation.md §0): `label_en`/`label_fr` hold each
 * locale's text, like `title_*`. `anchor` and `order` are shared by every locale — the anchor is
 * the DOM id of the one rendered heading. The bare `label` column mirrors the post's HOME-locale
 * label, so hosts that read `$entry->label` keep working; read {@see labelFor()} for a locale.
 *
 * Written by {@see PostSettingsController::updateToc()} (the editor's dialog) and
 * `create_translation`'s `toc` argument. `order` is set from the submitted array's index — a
 * client posts `{label, anchor}` pairs in the order it wants; it never picks an `order` directly.
 *
 * @property int $post_id
 * @property string $label The home-locale label, kept for hosts that read it directly.
 * @property string|null $label_en
 * @property string|null $label_fr
 * @property string $anchor
 * @property int $order
 */
class TocEntry extends Model
{
    protected $fillable = [
        'post_id', 'label', 'label_en', 'label_fr', 'anchor', 'order',
    ];

    /** The per-locale label columns. Fixed, like `title_en`/`title_fr` on the posts table. */
    public const LABEL_LOCALES = ['en', 'fr'];

    /**
     * This entry's label in `$locale`: that locale's column, else the home-locale column, else the
     * bare `label` — never empty for a saved entry, so an untranslated entry still reads as its
     * source text rather than vanishing (the same fallback posture as {@see Post::title()}).
     */
    public function labelFor(?string $locale, ?string $homeLocale = null): string
    {
        $own = in_array($locale, self::LABEL_LOCALES, true) ? $this->getAttribute("label_{$locale}") : null;
        $home = in_array($homeLocale, self::LABEL_LOCALES, true) ? $this->getAttribute("label_{$homeLocale}") : null;

        return (string) ($own ?: ($home ?: $this->label));
    }

    /**
     * Every locale's label for this entry — null where that locale has none of its own yet.
     *
     * @return array<string, ?string>
     */
    public function labels(): array
    {
        $labels = [];
        foreach (self::LABEL_LOCALES as $locale) {
            $value = trim((string) $this->getAttribute("label_{$locale}"));
            $labels[$locale] = $value !== '' ? $value : null;
        }

        return $labels;
    }

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
