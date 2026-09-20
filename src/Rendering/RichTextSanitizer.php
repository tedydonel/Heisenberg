<?php

declare(strict_types=1);

namespace Heisenberg\Rendering;

use Heisenberg\Services\BlockRenderer;

/**
 * Lightweight inline sanitizer for rich-text nodes (§4.6), graded by the block's
 * `security.richText` tier (study R-CTL-RT). Extracted verbatim from
 * {@see BlockRenderer}.
 *
 * SECURITY-CRITICAL: this is the tag/attribute allow-list every rich-text
 * attribute value passes through before it reaches the page.
 */
final class RichTextSanitizer
{
    /**
     * Inline allow-list for `inline-basic` rich text. Extended 2026-07-18
     * for the cPGss block toolbar: u/s/code/mark formatting plus <span>
     * whose ONLY surviving attribute is a validated color/background-color
     * style (execCommand text color + highlight output). Every other
     * attribute is scrubbed by sanitize().
     */
    private const RICH_TEXT_ALLOWED = '<a><b><br><em><i><strong><u><s><code><mark><span>';

    private const RICH_TEXT_ALLOWED_NO_LINK = '<b><br><em><i><strong><u><s><code><mark><span>';

    public function __construct(
        private CssValueSanitizer $cssValues = new CssValueSanitizer(),
    ) {
    }

    /**
     * Grades:
     *   - `none` / `plain`       → escaped plain text (no inline formatting; e.g. code)
     *   - `inline-basic`         → b/i/em/strong/br + a scheme-safe `<a>` (default)
     *   - `inline-basic-no-link` → b/i/em/strong/br, no `<a>` (e.g. button text, headings)
     */
    public function sanitize(string $value, string $tier = 'inline-basic'): string
    {
        if ($tier === 'none' || $tier === 'plain') {
            return HtmlEscaper::escape($value);
        }

        $allowLinks = $tier !== 'inline-basic-no-link';
        $allowed = $allowLinks ? self::RICH_TEXT_ALLOWED : self::RICH_TEXT_ALLOWED_NO_LINK;

        $clean = strip_tags($value, $allowed);

        if ($allowLinks) {
            // Re-parse <a> to keep only a scheme-safe href.
            $clean = (string) preg_replace_callback('/<a\b[^>]*>/i', function (array $m): string {
                if (preg_match('/href\s*=\s*("([^"]*)"|\'([^\']*)\')/i', $m[0], $h)) {
                    $href = trim($h[2] !== '' ? $h[2] : ($h[3] ?? ''));
                    if (preg_match('#^(https?:|mailto:|tel:)#i', $href) === 1) {
                        return '<a href="' . htmlspecialchars($href, ENT_QUOTES) . '">';
                    }
                }

                return '<a>';
            }, $clean);
        }

        // <span> keeps ONLY a validated color/background-color style pair
        // (the toolbar's text-color + highlight output); everything else on
        // it — events, classes, other CSS — is dropped fail-closed.
        $clean = (string) preg_replace_callback('/<span\b[^>]*>/i', function (array $m): string {
            $decls = [];
            if (preg_match('/style\s*=\s*("([^"]*)"|\'([^\']*)\')/i', $m[0], $s)) {
                $style = $s[2] !== '' ? $s[2] : ($s[3] ?? '');
                foreach (explode(';', html_entity_decode($style, ENT_QUOTES)) as $declaration) {
                    if (! str_contains($declaration, ':')) {
                        continue;
                    }
                    [$property, $rawValue] = array_map('trim', explode(':', $declaration, 2));
                    $property = strtolower($property);
                    if (! in_array($property, ['color', 'background-color'], true)) {
                        continue;
                    }
                    if ($this->cssValues->isSafeColorValue($rawValue)) {
                        $decls[] = $property . ': ' . $rawValue;
                    }
                }
            }

            return $decls === []
                ? '<span>'
                : '<span style="' . htmlspecialchars(implode('; ', $decls), ENT_QUOTES) . '">';
        }, $clean);

        // strip_tags keeps attributes on allowed tags — scrub them off the formatting tags (GTC parity).
        return (string) preg_replace('/<(b|br|em|i|strong|u|s|code|mark)\b[^>]*>/i', '<$1>', $clean);
    }
}
