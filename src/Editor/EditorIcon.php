<?php

declare(strict_types=1);

namespace Heisenberg\Editor;

/**
 * Editor-side icon resolver. Reads path data from the already-vendored
 * Phosphor set at resources/icons/phosphor/{regular,fill,bold}/*.svg and
 * emits a single inline <svg>. Phosphor glyphs are solid fills with
 * viewBox 0 0 256 256; we keep that shape.
 *
 * Weight matters visually, not just stylistically: "fill" is a solid
 * silhouette, "regular" is a genuinely hollow outline shape (not a thinner
 * version of the same glyph) — falling back from fill to regular changes
 * the icon's actual appearance, not just its stroke weight. Missing weights
 * are fetched from the official phosphor-icons/core source and vendored
 * here rather than approximated with the wrong weight.
 *
 * This is a thin Editor-side helper — its own Pencil-name-to-Phosphor-slug
 * mapping, independent of anything else that reads the same vendored SVG set.
 */
final class EditorIcon
{
    /** Editor name => Phosphor slug. Names that don't ship in the regular
     *  Phosphor weight fall back through the candidate list below. */
    private const ALIASES = [
        'align-left'             => 'text-align-left',
        'align-center'           => 'text-align-center',
        'align-right'            => 'text-align-right',
        'align-justify'          => 'text-align-justify',
        'format_align_left'      => 'text-align-left',
        'format_align_center'    => 'text-align-center',
        'format_align_right'     => 'text-align-right',
        'format_align_justify'   => 'text-align-justify',
        'vertical_align_top'     => 'align-top',
        'vertical_align_center'  => 'align-center-vertical',
        'vertical_align_bottom'  => 'align-bottom',
        'format_size'            => 'text-t',
        'format_line_spacing'    => 'arrows-vertical',
        'format_letter_spacing'  => 'arrows-horizontal',
        'border_left'            => 'align-left',
        'border_right'           => 'align-right',
        'border_top'             => 'align-top',
        'border_bottom'          => 'align-bottom',
        'rounded_corner'         => 'frame-corners',
        'horizontal_rule'        => 'minus',
        // Contract icons are Lucide slugs (Appendix C / BlockType::icon()); these two have no
        // same-named Phosphor asset, so map them to their visual equivalents.
        'mouse-pointer-click'    => 'cursor-click',
        'quote'                  => 'quotes',
        'selection-all-fill'     => 'selection-all',
        'caret-down'             => 'caret-down',
        'sidebar-simple'         => 'sidebar-simple',
        'arrow-up'               => 'arrow-up',
        'arrow-down'             => 'arrow-down',
        'dots-six-vertical'      => 'dots-six-vertical',
        'text-bolder-light'      => 'text-b',
        'text-italic'            => 'text-italic',
        'text-underline'         => 'text-underline',
        'text-strikethrough'     => 'text-strikethrough',
        'text-t'                 => 'text-t',
        'link'                   => 'link',
        'code'                   => 'code',
        'text-align-right-light' => 'text-align-right',
        'sparkle'                => 'sparkle',
        'dots-three'             => 'dots-three',
        'arrow-counter-clockwise' => 'arrow-counter-clockwise',
        'arrow-clockwise'        => 'arrow-clockwise',
        'eye'                    => 'eye',
        'minus'                  => 'minus',
        'percent'                => 'percent',
        'check-bold'             => 'check',
        'list'                   => 'list',
        // Custom Style-inspector glyphs. They are deliberately housed alongside the vendored
        // Phosphor assets so the resolver keeps its single-path, inline-SVG contract.
        'style-corner-radius-all'          => 'style-corner-radius-all',
        'style-corner-radius-top-left'     => 'style-corner-radius-top-left',
        'style-corner-radius-top-right'    => 'style-corner-radius-top-right',
        'style-corner-radius-bottom-left'  => 'style-corner-radius-bottom-left',
        'style-corner-radius-bottom-right' => 'style-corner-radius-bottom-right',
        'style-padding-all'        => 'style-padding-all',
        'style-padding-horizontal' => 'style-padding-horizontal',
        'style-padding-vertical'   => 'style-padding-vertical',
        'style-padding-left'       => 'style-padding-left',
        'style-padding-right'      => 'style-padding-right',
        'style-padding-top'        => 'style-padding-top',
        'style-padding-bottom'     => 'style-padding-bottom',
        // The source Alignment strip uses filled geometry, not regular outlines.
        'align-left-fill'              => 'align-left-fill',
        'align-center-horizontal-fill' => 'align-center-horizontal-fill',
        'align-right-fill'             => 'align-right-fill',
        'align-top-fill'               => 'align-top-fill',
        'align-center-vertical-fill'   => 'align-center-vertical-fill',
        'align-bottom-fill'            => 'align-bottom-fill',
        // Lucide slug used by resources/blocks/paragraph/paragraph.json's `icon` — no Phosphor
        // icon is literally named "pilcrow", but its own "paragraph" glyph is the same concept.
        'pilcrow'                => 'paragraph',
    ];

    /** @var array<string,string> slug => path data */
    private static array $pathCache = [];

    /**
     * Return an inline <svg> for the given Editor icon name.
     * Falls back to an empty SVG if the slug is unknown.
     */
    public static function svg(string $name, int $size = 16): string
    {
        $slug = self::resolveSlug($name);
        $d = $slug === null ? '' : self::pathData($slug);

        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 256 256" '
            . 'width="' . (int) $size . '" height="' . (int) $size . '" '
            . 'fill="currentColor" aria-hidden="true" focusable="false">'
            . ($d === '' ? '' : '<path d="' . htmlspecialchars($d, ENT_QUOTES) . '"/>')
            . '</svg>';
    }

    /**
     * Resolve a Pencil/Editor name to a Phosphor slug that exists on disk.
     * Returns null when no candidate file matches.
     */
    public static function resolveSlug(string $name): ?string
    {
        if ($name === '') {
            return null;
        }

        $slug = self::ALIASES[$name] ?? $name;
        foreach (self::candidatePaths($slug) as $file) {
            if (is_file($file)) {
                return $slug;
            }
        }

        return null;
    }

    /**
     * Candidate file paths for a slug, most-faithful first. The exact-weight
     * file (fill/ or bold/) always wins when it exists; falling back to
     * regular/ is a last resort that changes the glyph's visual style (solid
     * -> hollow), not just its stroke weight, so it's only reached when the
     * true-weight asset genuinely isn't vendored.
     *
     * @return array<int,string>
     */
    private static function candidatePaths(string $slug): array
    {
        $base = dirname(__DIR__, 2) . '/resources/icons/phosphor';
        $weight = match (true) {
            str_ends_with($slug, '-fill') => 'fill',
            str_ends_with($slug, '-bold') => 'bold',
            str_ends_with($slug, '-light') => 'light',
            default => 'regular',
        };
        $stripped = preg_replace('/-(fill|bold|light)$/', '', $slug) ?? $slug;

        return [
            $base . '/' . $weight . '/' . $slug . '.svg',
            $base . '/regular/' . $slug . '.svg',
            $base . '/regular/' . $stripped . '.svg',
        ];
    }

    private static function pathData(string $slug): string
    {
        if (isset(self::$pathCache[$slug])) {
            return self::$pathCache[$slug];
        }

        $svg = '';
        foreach (self::candidatePaths($slug) as $file) {
            if (is_file($file)) {
                $svg = (string) file_get_contents($file);
                break;
            }
        }

        $d = preg_match('/\sd="([^"]+)"/', $svg, $m) === 1 ? $m[1] : '';

        return self::$pathCache[$slug] = $d;
    }
}
