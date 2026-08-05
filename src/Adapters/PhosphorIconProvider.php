<?php

declare(strict_types=1);

namespace Heisenberg\Adapters;

/**
 * Default icon provider: serves the locally vendored Phosphor set
 * (resources/icons/phosphor/regular, MIT — no vendor package, no CDN).
 *
 * Extends LucideIconProvider so the traversal-safe SVG lookup from blueprint
 * §4.7 is inherited verbatim (allow-list slug → realpath both sides → confine
 * to root). Block contracts still carry Lucide slugs in `data-lucide`
 * attributes (the blueprint's wire format); LUCIDE_TO_PHOSPHOR translates the
 * ones whose names differ so those contracts keep rendering.
 */
class PhosphorIconProvider extends LucideIconProvider
{
    /** @var array<string, string> Lucide slug => Phosphor slug (where names differ) */
    public const LUCIDE_TO_PHOSPHOR = [
        'pilcrow' => 'paragraph',
        'bookmark' => 'bookmark-simple',
        'mouse-pointer-click' => 'cursor-click',
        'send' => 'paper-plane-tilt',
        'list' => 'list-bullets',
        'quote' => 'quotes',
        'external-link' => 'arrow-square-out',
        'download' => 'download-simple',
        'chevron-right' => 'caret-right',
        'chevron-down' => 'caret-down',
        'sparkles' => 'sparkle',
        'zap' => 'lightning',
        'search' => 'magnifying-glass',
        'trash-2' => 'trash',
        'settings' => 'gear',
        'more-horizontal' => 'dots-three',
        'grip-vertical' => 'dots-six-vertical',
    ];

    public function bladeComponent(string $name): string
    {
        return 'phosphor-' . ($this->slug($name) ?? '');
    }

    /**
     * Normalize to a safe slug, translating Lucide names to their Phosphor
     * equivalents. The parent's [a-z0-9-] allow-list still gates everything.
     */
    protected function slug(string $name): ?string
    {
        $slug = preg_replace('/^(lucide|phosphor|ph)-/', '', strtolower(trim($name)));
        if (! is_string($slug) || preg_match('/^[a-z0-9-]+$/', $slug) !== 1) {
            return null;
        }

        return self::LUCIDE_TO_PHOSPHOR[$slug] ?? $slug;
    }

    /** The vendored Phosphor SVG directory (always shipped with the package). */
    protected function resolveRoot(): ?string
    {
        if ($this->svgRoot !== null) {
            return $this->svgRoot;
        }

        $dir = dirname(__DIR__, 2) . '/resources/icons/phosphor/regular';

        return is_dir($dir) ? $dir : null;
    }
}
