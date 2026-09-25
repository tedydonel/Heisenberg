<?php

declare(strict_types=1);

namespace Heisenberg\Services;

use Heisenberg\Http\Controllers\PostSettingsController;
use Heisenberg\Models\Post;
use Heisenberg\Models\TocEntry;
use Heisenberg\Support\LocaleConfig;
use Illuminate\Validation\ValidationException;

/**
 * A post's authored table of contents, per locale (docs/content-translation.md §0.3).
 *
 * The entry LIST — which anchors, in what order — belongs to the post's home locale, the same
 * way a translation may change a block's text but never add, remove or reorder blocks. Every
 * other locale only supplies its own label for each existing anchor. The editor's TOC dialog
 * ({@see PostSettingsController::updateToc()}), `create_translation`'s `toc` argument and the
 * translation merge all write through here, and every reader goes through {@see payload()} or
 * {@see visitorPayload()}, so the rule has one home.
 */
class TocService
{
    public function homeLocale(Post $post): string
    {
        $locale = (string) ($post->locale ?: LocaleConfig::default());

        return in_array($locale, TocEntry::LABEL_LOCALES, true) ? $locale : TocEntry::LABEL_LOCALES[0];
    }

    /**
     * The post's entries, in order. `$fresh` queries rather than reusing a loaded relation — the
     * writers need the current rows, the readers are happy with an eager-loaded set.
     *
     * @return list<TocEntry>
     */
    public function entries(Post $post, bool $fresh = false): array
    {
        $rows = $fresh || ! $post->relationLoaded('tocEntries') ? $post->tocEntries()->get() : $post->tocEntries;

        // The relation's model class is host-configurable (heisenberg.models.toc_entry); only
        // TocEntry rows carry the per-locale label API this service reads.
        return array_values(array_filter($rows->all(), static fn ($row): bool => $row instanceof TocEntry));
    }

    /**
     * Replace the whole list, authored in the home locale. Each entry keeps the labels other
     * locales already gave its anchor, so restructuring the source never throws away a
     * translation of an entry that is still there.
     *
     * @param list<array{label: string, anchor: string}> $entries
     */
    public function replace(Post $post, array $entries): void
    {
        $home = $this->homeLocale($post);
        $existing = $this->byAnchor($this->entries($post, true));

        $post->getConnection()->transaction(function () use ($post, $entries, $existing, $home): void {
            $post->tocEntries()->delete();
            foreach ($entries as $index => $entry) {
                $attributes = [
                    'label' => $entry['label'],
                    'anchor' => $entry['anchor'],
                    'order' => $index,
                ];
                $previous = $existing[$entry['anchor']] ?? null;
                foreach (TocEntry::LABEL_LOCALES as $locale) {
                    $attributes["label_{$locale}"] = $locale === $home
                        ? $entry['label']
                        : $previous?->getAttribute("label_{$locale}");
                }
                $post->tocEntries()->create($attributes);
            }
        });
        $post->unsetRelation('tocEntries');
    }

    /**
     * Write one non-home locale's labels, keyed by anchor. Anchors the post does not have are
     * refused (nothing is written); anchors left out keep whatever that locale already had.
     *
     * @param list<array{label: string, anchor: string}> $labels
     *
     * @throws ValidationException naming the unknown anchor, or a home-locale target
     */
    public function translate(Post $post, string $locale, array $labels): void
    {
        if (! in_array($locale, TocEntry::LABEL_LOCALES, true)) {
            throw ValidationException::withMessages(['locale' => "Unsupported locale '{$locale}'."]);
        }
        if ($locale === $this->homeLocale($post)) {
            throw ValidationException::withMessages(['locale' => "'{$locale}' is this post's home locale; its table of contents is edited directly, not translated."]);
        }

        $entries = $this->byAnchor($this->entries($post, true));
        foreach ($labels as $label) {
            if (! isset($entries[$label['anchor']])) {
                throw ValidationException::withMessages(['toc' => "The table of contents has no entry with anchor '{$label['anchor']}'."]);
            }
        }

        $post->getConnection()->transaction(function () use ($entries, $labels, $locale): void {
            foreach ($labels as $label) {
                $entries[$label['anchor']]->update(["label_{$locale}" => $label['label']]);
            }
        });
        $post->unsetRelation('tocEntries');
    }

    /**
     * Fold another row's labels onto `$post`'s matching entries (by anchor), in `$locale`'s
     * column, never overwriting a label `$post` already has there. For the split-row merge.
     */
    public function foldLabels(Post $post, Post $from, string $locale): void
    {
        if (! in_array($locale, TocEntry::LABEL_LOCALES, true)) {
            return;
        }

        $theirs = $this->byAnchor($this->entries($from, true));
        foreach ($this->entries($post, true) as $entry) {
            $match = $theirs[$entry->anchor] ?? null;
            if ($match === null || trim((string) $entry->getAttribute("label_{$locale}")) !== '') {
                continue;
            }
            $entry->update(["label_{$locale}" => $match->labelFor($locale, $locale)]);
        }
        $post->unsetRelation('tocEntries');
    }

    /**
     * The list as the editor and API read it: each entry's label in `$locale` (falling back to the
     * home label), its anchor, and every locale's own label (null where there is none yet).
     *
     * @return list<array{label: string, anchor: string, labels: array<string, ?string>}>
     */
    public function payload(Post $post, ?string $locale = null): array
    {
        $home = $this->homeLocale($post);
        $locale ??= $home;

        return array_map(static fn (TocEntry $entry): array => [
            'label' => $entry->labelFor($locale, $home),
            'anchor' => $entry->anchor,
            'labels' => $entry->labels(),
        ], $this->entries($post, true));
    }

    /**
     * The list as a visitor reads it (public page, preview): each label in the locale being
     * browsed, falling back to the home label.
     *
     * @return list<array{label: string, anchor: string}>
     */
    public function visitorPayload(Post $post, string $locale): array
    {
        $home = $this->homeLocale($post);

        return array_map(static fn (TocEntry $entry): array => [
            'label' => $entry->labelFor($locale, $home),
            'anchor' => $entry->anchor,
        ], $this->entries($post));
    }

    /**
     * @param list<TocEntry> $entries
     * @return array<string, TocEntry>
     */
    private function byAnchor(array $entries): array
    {
        $keyed = [];
        foreach ($entries as $entry) {
            $keyed[$entry->anchor] = $entry;
        }

        return $keyed;
    }
}
