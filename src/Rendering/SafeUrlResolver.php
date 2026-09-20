<?php

declare(strict_types=1);

namespace Heisenberg\Rendering;

use Heisenberg\Services\BlockRenderer;

/**
 * Scheme allow-list for `src`/`href` values (§4.8, §4.10). Extracted verbatim
 * from {@see BlockRenderer::safeUrl()}.
 */
final class SafeUrlResolver
{
    public static function safeUrl(string $url): string
    {
        $url = trim($url);

        // Browsers strip ASCII tab/newline (and ignore other C0 controls) while
        // resolving a URL, so "java\tscript:…" runs as "javascript:…". Strip every
        // C0 control + DEL first so parse_url() sees the real scheme, not a
        // whitespace-obfuscated one that slips past the allow-list below.
        $url = (string) preg_replace('/[\x00-\x1F\x7F]+/', '', $url);
        if ($url === '') {
            return '';
        }

        $scheme = parse_url($url, PHP_URL_SCHEME);
        if ($scheme === false) {
            return '';
        }
        if ($scheme === null) {
            return $url; // relative or scheme-relative
        }

        return in_array(strtolower($scheme), ['http', 'https', 'mailto', 'tel'], true) ? $url : '';
    }
}
