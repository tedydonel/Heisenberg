<?php

declare(strict_types=1);

namespace Heisenberg\Services;

/**
 * The block-icon library: the SVG collection imported from VvvebJs (30 icon sets,
 * see resources/icons/blocks/CREDITS.md) as individual sanitized files plus a
 * manifest. Everything is manifest-gated and fail-closed: a set/slug pair that
 * the manifest doesn't list never touches the filesystem, so the route and the
 * renderer share one allow-list and there is no traversal surface.
 *
 * Icons are referenced as "<set>/<slug>" (e.g. "feather/activity") — the shape
 * the icon block's `icon` attribute stores and the picker writes.
 */
class IconLibraryService
{
    private const REF_PATTERN = '/^([a-z0-9-]+)\/([a-z0-9-]+)$/';

    /** @var array{sets: array<string, array{count: int, icons: string[]}>, total: int}|null */
    private ?array $manifest = null;

    /**
     * @return array{sets: array<string, array{count: int, icons: string[]}>, total: int}
     */
    public function manifest(): array
    {
        if ($this->manifest !== null) {
            return $this->manifest;
        }

        $file = $this->root() . '/manifest.json';
        $decoded = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        if (! is_array($decoded) || ! isset($decoded['sets']) || ! is_array($decoded['sets'])) {
            return $this->manifest = ['sets' => [], 'total' => 0];
        }

        return $this->manifest = ['sets' => $decoded['sets'], 'total' => (int) ($decoded['total'] ?? 0)];
    }

    /** Is "<set>/<slug>" a real, manifest-listed icon? */
    public function exists(string $reference): bool
    {
        return $this->parse($reference) !== null;
    }

    /**
     * The sanitized SVG markup for "<set>/<slug>", or null when unknown. The
     * files were sanitized at import time (no scripts/handlers/foreign objects,
     * viewBox present, root sizing stripped) — see the importer notes in
     * resources/icons/blocks/CREDITS.md.
     */
    public function svg(string $reference): ?string
    {
        $parsed = $this->parse($reference);
        if ($parsed === null) {
            return null;
        }

        $path = $this->root() . '/' . $parsed[0] . '/' . $parsed[1] . '.svg';

        return is_file($path) ? $this->colorized((string) file_get_contents($path)) : null;
    }

    /**
     * Make a single-colour icon follow the block's `color` (the icon block paints by setting
     * `color` on the wrapper, which only reaches the glyph through `currentColor`).
     *
     * Most sets already ship `currentColor` and pass through untouched. Two shipped ones cannot
     * paint at all as imported: remix-icon's drawn paths carry no `fill`, so they default to
     * black, and iconsax's carry an empty `fill=""` the importer left behind. Both ignored the
     * block's colour entirely, on the canvas and in the render.
     *
     * Deliberately conservative — a MULTI-colour icon is left exactly as it is, since rewriting
     * it would flatten artwork the author chose for its colours.
     */
    private function colorized(string $svg): string
    {
        if ($svg === '' || stripos($svg, 'currentColor') !== false) {
            return $svg;
        }

        // An empty paint attribute is invalid CSS/SVG — the importer's leftover, and the whole
        // reason these icons render as a default black (or not at all, under a `fill="none"`
        // root). It always meant "the icon's own colour".
        $svg = (string) preg_replace('/\b(fill|stroke)=""/i', '$1="currentColor"', $svg, -1, $emptied);

        // Every explicit paint the file names, ignoring `none` (a deliberate non-paint, e.g.
        // remix-icon's invisible bounding-box path) and the gradient/pattern references that mark
        // artwork this must not touch.
        preg_match_all('/\b(?:fill|stroke)="([^"]+)"/i', $svg, $matches);
        $paints = array_values(array_unique(array_filter(
            array_map(static fn (string $v): string => strtolower(trim($v)), $matches[1]),
            static fn (string $v): bool => $v !== '' && $v !== 'none' && $v !== 'currentcolor',
        )));
        if (count($paints) > 1 || str_contains(strtolower($svg), 'url(')) {
            return $svg; // multi-colour or gradient-painted: leave the artwork alone
        }

        if ($paints !== []) {
            return (string) preg_replace(
                '/\b(fill|stroke)="' . preg_quote($paints[0], '/') . '"/i',
                '$1="currentColor"',
                $svg,
            );
        }

        // Nothing names a paint: the drawn shapes are inheriting, and what they inherit is either
        // the root's `fill` or the initial black. Give the root `currentColor` so they follow the
        // block — children that opted out with `fill="none"` keep it, since they say so.
        if ($emptied > 0 || preg_match('/^<svg[^>]*\bfill=/i', $svg) === 1) {
            return $svg;
        }

        return (string) preg_replace('/^<svg\b/i', '<svg fill="currentColor"', $svg, 1);
    }

    /**
     * The same markup, but only when it is safe to hand a client that will INLINE it (the picker
     * injects rather than <img src>, which turns 61 requests per page into one — an <img> is an
     * isolated document, inline markup is not). The shipped library is sanitized at import time;
     * this is the fail-closed guard for a host that points `heisenberg.icon_root` at its own
     * files: anything but a plain <svg> element, or carrying a script/handler/external
     * reference, is refused here and simply falls back to the per-icon <img> URL.
     */
    public function inlineSvg(string $reference): ?string
    {
        $svg = $this->svg($reference);
        if ($svg === null) {
            return null;
        }

        $svg = trim($svg);
        if (stripos($svg, '<svg') !== 0 || ! str_ends_with($svg, '</svg>')) {
            return null;
        }
        // <script>/<foreignObject>/<use href> and any on* handler: an inline SVG runs them.
        if (preg_match('/<\s*(script|foreignObject|iframe|image|use)\b|\bon[a-z]+\s*=|(href|xlink:href)\s*=/i', $svg) === 1) {
            return null;
        }

        return $svg;
    }

    /**
     * Search the manifest by substring. Empty query lists everything (paged).
     *
     * @return array{icons: array<int, array{set: string, slug: string}>, total: int}
     */
    public function search(string $query, ?string $set = null, int $limit = 60, int $offset = 0): array
    {
        $query = strtolower(trim($query));
        $limit = max(1, min(200, $limit));
        $offset = max(0, $offset);

        $matches = [];
        foreach ($this->manifest()['sets'] as $setName => $meta) {
            if ($set !== null && $set !== '' && $set !== $setName) {
                continue;
            }
            foreach (($meta['icons'] ?? []) as $slug) {
                if ($query === '' || str_contains($slug, $query)) {
                    $matches[] = ['set' => $setName, 'slug' => (string) $slug];
                }
            }
        }

        return [
            'icons' => array_slice($matches, $offset, $limit),
            'total' => count($matches),
        ];
    }

    /** The set names in the manifest, for the picker's filter. @return string[] */
    public function sets(): array
    {
        return array_keys($this->manifest()['sets']);
    }

    /** @return array{0: string, 1: string}|null [set, slug] when valid AND manifest-listed */
    private function parse(string $reference): ?array
    {
        if (preg_match(self::REF_PATTERN, $reference, $m) !== 1) {
            return null;
        }
        $icons = $this->manifest()['sets'][$m[1]]['icons'] ?? null;

        return is_array($icons) && in_array($m[2], $icons, true) ? [$m[1], $m[2]] : null;
    }

    private function root(): string
    {
        return (string) (config('heisenberg.icon_root') ?: dirname(__DIR__, 2) . '/resources/icons/blocks');
    }
}
