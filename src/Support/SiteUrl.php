<?php

declare(strict_types=1);

namespace Heisenberg\Support;

/**
 * One answer to "where does the public site live?".
 *
 * The editor is frequently NOT served from the public site: a host mounts Heisenberg inside an
 * admin or staff dashboard on its own subdomain, so the request the editor renders under says
 * `admin.example.com` while readers are on `example.com`. Nothing in that request can tell the
 * two apart, which is why every surface that needs the public address asks here instead of
 * guessing from `request()->getHost()` or `window.location`.
 *
 * Resolution order: `heisenberg.site_url`, then Laravel's own `app.url`. Both unset (or set to
 * the framework's placeholder default) yields an empty string — callers then show a placeholder
 * rather than inventing a domain, which is the honest answer when nobody has said.
 */
final class SiteUrl
{
    /** The configured public site root, without a trailing slash, or '' when unset. */
    public static function base(): string
    {
        foreach ([config('heisenberg.site_url'), config('app.url')] as $candidate) {
            if (! is_string($candidate)) {
                continue;
            }
            $value = trim($candidate);
            // Laravel ships `APP_URL=http://localhost`; treating that as a real public site
            // would put "localhost" in a canonical tag, which is worse than no answer.
            if ($value === '' || self::isPlaceholder($value)) {
                continue;
            }

            return rtrim($value, '/');
        }

        return '';
    }

    /** Just the host of {@see base()} ('example.com'), or '' when unset. */
    public static function host(): string
    {
        $base = self::base();
        if ($base === '') {
            return '';
        }

        $host = parse_url($base, PHP_URL_HOST);

        return is_string($host) && $host !== '' ? $host : '';
    }

    /**
     * Move an absolute URL onto the public site, keeping its path and query.
     *
     * Used for URLs that Laravel's route generator built against the CURRENT request — correct
     * in shape, wrong in host whenever the editor runs on a different domain than the site.
     * Returns the URL untouched when no site URL is configured.
     */
    public static function rebase(string $url): string
    {
        $base = self::base();
        if ($base === '' || $url === '') {
            return $url;
        }

        $path = parse_url($url, PHP_URL_PATH);
        $query = parse_url($url, PHP_URL_QUERY);
        $fragment = parse_url($url, PHP_URL_FRAGMENT);
        if (! is_string($path)) {
            return $url;
        }

        return $base . $path
            . (is_string($query) && $query !== '' ? '?' . $query : '')
            . (is_string($fragment) && $fragment !== '' ? '#' . $fragment : '');
    }

    private static function isPlaceholder(string $value): bool
    {
        $host = parse_url($value, PHP_URL_HOST);

        return in_array($host, ['localhost', '127.0.0.1', '::1'], true);
    }
}
