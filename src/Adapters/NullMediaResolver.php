<?php

declare(strict_types=1);

namespace Heisenberg\Adapters;

use Heisenberg\Contracts\MediaResolver;

/**
 * Default media resolver: returns the scheme-checked raw URL with no srcset/sizes —
 * byte-identical to the GTC renderer's fallback path when no `PublicFile` matched.
 * A host with a real media library binds its own resolver. (Blueprint §4.8, §13.2)
 */
class NullMediaResolver implements MediaResolver
{
    public function resolve(string $url, string $context): array
    {
        return [
            'url'    => $this->safeUrl($url),
            'srcset' => null,
            'sizes'  => null,
        ];
    }

    public function browse(int $page = 1, int $perPage = 24): iterable
    {
        return [];
    }

    /**
     * Allow http(s), scheme-relative ("//host/…") and relative ("/path", "img.jpg")
     * URLs; drop anything with a non-http(s) scheme (javascript:, data:, file:, …).
     * This is the renderer's last line of defence (§4.8) and must not be loosened.
     */
    protected function safeUrl(string $url): string
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

        // Malformed URL — refuse it.
        if ($scheme === false) {
            return '';
        }

        // No scheme -> relative or scheme-relative -> allowed.
        if ($scheme === null) {
            return $url;
        }

        return in_array(strtolower($scheme), ['http', 'https'], true) ? $url : '';
    }
}
