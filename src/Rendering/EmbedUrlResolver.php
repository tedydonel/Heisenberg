<?php

declare(strict_types=1);

namespace Heisenberg\Rendering;

use Heisenberg\Services\BlockRenderer;

/**
 * Normalizes a pasted video-provider URL (`embed` block) to a canonical,
 * allow-listed player `src`, and validates a self-hosted `<video src>`.
 * Extracted verbatim from {@see BlockRenderer}, which
 * keeps `EMBED_SRC_PATTERN`/`EMBED_FILE_SRC_PATTERN`/`embedSrcFor()`/
 * `embedFileSrcFor()` as public aliases/delegates for backward compatibility
 * (tests assert against the real constants/methods via `BlockRenderer::`).
 *
 * Every method here is static and pure — no request/container state — which
 * is exactly how the original methods on BlockRenderer were declared.
 */
final class EmbedUrlResolver
{
    /**
     * Iframe src allowlist (embed block) — the LAST line of defence, applied in
     * renderNode() to every `<iframe src>` no matter which contract produced it.
     * {@see embedSrcFor()} may narrow this set, never widen it.
     *
     * Every branch pins an EXACT host: the alternation sits immediately behind
     * `^https://`, so a suffix lookalike (`evil-dailymotion.com`,
     * `notyoutube.com`, `player.vimeo.com.evil.com`) can never satisfy it. The one
     * variable host — Cloudflare Stream's per-customer subdomain — is bounded to a
     * SINGLE label (`[a-z0-9]{1,40}`, no dot in the class) that must be followed
     * literally by `.cloudflarestream.com/`, so `customer-a.evil-cloudflarestream.com`
     * and `customer-a.evil.com` both fail.
     *
     * Public so tests can assert against the real constant instead of a copy that
     * could silently drift from it.
     */
    public const EMBED_SRC_PATTERN = '#^https://(?:'
        . 'www\.youtube(?:-nocookie)?\.com/embed/'
        . '|player\.vimeo\.com/video/'
        . '|www\.dailymotion\.com/embed/video/'
        . '|www\.loom\.com/embed/'
        . '|fast\.wistia\.net/embed/iframe/'
        . '|streamable\.com/e/'
        . '|www\.tiktok\.com/embed/v2/'
        . '|customer-[a-z0-9]{1,40}\.cloudflarestream\.com/'
        . ')[A-Za-z0-9_/?=&.-]+$#';

    /**
     * Self-hosted video allowlist — the `<video src>` counterpart of
     * EMBED_SRC_PATTERN, and the whole of {@see embedFileSrcFor()}'s gate.
     * https ONLY (a media file loaded over http would mixed-content-block on any
     * real page, and a scheme-relative URL gives us nothing to validate), a host
     * whose charset excludes `@` and `/` (so `https://evil.com@cdn.good/x.mp4`
     * cannot parse as a "host" here), and a path that must END in a known video
     * extension before any `?query`/`#fragment`.
     */
    public const EMBED_FILE_SRC_PATTERN = '#^https://[A-Za-z0-9](?:[A-Za-z0-9.-]{0,251}[A-Za-z0-9])?(?::[0-9]{1,5})?'
        . '/[A-Za-z0-9._~%!$&()*+,;=:/-]*\.(?:mp4|webm|ogg|ogv|mov)'
        . '(?:\?[A-Za-z0-9._~%!$&()*+,;=:/?-]*)?(?:\#[A-Za-z0-9._~%!$&()*+,;=:/?-]*)?$#i';

    /**
     * The pasted-URL forms {@see embedSrcFor()} accepts, in match order (first wins),
     * each capturing what the canonical embed URL needs. `out` selects the builder.
     *
     * Anchored at `^` with every host spelled out literally, so no crafted authority
     * can smuggle a foreign origin past them: `youtube.com@evil.com/...`,
     * `notyoutube.com/...`, `www.youtube.com.evil.com/...` and
     * `https://evil.com/https://youtube.com/...` all fail to match. The scheme prefix
     * `(?:(?:https?:)?//)?` accepts exactly `https://`, `http://`, a protocol-relative
     * `//`, or nothing — never `javascript:`/`data:`, which cannot reach the host
     * literal. Every id charset is bounded and must be followed by a query/fragment/
     * path separator or end-of-string, so an over-long or decorated id is rejected.
     *
     * (The `#` inside the character classes is backslash-escaped: PHP's preg delimiter
     * scan is not character-class aware, so a bare `#` would close the pattern early.)
     *
     * LOCKSTEP with EMBED_RULES in the JS mirror — same rules, same order, same `out`.
     */
    private const EMBED_URL_RULES = [
        // YouTube — watch / shorts / live / v / embed / youtu.be, on www, m and music.
        ['re' => '#^(?:(?:https?:)?//)?(?:www\.|m\.|music\.)?youtube\.com/watch\?(?:[^\#]*&)?v=([A-Za-z0-9_-]{5,20})(?:[&\#].*)?$#i', 'out' => 'yt'],
        ['re' => '#^(?:(?:https?:)?//)?(?:www\.|m\.|music\.)?youtube\.com/shorts/([A-Za-z0-9_-]{5,20})(?:[/?\#].*)?$#i', 'out' => 'yt'],
        ['re' => '#^(?:(?:https?:)?//)?(?:www\.|m\.|music\.)?youtube\.com/live/([A-Za-z0-9_-]{5,20})(?:[/?\#].*)?$#i', 'out' => 'yt'],
        ['re' => '#^(?:(?:https?:)?//)?(?:www\.|m\.|music\.)?youtube\.com/v/([A-Za-z0-9_-]{5,20})(?:[/?\#].*)?$#i', 'out' => 'yt'],
        ['re' => '#^(?:(?:https?:)?//)?(?:www\.|m\.|music\.)?youtube(?:-nocookie)?\.com/embed/([A-Za-z0-9_-]{5,20})(?:[/?\#].*)?$#i', 'out' => 'yt'],
        ['re' => '#^(?:(?:https?:)?//)?(?:www\.)?youtu\.be/([A-Za-z0-9_-]{5,20})(?:[/?\#].*)?$#i', 'out' => 'yt'],

        // Vimeo — id is digits only; group 2 is the optional privacy hash of an
        // unlisted video (`vimeo.com/ID/HASH`). Without it an unlisted video 404s in
        // the player, so dropping it is a correctness bug, not a missing nicety.
        ['re' => '#^(?:(?:https?:)?//)?(?:www\.)?vimeo\.com/([0-9]{1,15})(?:/([A-Za-z0-9]{6,32}))?(?:[/?\#].*)?$#i', 'out' => 'vimeo'],
        ['re' => '#^(?:(?:https?:)?//)?player\.vimeo\.com/video/([0-9]{1,15})(?:[/?\#].*)?$#i', 'out' => 'vimeo'],
        ['re' => '#^(?:(?:https?:)?//)?(?:www\.)?vimeo\.com/channels/[A-Za-z0-9_-]{1,64}/([0-9]{1,15})(?:[/?\#].*)?$#i', 'out' => 'vimeo'],
        ['re' => '#^(?:(?:https?:)?//)?(?:www\.)?vimeo\.com/groups/[A-Za-z0-9_-]{1,64}/videos/([0-9]{1,15})(?:[/?\#].*)?$#i', 'out' => 'vimeo'],
        ['re' => '#^(?:(?:https?:)?//)?(?:www\.)?vimeo\.com/showcase/[0-9]{1,15}/video/([0-9]{1,15})(?:[/?\#].*)?$#i', 'out' => 'vimeo'],

        // Dailymotion — the id runs to the first `_` of the SEO slug (`x8abc12_my-film`).
        ['re' => '#^(?:(?:https?:)?//)?(?:www\.)?dailymotion\.com/video/([A-Za-z0-9]{5,20})(?:[_/?\#].*)?$#i', 'out' => 'dm'],
        ['re' => '#^(?:(?:https?:)?//)?(?:www\.)?dailymotion\.com/embed/video/([A-Za-z0-9]{5,20})(?:[_/?\#].*)?$#i', 'out' => 'dm'],
        ['re' => '#^(?:(?:https?:)?//)?dai\.ly/([A-Za-z0-9]{5,20})(?:[_/?\#].*)?$#i', 'out' => 'dm'],

        // Loom.
        ['re' => '#^(?:(?:https?:)?//)?(?:www\.)?loom\.com/share/([A-Za-z0-9]{16,64})(?:[/?\#].*)?$#i', 'out' => 'loom'],
        ['re' => '#^(?:(?:https?:)?//)?(?:www\.)?loom\.com/embed/([A-Za-z0-9]{16,64})(?:[/?\#].*)?$#i', 'out' => 'loom'],

        // Wistia — one bounded subdomain label only (the class holds no dot, so
        // `evil.com.wistia.com` and `evil-wistia.com` both fail).
        ['re' => '#^(?:(?:https?:)?//)?(?:[A-Za-z0-9-]{1,63}\.)?wistia\.com/medias/([A-Za-z0-9]{6,20})(?:[/?\#].*)?$#i', 'out' => 'wistia'],
        ['re' => '#^(?:(?:https?:)?//)?(?:[A-Za-z0-9-]{1,63}\.)?wistia\.net/(?:medias|embed/iframe)/([A-Za-z0-9]{6,20})(?:[/?\#].*)?$#i', 'out' => 'wistia'],
        ['re' => '#^(?:(?:https?:)?//)?wi\.st/medias/([A-Za-z0-9]{6,20})(?:[/?\#].*)?$#i', 'out' => 'wistia'],

        // Streamable — `streamable.com/ID` and the already-embed `streamable.com/e/ID`.
        ['re' => '#^(?:(?:https?:)?//)?(?:www\.)?streamable\.com/(?:e/)?([A-Za-z0-9]{3,12})(?:[/?\#].*)?$#i', 'out' => 'streamable'],

        // TikTok — the numeric video id, never the @handle.
        ['re' => '#^(?:(?:https?:)?//)?(?:www\.|m\.)?tiktok\.com/@[A-Za-z0-9._-]{1,30}/video/([0-9]{5,25})(?:[/?\#].*)?$#i', 'out' => 'tiktok'],
        ['re' => '#^(?:(?:https?:)?//)?(?:www\.)?tiktok\.com/embed/v2/([0-9]{5,25})(?:[/?\#].*)?$#i', 'out' => 'tiktok'],

        // Cloudflare Stream — group 1 is the customer subdomain, group 2 the video uid.
        ['re' => '#^(?:(?:https?:)?//)?customer-([A-Za-z0-9]{1,40})\.cloudflarestream\.com/([A-Za-z0-9]{8,64})/(?:watch|iframe)(?:[/?\#].*)?$#i', 'out' => 'cfstream'],
    ];

    /**
     * Normalize a pasted video URL to a canonical, privacy-preferring player src for
     * the `embed` block; returns '' for anything else (fail closed — the template's
     * `src` is then omitted entirely and the iframe ships with no source).
     *
     * Accepted (host case-insensitive, `www.` optional, scheme optional/http/https,
     * extra query params tolerated) — see EMBED_URL_RULES for the exact forms:
     *   youtube.com/{watch?…v=ID | shorts/ID | live/ID | v/ID | embed/ID} (www/m/music),
     *   youtube-nocookie.com/embed/ID, youtu.be/ID
     *                        -> https://www.youtube-nocookie.com/embed/ID[?start=S]
     *   vimeo.com/N[/HASH] | vimeo.com/{channels/x|groups/x/videos|showcase/n/video}/N
     *   | player.vimeo.com/video/N[?h=HASH]
     *                        -> https://player.vimeo.com/video/N[?h=HASH]
     *   dailymotion.com/video/ID | dailymotion.com/embed/video/ID | dai.ly/ID
     *                        -> https://www.dailymotion.com/embed/video/ID
     *   loom.com/{share|embed}/ID
     *                        -> https://www.loom.com/embed/ID
     *   *.wistia.com/medias/ID | *.wistia.net/{medias|embed/iframe}/ID | wi.st/medias/ID
     *                        -> https://fast.wistia.net/embed/iframe/ID
     *   streamable.com/[e/]ID
     *                        -> https://streamable.com/e/ID
     *   tiktok.com/@user/video/ID | tiktok.com/embed/v2/ID
     *                        -> https://www.tiktok.com/embed/v2/ID
     *   customer-SUB.cloudflarestream.com/UID/{watch|iframe}
     *                        -> https://customer-SUB.cloudflarestream.com/UID/iframe
     *
     * DELIBERATELY NOT SUPPORTED — their embed URL is not derivable from the pasted URL
     * without a network round-trip, and shipping a guess renders an error frame:
     *   - Twitch: the player REQUIRES a `parent=<embedding domain>` param. This method is
     *     static, request-free and mirrored in the browser, and one CMS can serve many
     *     domains, so no correct value exists here.
     *   - Rumble: the embed id is opaque (`/embed/v3abcd/`) and unrelated to the URL slug
     *     (`/v1a2b3c-title.html`); resolving it needs an API/page fetch.
     *   - vm.tiktok.com / other shortlinks: resolve only via a redirect.
     *
     * LOCKSTEP with the JS embedSrcFor() in block-runtime.blade.php (the canvas mirror)
     * — same rules in the same order, same normalization, same rejections; a divergence
     * means the editor preview and the published page disagree about what embeds.
     * Mirrors the cssValueValid() pairing rule. The result is re-checked here against
     * EMBED_SRC_PATTERN, which stays the final gate in renderNode(): this method can
     * narrow that allow-list, never widen it.
     */
    public static function embedSrcFor(string $url): string
    {
        // Browsers strip ASCII tab/newline and ignore other C0 controls while resolving a
        // URL (same reasoning as safeUrl()), so strip them before matching — otherwise
        // "you<TAB>tube.com/watch?v=ID" would be rejected here yet still load YouTube.
        $url = trim((string) preg_replace('/[\x00-\x1F\x7F]+/', '', trim($url)));
        if ($url === '') {
            return '';
        }

        $src = '';
        foreach (self::EMBED_URL_RULES as $rule) {
            if (preg_match($rule['re'], $url, $m) !== 1) {
                continue;
            }

            $start = self::embedStartSeconds($url);
            $hash = ($m[2] ?? '') !== '' ? $m[2] : self::vimeoQueryHash($url);

            $src = match ($rule['out']) {
                'yt' => 'https://www.youtube-nocookie.com/embed/' . $m[1]
                    . ($start > 0 ? '?start=' . $start : ''),
                'vimeo' => 'https://player.vimeo.com/video/' . $m[1]
                    . ($hash !== '' ? '?h=' . $hash : ''),
                'dm' => 'https://www.dailymotion.com/embed/video/' . $m[1],
                'loom' => 'https://www.loom.com/embed/' . $m[1],
                'wistia' => 'https://fast.wistia.net/embed/iframe/' . $m[1],
                'streamable' => 'https://streamable.com/e/' . $m[1],
                'tiktok' => 'https://www.tiktok.com/embed/v2/' . $m[1],
                // The customer subdomain is lower-cased: hosts are case-insensitive, but
                // EMBED_SRC_PATTERN pins this one to [a-z0-9] and would otherwise reject
                // an otherwise-valid paste that happened to shout.
                'cfstream' => 'https://customer-' . strtolower($m[1]) . '.cloudflarestream.com/' . $m[2] . '/iframe',
                default => '',
            };
            break;
        }

        return preg_match(self::EMBED_SRC_PATTERN, $src) === 1 ? $src : '';
    }

    /**
     * A pasted start offset (`?t=`/`&t=`/`#t=`/`start=`) in whole seconds, or 0 when the
     * URL carries none / carries something unparseable. YouTube writes it four ways —
     * `90`, `90s`, `1m30s`, `1h2m3s` — and dropping it silently restarts every "watch
     * from here" link at zero.
     *
     * The capture is deliberately loose and the VALIDATION strict: the value only ever
     * leaves here as an int, so nothing from the URL reaches the built src verbatim.
     */
    private static function embedStartSeconds(string $url): int
    {
        if (preg_match('#[?&\#](?:t|start)=([A-Za-z0-9]{1,16})#i', $url, $m) !== 1) {
            return 0;
        }

        $value = strtolower($m[1]);
        if (preg_match('/^[0-9]{1,6}$/', $value) === 1) {
            $seconds = (int) $value;
        } elseif (preg_match('/^(?:([0-9]{1,3})h)?(?:([0-9]{1,3})m)?(?:([0-9]{1,3})s)?$/', $value, $p) === 1
            && ($p[1] ?? '') . ($p[2] ?? '') . ($p[3] ?? '') !== '') {
            $seconds = ((int) ($p[1] ?? 0)) * 3600 + ((int) ($p[2] ?? 0)) * 60 + (int) ($p[3] ?? 0);
        } else {
            return 0;
        }

        return ($seconds > 0 && $seconds <= 86400) ? $seconds : 0;
    }

    /**
     * The Vimeo privacy hash carried in the query (`?h=…`) rather than the path —
     * the form `player.vimeo.com/video/ID?h=…` and `vimeo.com/ID?h=…` use. Bounded to
     * the same charset/length as the path form so a decorated value can't ride along.
     */
    private static function vimeoQueryHash(string $url): string
    {
        return preg_match('#[?&]h=([A-Za-z0-9]{6,32})(?:[&\#]|$)#i', $url, $m) === 1 ? $m[1] : '';
    }

    /**
     * The `<video>` counterpart of {@see embedSrcFor()}: a pasted link to a SELF-HOSTED
     * video FILE is not an iframe embed, it is a media element. Returns the URL
     * unchanged when it is a plain https `.mp4`/`.webm`/`.ogg`/`.ogv`/`.mov`, else ''
     * (fail closed — the template's `<video>` then ships with no `src`).
     *
     * Nothing is normalized: a media URL is opaque (signed CDN links carry required
     * query params), so this is a pure allow-list decision, gated by
     * EMBED_FILE_SRC_PATTERN — which renderNode() re-applies to every `<video src>` the
     * same way it re-applies EMBED_SRC_PATTERN to every `<iframe src>`.
     *
     * LOCKSTEP with the JS embedFileSrcFor() in block-runtime.blade.php.
     */
    public static function embedFileSrcFor(string $url): string
    {
        $url = trim((string) preg_replace('/[\x00-\x1F\x7F]+/', '', trim($url)));

        return $url !== '' && preg_match(self::EMBED_FILE_SRC_PATTERN, $url) === 1 ? $url : '';
    }
}
