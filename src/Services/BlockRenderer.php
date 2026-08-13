<?php

declare(strict_types=1);

namespace Heisenberg\Services;

/**
 * Compiles blocks to safe HTML by walking each block's contract `render.template`
 * (blueprint §4). ONE generic walk renders every contract — no per-block code.
 * Container blocks nest via an `inner-blocks` template node: the walk recurses each
 * child through its OWN contract (depth-capped), and every nested block is its own
 * security boundary — a container never sanitizes its children's content.
 *
 * Security model (§4.10): every value is escaped; src/href schemes are
 * allow-listed; CSS values are token-validated (colours confined to the design
 * palette); rich-text is tag-stripped to an inline allow-list and attribute-
 * scrubbed; editor-only nodes are dropped. The final heavyweight HTMLPurifier
 * pass (HtmlSanitizationService::purify) is the publish/render job's backstop over
 * the whole output — mirroring GTC; this renderer does not run it.
 *
 * SECURITY-CRITICAL. Verified against live GTC source (fidelity audit 2026-06-07):
 * the rich-text, colour, and size-token sanitizers match GTC's stricter patterns.
 * MediaResolver/srcset support lands in a later slice. Blocks are JSON-only
 * ({name, attributes, …}); there is no legacy {type, content} path.
 */
class BlockRenderer
{
    private const VOID_ELEMENTS = ['area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'param', 'source', 'track', 'wbr'];

    /**
     * Inline allow-list for `inline-basic` rich text. Extended 2026-07-18
     * for the cPGss block toolbar: u/s/code/mark formatting plus <span>
     * whose ONLY surviving attribute is a validated color/background-color
     * style (execCommand text color + highlight output). Every other
     * attribute is scrubbed by sanitizeRichText.
     */
    private const RICH_TEXT_ALLOWED = '<a><b><br><em><i><strong><u><s><code><mark><span>';

    private const RICH_TEXT_ALLOWED_NO_LINK = '<b><br><em><i><strong><u><s><code><mark><span>';

    /**
     * Hard cap on inner-block nesting depth — defends against pathologically deep
     * trees. Public: {@see \Heisenberg\Services\BlocksPayloadService} reuses this
     * exact limit when validating a save payload's `innerBlocks` tree, so the
     * save-time rejection and the render-time silent drop never drift apart.
     */
    public const MAX_NESTING_DEPTH = 20;

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
     * Safe tags an *interpolated* (user-data) tag may resolve to; anything else falls back
     * to div. Fail-closed: a tag not listed becomes div (never e.g. <script>/<iframe>/<button>).
     */
    private const DYNAMIC_TAG_ALLOWLIST = [
        'div', 'section', 'article', 'aside', 'main', 'header', 'footer', 'nav',
        'figure', 'figcaption', 'details', 'summary', 'blockquote', 'p', 'span',
        'ul', 'ol', 'li', 'dl', 'dt', 'dd', 'pre', 'code', 'hr',
        'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
    ];

    public function __construct(private BlockRegistryService $registry)
    {
    }

    /**
     * `$surface` selects WHICH top-level contract section supplies the template tree —
     * `'render'` (the default, web output, `render.template`) or `'email'`
     * (`email.template`, docs/email-system.md §4) — threaded through every recursive call
     * below unchanged from its default so the web rendering path is byte-for-byte what it
     * was before this parameter existed; {@see \Heisenberg\Services\EmailRenderer} is the
     * only caller that ever passes `'email'`. A block whose contract has no section under
     * `$surface` (or no `template` inside it) renders as EMPTY, not an error — this is how
     * a block silently drops out of a surface it never opted into (embed/icon have no
     * `email` section at all; §4).
     */
    public function renderBlocks(array $blocks, string $locale, string $surface = 'render'): string
    {
        $html = '';
        foreach ($blocks as $block) {
            if (is_array($block)) {
                $html .= $this->renderBlock($block, $locale, $surface);
            }
        }

        return $html;
    }

    public function renderBlock(array $block, string $locale, string $surface = 'render'): string
    {
        return $this->renderBlockAtDepth($block, $locale, 0, $surface);
    }

    private function renderBlockAtDepth(array $block, string $locale, int $depth, string $surface = 'render'): string
    {
        if ($depth > self::MAX_NESTING_DEPTH) {
            return '';
        }

        // JSON-only: a block must carry a string `name` resolving to a contract.
        return is_string($block['name'] ?? null)
            ? $this->renderJsonBlock($block, $locale, $depth, $surface)
            : '';
    }

    private function renderJsonBlock(array $block, string $locale, int $depth, string $surface = 'render'): string
    {
        $contract = $this->registry->getBlock((string) $block['name']);
        if ($contract === null) {
            return '';
        }

        $template = $contract[$surface]['template'] ?? null;
        if (! is_array($template)) {
            return '';
        }

        return $this->renderNode($template, $block, $contract, $locale, true, $depth, $surface);
    }

    private function renderNode(array $node, array $block, array $contract, string $locale, bool $isRoot = false, int $depth = 0, string $surface = 'render'): string
    {
        if ($this->isEditorOnlyNode($node)) {
            return '';
        }

        $type = $node['type'] ?? null;

        if ($type === 'text') {
            return $this->escape($this->substitute((string) ($node['content'] ?? ''), $block, $locale));
        }

        if ($type === 'rich-text') {
            $value = $this->localizedAttribute($block, (string) ($node['attribute'] ?? ''), $locale);
            $tier = (string) ($contract['security']['richText'] ?? 'inline-basic');
            $inner = $this->sanitizeRichText((string) $value, $tier);
            $class = isset($node['class']) ? $this->substitute((string) $node['class'], $block, $locale) : '';

            return $class !== '' ? '<span class="' . $this->escape($class) . '">' . $inner . '</span>' : $inner;
        }

        if ($type === 'inner-blocks') {
            return $this->renderInnerBlocks($block, $locale, $depth, $surface);
        }

        // text-lines: one element per non-empty line of a plain attribute — the generic
        // engine's list primitive. The GTC-era engine did this in a per-type PHP method
        // (renderJsonList splitting on \R+); expressing it as a template node keeps the
        // "no per-block PHP" design while giving a real <li> per item instead of one
        // catch-all cell. Escaped plain text only — no rich-text tier applies, and the
        // tag is static (never interpolated), constrained to the tag-name charset.
        if ($type === 'text-lines') {
            $value = (string) $this->localizedAttribute($block, (string) ($node['attribute'] ?? ''), $locale);
            $tag = strtolower((string) ($node['tag'] ?? 'li'));
            if (preg_match('/^[a-z][a-z0-9-]*$/', $tag) !== 1) {
                $tag = 'li';
            }
            $class = isset($node['class']) ? $this->substitute((string) $node['class'], $block, $locale) : '';
            $open = '<' . $tag . ($class !== '' ? ' class="' . $this->escape($class) . '"' : '') . '>';
            $html = '';
            foreach ((preg_split('/\R/', $value) ?: []) as $line) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }
                $html .= $open . $this->escape($line) . '</' . $tag . '>';
            }

            return $html;
        }

        // icon: inline one sanitized SVG from the block-icon library (the imported VvvebJs
        // collection — IconLibraryService), resolved from a plain attribute holding
        // "<set>/<slug>". Manifest-gated fail-closed: an unknown/empty reference renders
        // nothing at all. The files were sanitized at import time, so inlining them adds no
        // new markup surface; the wrapping span carries the reference for the canvas runtime.
        if ($type === 'icon') {
            $reference = trim((string) $this->localizedAttribute($block, (string) ($node['attribute'] ?? ''), $locale));
            $svg = $reference === '' ? null : app(IconLibraryService::class)->svg($reference);
            if ($svg === null) {
                return '';
            }
            $class = isset($node['class']) ? $this->substitute((string) $node['class'], $block, $locale) : '';

            return '<span' . ($class !== '' ? ' class="' . $this->escape($class) . '"' : '')
                . ' data-hb-icon="' . $this->escape($reference) . '">' . $svg . '</span>';
        }

        // Conditionally-unwrapped element: `{ "omitTagWhenAttributeEmpty": "href", "tag": "a", ... }`
        // renders children with NO wrapping element at all when that attribute resolves empty —
        // e.g. the email image template's `<a>` around an `<img>`: an anchor with no `href` is
        // dead markup (`<a><img></a>`), so an unlinked image gets no anchor rather than an empty one.
        $unwrapAttribute = $node['omitTagWhenAttributeEmpty'] ?? null;
        if (is_string($unwrapAttribute)
            && $unwrapAttribute !== ''
            && trim($this->scalarToString($this->localizedAttribute($block, $unwrapAttribute, $locale))) === '') {
            $children = '';
            foreach (($node['children'] ?? []) as $child) {
                if (is_array($child)) {
                    $children .= $this->renderNode($child, $block, $contract, $locale, false, $depth, $surface);
                }
            }

            return $children;
        }

        // element
        $tag = $this->resolveTag($node, $block, $contract, $locale);
        $attributes = $this->resolveAttributes($node, $block, $locale);

        if ($tag === 'iframe'
            && isset($attributes['src'])
            && (! is_string($attributes['src']) || preg_match(self::EMBED_SRC_PATTERN, $attributes['src']) !== 1)) {
            unset($attributes['src']); // fail closed: only allowlisted players embed
        }

        // Same guard, one element over: a <video> may only ever point at a plain https
        // media FILE. Enforced here (not just in embedFileSrcFor) so a future contract
        // that wires a <video src> through the ordinary value path inherits the gate.
        if ($tag === 'video'
            && isset($attributes['src'])
            && (! is_string($attributes['src']) || preg_match(self::EMBED_FILE_SRC_PATTERN, $attributes['src']) !== 1)) {
            unset($attributes['src']); // fail closed: no src at all beats a hostile one
        }

        // Reverse-tabnabbing guard (§4.10): any anchor whose resolved `target`
        // opens a new browsing context gets a forced-safe `rel`, regardless of
        // what the contract template did or didn't declare. Enforced here (not
        // per-contract) so it can never be forgotten by a future block that wires
        // up target="_blank" — one generic walk, one guard, applied uniformly.
        if ($tag === 'a' && ($attributes['target'] ?? null) === '_blank') {
            $attributes['rel'] = 'noopener noreferrer';
        }

        $class = $this->resolveClass($node, $block, $contract, $locale, $isRoot, $surface);
        if ($class !== '') {
            $attributes = ['class' => $class] + $attributes;
        }
        if ($isRoot) {
            $style = $this->blockStyleDeclarations($block, $contract);
            if ($style !== '') {
                $attributes['style'] = $style;
            }
        }

        $open = '<' . $tag . $this->buildAttributes($attributes) . '>';

        if (in_array($tag, self::VOID_ELEMENTS, true)) {
            return $open;
        }

        $children = '';
        foreach (($node['children'] ?? []) as $child) {
            if (is_array($child)) {
                $children .= $this->renderNode($child, $block, $contract, $locale, false, $depth, $surface);
            }
        }

        return $open . $children . '</' . $tag . '>';
    }

    /**
     * Expand an `inner-blocks` slot: render each child block instance through its
     * OWN contract (its own template + sanitizers), in document order. Each nested
     * block is its own security boundary — a container never sanitizes its children.
     */
    private function renderInnerBlocks(array $block, string $locale, int $depth, string $surface = 'render'): string
    {
        $html = '';
        foreach (($block['innerBlocks'] ?? []) as $child) {
            if (is_array($child)) {
                $html .= $this->renderBlockAtDepth($child, $locale, $depth + 1, $surface);
            }
        }

        return $html;
    }

    private function isEditorOnlyNode(array $node): bool
    {
        $class = $node['class'] ?? '';
        if (is_string($class) && str_contains($class, '__picker')) {
            return true;
        }

        $attributes = $node['attributes'] ?? [];

        return is_array($attributes) && array_key_exists('data-image-picker', $attributes);
    }

    private function resolveTag(array $node, array $block, array $contract, string $locale): string
    {
        $raw = (string) ($node['tag'] ?? 'div');
        $isDynamic = str_contains($raw, '{{');   // interpolated from (untrusted) block data
        $enumConstrained = preg_replace_callback(
            '/\{\{\s*attributes\.([a-zA-Z0-9_]+)\s*\}\}/',
            function (array $match) use ($block, $contract, $locale): string {
                $attribute = $match[1];
                $enum = $contract['attributes'][$attribute]['enum'] ?? null;
                if (! is_array($enum) || $enum === []) {
                    return '';
                }

                $value = $this->localizedAttribute($block, $attribute, $locale);
                if (! in_array($value, $enum, true)) {
                    $value = $enum[0];
                }

                return $this->scalarToString($value);
            },
            $raw
        ) ?? $raw;
        $tag = strtolower(trim($this->substitute($enumConstrained, $block, $locale)));

        if (preg_match('/^[a-z][a-z0-9-]*$/', $tag) !== 1) {
            return 'div';
        }

        // A static tag is author-controlled (trusted); a dynamic tag comes from user data
        // and is confined to a safe allow-list so it can never resolve to e.g. <script>.
        if ($isDynamic && ! in_array($tag, self::DYNAMIC_TAG_ALLOWLIST, true)) {
            return 'div';
        }

        return $tag;
    }

    /**
     * `$surface === 'email'` skips the contract-level className/classNames/align injection
     * entirely (docs/email-system.md §4 defect 5) — `hb-supports`, `hb-ease-*`, `hb-flex-layout`,
     * `hb-align-*` all name web-only CSS (interaction states, animation, flexbox) that has no
     * counterpart in an inbox. Email root classes are exactly what the `email.template` node
     * itself authors (e.g. `hb-email-col`, which the shell's own media query targets) — nothing
     * auto-appended. Web (`render`) keeps every existing rule unchanged.
     */
    private function resolveClass(array $node, array $block, array $contract, string $locale, bool $isRoot, string $surface = 'render'): string
    {
        $class = isset($node['class']) ? $this->substitute((string) $node['class'], $block, $locale) : '';

        if ($isRoot && $surface !== 'email') {
            $className = $contract['style']['className'] ?? '';
            if (is_string($className) && $className !== '') {
                $class = trim($class . ' ' . $className);
            }

            foreach (($contract['style']['classNames'] ?? []) as $binding) {
                if (! is_array($binding) || ! is_string($binding['class'] ?? null)) {
                    continue;
                }
                if (preg_match('/^[a-z][a-z0-9-]*$/', $binding['class']) !== 1) {
                    continue;
                }
                if ($this->predicateMatches($binding['when'] ?? null, $block, $contract, $locale)) {
                    $class = trim($class . ' ' . $binding['class']);
                }
            }

            $allowedAlignments = $contract['supports']['align'] ?? [];
            $alignment = $block['supports']['align'] ?? null;
            if (is_array($allowedAlignments)
                && is_string($alignment)
                && in_array($alignment, $allowedAlignments, true)
                // Matches the validator's ALIGN_VALUES allow-list exactly.
                && in_array($alignment, ['left', 'center', 'right', 'wide', 'full'], true)) {
                $class = trim($class . ' hb-align-' . $alignment);
            }
        }

        $words = array_values(array_unique(array_filter(explode(' ', $class), static fn ($w): bool => $w !== '')));

        return implode(' ', $words);
    }

    private function predicateMatches(mixed $predicate, array $block, array $contract, string $locale): bool
    {
        if (! is_array($predicate) || ! is_string($predicate['attribute'] ?? null)) {
            return false;
        }

        $attribute = $predicate['attribute'];
        $attributes = is_array($block['attributes'] ?? null) ? $block['attributes'] : [];
        if (array_key_exists($attribute . '_' . $locale, $attributes) || array_key_exists($attribute, $attributes)) {
            $value = $this->localizedAttribute($block, $attribute, $locale);
        } else {
            $value = $contract['attributes'][$attribute]['default'] ?? null;
        }

        if (array_key_exists('equals', $predicate)) {
            return $value === $predicate['equals'];
        }

        return isset($predicate['in']) && is_array($predicate['in'])
            && in_array($value, $predicate['in'], true);
    }

    /**
     * @return array<string, string|true> attribute name -> value, or `true` for a bare
     *   boolean/presence attribute
     */
    private function resolveAttributes(array $node, array $block, string $locale): array
    {
        $out = [];

        foreach (($node['attributes'] ?? []) as $name => $raw) {
            if (! is_string($name) || preg_match('/^[a-z_:][-a-z0-9_:.]*$/', $name) !== 1) {
                continue;
            }
            if ($name === 'data-image-picker') {
                continue;
            }

            // Boolean/presence attribute: { "boolean": "{{…}}" } — rendered bare when truthy.
            if (is_array($raw) && array_key_exists('boolean', $raw)) {
                if ($this->isTruthyAttribute($this->substitute((string) $raw['boolean'], $block, $locale))) {
                    $out[$name] = true;
                }
                continue;
            }

            // Embed attribute: { "embed": "{{attributes.url}}" } — the pasted video URL
            // normalized to an allow-listed player src, omitted when it isn't one.
            if (is_array($raw) && array_key_exists('embed', $raw)) {
                $embed = self::embedSrcFor($this->substitute((string) $raw['embed'], $block, $locale));
                if ($embed !== '') {
                    $out[$name] = $embed;
                }
                continue;
            }

            // Embed-file attribute: { "embedFile": "{{attributes.url}}" } — the SAME
            // pasted URL read as a self-hosted media file instead of a provider page.
            // Mutually exclusive with `embed` by construction: a provider URL is never a
            // .mp4 path and vice versa, so exactly one of the two elements gets a src.
            if (is_array($raw) && array_key_exists('embedFile', $raw)) {
                $file = self::embedFileSrcFor($this->substitute((string) $raw['embedFile'], $block, $locale));
                if ($file !== '') {
                    $out[$name] = $file;
                }
                continue;
            }

            // Enum-mapped attribute: { "enumMap": "{{attributes.level}}", "cases": {"1": "...", …},
            // "default": "..." } — the WHOLE value is chosen by matching another token's resolved
            // value against a static case table. Exists because the template DSL has no per-value
            // branching otherwise: email heading sizing needs a literal px figure per h1…h6 (email
            // clients can't be trusted with the canvas's `clamp()`/tag-selector cascade), and no
            // combination of `style.variables` + `var()` fallback can express "pick figure N by
            // this attribute's value" — only "pick THIS default vs. an instance override".
            if (is_array($raw) && array_key_exists('enumMap', $raw)) {
                $key = $this->substitute((string) $raw['enumMap'], $block, $locale);
                $cases = is_array($raw['cases'] ?? null) ? $raw['cases'] : [];
                $chosen = is_string($cases[$key] ?? null) ? $cases[$key] : (string) ($raw['default'] ?? '');
                $value = $this->substitute($chosen, $block, $locale);

                if (in_array($name, ['src', 'href', 'srcset', 'poster'], true)) {
                    $value = $this->safeUrl($value);
                    if ($value === '' && in_array($name, ['src', 'srcset'], true)) {
                        continue;
                    }
                }

                $out[$name] = $value;
                continue;
            }

            // Value attribute: a plain string, or an object that can omit an empty value.
            $omitEmpty = false;
            if (is_array($raw)) {
                $omitEmpty = ($raw['omitWhenEmpty'] ?? $raw['omitEmpty'] ?? false) === true;
                $raw = (string) ($raw['value'] ?? '');
            }

            $value = $this->substitute((string) $raw, $block, $locale);

            if (in_array($name, ['src', 'href', 'srcset', 'poster'], true)) {
                $value = $this->safeUrl($value);
                if ($value === '' && in_array($name, ['src', 'srcset'], true)) {
                    continue; // drop empty src/srcset
                }
            }

            if ($omitEmpty && $value === '') {
                continue; // omittable attribute with no value
            }

            $out[$name] = $value;
        }

        return $out;
    }

    /** HTML boolean-attribute truthiness from a resolved string value. */
    private function isTruthyAttribute(string $value): bool
    {
        return $value !== '' && $value !== 'false' && $value !== '0';
    }

    /**
     * @param array<string, string|true> $attributes
     */
    private function buildAttributes(array $attributes): string
    {
        $s = '';
        foreach ($attributes as $name => $value) {
            if ($value === true) {
                $s .= ' ' . $name;   // bare boolean/presence attribute
                continue;
            }
            $s .= ' ' . $name . '="' . $this->escape((string) $value) . '"';
        }

        return $s;
    }

    /** Resolve {{ id }}, {{ name }}, {{ attributes.X }} (locale-aware), {{ supports.X }}, {{ lang.X }}. */
    private function substitute(string $value, array $block, string $locale): string
    {
        return preg_replace_callback(
            '/\{\{\s*([a-zA-Z0-9_.]+)\s*\}\}/',
            function (array $m) use ($block, $locale): string {
                $token = $m[1];

                if ($token === 'id') {
                    return (string) ($block['id'] ?? '');
                }
                if ($token === 'name') {
                    return (string) ($block['name'] ?? '');
                }
                if (str_starts_with($token, 'attributes.')) {
                    return $this->scalarToString($this->localizedAttribute($block, substr($token, 11), $locale));
                }
                if (str_starts_with($token, 'supports.')) {
                    return $this->scalarToString($this->dataGet($block['supports'] ?? [], substr($token, 9)));
                }
                if (str_starts_with($token, 'lang.')) {
                    return $this->localizedString(substr($token, 5), $locale);
                }

                return '';
            },
            $value
        ) ?? $value;
    }

    /**
     * `{{ lang.blocks.embed.unsupported }}` -> the package translation
     * `heisenberg::blocks.embed.unsupported` for the render locale. Lets a contract ship
     * a user-facing STRING (an empty/unsupported state, a fallback caption) without
     * hard-coding English into the template the way an author-controlled `title=` does.
     *
     * Fail-quiet by design: an unknown key resolves to '' rather than leaking the raw
     * key into the page, and the key charset is bounded so a contract can only ever
     * reach a lang file, never an arbitrary container binding. The result is escaped by
     * the caller (text nodes) or by buildAttributes() (attribute values) like any other
     * substituted value. NOTE the canvas mirror's subst() has no translator and yields
     * '' here — a contract using this token must stay legible with an empty string
     * (embed.css does that with a `:empty::before` fallback label).
     */
    private function localizedString(string $key, string $locale): string
    {
        if (preg_match('/^[a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*)+$/i', $key) !== 1) {
            return '';
        }

        $full = 'heisenberg::' . $key;
        $translated = __($full, [], $locale);

        return is_string($translated) && $translated !== $full ? $translated : '';
    }

    /** Locale-aware attribute lookup: `key_<locale>` then bare `key`. */
    private function localizedAttribute(array $block, string $key, string $locale): mixed
    {
        $attributes = $block['attributes'] ?? [];
        if (! is_array($attributes)) {
            return '';
        }

        if (array_key_exists($key . '_' . $locale, $attributes)) {
            return $attributes[$key . '_' . $locale];
        }

        return $attributes[$key] ?? '';
    }

    /**
     * Lightweight inline sanitizer for rich-text nodes (§4.6), graded by the block's
     * `security.richText` tier (study R-CTL-RT):
     *   - `none` / `plain`       → escaped plain text (no inline formatting; e.g. code)
     *   - `inline-basic`         → b/i/em/strong/br + a scheme-safe `<a>` (default)
     *   - `inline-basic-no-link` → b/i/em/strong/br, no `<a>` (e.g. button text, headings)
     */
    private function sanitizeRichText(string $value, string $tier = 'inline-basic'): string
    {
        if ($tier === 'none' || $tier === 'plain') {
            return $this->escape($value);
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
                    if ($this->isSafeColorValue($rawValue)) {
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

    /**
     * Interaction states the model may style. Keys are the model paths under
     * `supports.states`; values are the CSS selector suffixes they compile to.
     * `.hb-state-preview` rides along on every state so the editor can force
     * a state's look while the user edits it.
     */
    public const INTERACTION_STATES = [
        'hover' => ':hover',
        'active' => ':active',
        'focus' => ':focus-within',
    ];

    /**
     * Compile the per-instance interaction-state CSS for a list of blocks
     * (recursing inner blocks). Each state override re-declares the SAME
     * contract style variables the base inline style uses, scoped to the
     * block instance + pseudo-class, every value run through the variable's
     * own sanitize kind. Emitted as one stylesheet (GTC's computedStyles
     * channel) — never inline in the sanitized HTML.
     */
    public function stateStylesCss(array $blocks, int $depth = 0): string
    {
        if ($depth > self::MAX_NESTING_DEPTH) {
            return '';
        }

        $css = [];
        foreach ($blocks as $block) {
            if (! is_array($block) || ! is_string($block['name'] ?? null)) {
                continue;
            }
            $contract = $this->registry->getBlock($block['name']);
            if ($contract === null) {
                continue;
            }

            $id = (string) ($block['id'] ?? '');
            $states = $block['supports']['states'] ?? null;
            if ($id !== '' && preg_match('/^[A-Za-z0-9_-]+$/', $id) === 1 && is_array($states)) {
                foreach (self::INTERACTION_STATES as $state => $pseudo) {
                    $declarations = $this->stateDeclarations($states[$state] ?? null, $contract);
                    if ($declarations === '') {
                        continue;
                    }
                    $scope = '[data-block-id="' . $id . '"]';
                    $css[] = $scope . $pseudo . ', ' . $scope . '.hb-state-preview-' . $state
                        . ' { ' . $declarations . ' }';
                }
            }

            $inner = $block['innerBlocks'] ?? null;
            if (is_array($inner) && $inner !== []) {
                $nested = $this->stateStylesCss($inner, $depth + 1);
                if ($nested !== '') {
                    $css[] = $nested;
                }
            }
        }

        return implode("\n", $css);
    }

    /** Declarations for one state's override map, keyed off the contract's style.variables. */
    private function stateDeclarations(mixed $overrides, array $contract): string
    {
        if (! is_array($overrides)) {
            return '';
        }

        $variables = $contract['style']['variables'] ?? [];
        if (! is_array($variables)) {
            return '';
        }

        $declarations = [];
        foreach ($variables as $name => $definition) {
            if (! is_array($definition)) {
                continue;
            }
            $source = (string) ($definition['source'] ?? '');
            if (! str_starts_with($source, 'supports.')) {
                continue; // states override style-bearing supports only
            }

            $value = $this->dataGet($overrides, substr($source, 9));
            if ($value === null || $value === '') {
                continue; // unset for this state — base value cascades
            }

            $safe = $this->sanitizeCssValue(
                $this->scalarToString($value),
                (string) ($definition['sanitize'] ?? 'text'),
                ''
            );
            if ($safe !== '') {
                // !important: the base variable value sits in the root's
                // INLINE style, which otherwise beats any stylesheet rule.
                $declarations[] = $name . ': ' . $safe . ' !important';
            }
        }

        return implode('; ', $declarations);
    }

    /** Materialize the contract's style.variables into a CSS declaration string (§4.5). */
    private function blockStyleDeclarations(array $block, array $contract): string
    {
        $variables = $contract['style']['variables'] ?? [];
        if (! is_array($variables)) {
            return '';
        }

        $declarations = [];
        foreach ($variables as $name => $definition) {
            if (! is_array($definition)) {
                continue;
            }

            $value = $this->resolveStyleSource($block, (string) ($definition['source'] ?? ''));
            $default = (string) ($definition['default'] ?? '');
            if ($value === null || $value === '') {
                $value = $default;
            }

            $safe = $this->sanitizeCssValue((string) $value, (string) ($definition['sanitize'] ?? 'text'), $default);
            if ($safe !== '') {
                $declarations[] = $name . ': ' . $safe;
            }
        }

        return $declarations === [] ? '' : implode('; ', $declarations) . ';';
    }

    private function resolveStyleSource(array $block, string $source): mixed
    {
        if (str_starts_with($source, 'supports.')) {
            return $this->dataGet($block['supports'] ?? [], substr($source, 9));
        }
        if (str_starts_with($source, 'attributes.')) {
            return $block['attributes'][substr($source, 11)] ?? null;
        }

        return null;
    }

    private function sanitizeCssValue(string $value, string $sanitizer, string $fallback): string
    {
        $value = $this->normalizeCssNumber(trim($value), $sanitizer);

        if ($this->cssValueValid($value, $sanitizer)) {
            return $value;
        }

        $fallback = $this->normalizeCssNumber(trim($fallback), $sanitizer);

        return $this->cssValueValid($fallback, $sanitizer) ? $fallback : '';
    }

    /**
     * A bare number carries no CSS unit and would fail its sanitizer; resolve the implied
     * unit (px for lengths, deg for angles, % for 0-100 opacity). Lockstep with the JS
     * normalizeCssNumber() in block-runtime.blade.php.
     */
    private function normalizeCssNumber(string $value, string $sanitizer): string
    {
        if (preg_match('/^-?\d+(\.\d+)?$/', $value) !== 1) {
            return $value;
        }

        return match ($sanitizer) {
            'size-value', 'length-signed' => $value . 'px',
            'angle' => $value . 'deg',
            'opacity' => (float) $value > 1 ? $value . '%' : $value,
            default => $value,
        };
    }

    private function cssValueValid(string $value, string $sanitizer): bool
    {
        if ($value === '') {
            return false;
        }

        return match ($sanitizer) {
            'color-token' => $this->isSafeColorValue($value),
            'color-token-or-transparent' => $value === 'transparent' || $this->isSafeColorValue($value),
            'border-style' => in_array($value, ['none', 'solid', 'dashed', 'dotted'], true),
            'font-token' => preg_match('/^var\(--[a-z0-9-]+\)$/i', $value) === 1,
            'size-value' => preg_match('/^(var\(--[a-z0-9-]+\)|-?\d+(\.\d+)?(px|rem|em|%|vw|vh))$/i', $value) === 1,
            'color-value' => $value === 'transparent' || preg_match('/^var\(--[a-z0-9-]+\)$/i', $value) === 1 || $this->isSafeColorValue($value),
            'font-family' => preg_match('/^(var\(--[a-z0-9-]+\)|[a-z0-9][a-z0-9 \-]{0,80})$/i', $value) === 1,
            'font-weight' => preg_match('/^(var\(--[a-z0-9-]+\)|[1-9]00)$/i', $value) === 1,
            'size-token' => preg_match('/^(0|auto|100%|var\(--[a-z0-9-]+(,\s*var\(--[a-z0-9-]+\))?\)|calc\([a-z0-9\s().,%*\/+-]+\)|-?\d+(\.\d+)?(px|rem|em|vw|%)?)$/i', $value) === 1,
            'integer' => preg_match('/^-?\d+$/', $value) === 1,
            // Supports-capability kinds. LOCKSTEP with BlockContractValidator::SANITIZERS and
            // the JS cssValueValid(); every kind gets its OWN case, never the permissive default.
            'opacity' => preg_match('/^(0|1|0?\.\d{1,3}|(100|[1-9]?\d)%)$/', $value) === 1,
            'angle' => preg_match('/^-?\d{1,3}(\.\d+)?deg$/i', $value) === 1,
            'length-signed' => $this->isSafeLengthSignedValue($value),
            'shadow' => $this->isSafeShadowValue($value),
            'text-align' => in_array($value, ['left', 'center', 'right', 'justify'], true),
            'align-3' => in_array($value, ['start', 'center', 'end'], true),
            'position-mode' => in_array($value, ['static', 'relative', 'absolute'], true),
            'flex-direction' => in_array($value, ['row', 'column', 'row-reverse', 'column-reverse'], true),
            'flex-justify' => in_array($value, ['start', 'center', 'end', 'space-between', 'space-around'], true),
            'flex-align' => in_array($value, ['start', 'center', 'end', 'stretch'], true),
            'flex-wrap' => in_array($value, ['wrap', 'nowrap', 'wrap-reverse'], true),
            'overflow' => in_array($value, ['visible', 'hidden', 'clip'], true),
            default => preg_match('#^[a-z0-9\s().,%_/-]+$#i', $value) === 1,
        };
    }

    /** `length-signed`: a signed length (letter-spacing, translate x/y, per-side border width) or the bare `0`. */
    private function isSafeLengthSignedValue(string $value): bool
    {
        return preg_match('/^(0|-?\d+(\.\d+)?(px|rem|em|%|vw|vh))$/i', $value) === 1;
    }

    /**
     * `shadow`: one or more comma-separated box-shadow layers. Per layer: an
     * optional `inset`, 2–4 signed lengths, and exactly one colour — each
     * length re-validated via {@see isSafeLengthSignedValue()} and the colour
     * via the existing {@see isSafeColorValue()}. Deliberately NOT one mega
     * regex (study doc §Phase-1 spec) so every component is validated on its
     * own terms; comma/paren-aware splitting keeps rgba()/hsla() layers intact.
     */
    private function isSafeShadowValue(string $value): bool
    {
        if ($value === 'none') {
            return true; // the real box-shadow keyword for "no shadow" — not a layer list.
        }

        $layers = $this->splitTopLevel($value, ',');
        if ($layers === []) {
            return false;
        }

        foreach ($layers as $layer) {
            if (! $this->isSafeShadowLayer($layer)) {
                return false;
            }
        }

        return true;
    }

    private function isSafeShadowLayer(string $layer): bool
    {
        $normalized = trim((string) preg_replace('/\s+/', ' ', trim($layer)));
        if ($normalized === '') {
            return false;
        }

        $tokens = array_values(array_filter(
            $this->splitTopLevel($normalized, ' '),
            static fn (string $t): bool => $t !== ''
        ));
        if ($tokens === []) {
            return false;
        }

        $insetCount = 0;
        $colorTokens = [];
        $lengthTokens = [];
        foreach ($tokens as $token) {
            if (strcasecmp($token, 'inset') === 0) {
                $insetCount++;
                continue;
            }
            if ($this->isSafeColorValue($token)) {
                $colorTokens[] = $token;
                continue;
            }
            $lengthTokens[] = $token;
        }

        if ($insetCount > 1 || count($colorTokens) !== 1) {
            return false;
        }
        if (count($lengthTokens) < 2 || count($lengthTokens) > 4) {
            return false;
        }

        foreach ($lengthTokens as $length) {
            if (! $this->isSafeLengthSignedValue($length)) {
                return false;
            }
        }

        return true;
    }

    /** Split on a delimiter char, but only at paren-depth 0 (keeps rgba(0, 0, 0, .2) etc. intact). */
    private function splitTopLevel(string $value, string $delimiter): array
    {
        $parts = [];
        $current = '';
        $depth = 0;

        for ($i = 0, $len = strlen($value); $i < $len; $i++) {
            $ch = $value[$i];
            if ($ch === '(') {
                $depth++;
            } elseif ($ch === ')') {
                $depth = max(0, $depth - 1);
            }

            if ($depth === 0 && $ch === $delimiter) {
                $parts[] = $current;
                $current = '';
                continue;
            }

            $current .= $ch;
        }
        $parts[] = $current;

        return array_map('trim', $parts);
    }

    private function isSafeColorValue(string $value): bool
    {
        return preg_match('/^var\(--(?:accent-[a-z0-9-]+|ink|faint|paper)\)$/', $value) === 1
            || preg_match('/^#[0-9a-fA-F]{3,8}$/', $value) === 1
            || preg_match('/^rgba?\(\s*(25[0-5]|2[0-4]\d|1?\d?\d)\s*,\s*(25[0-5]|2[0-4]\d|1?\d?\d)\s*,\s*(25[0-5]|2[0-4]\d|1?\d?\d)(\s*,\s*(0|1|0?\.\d+))?\s*\)$/i', $value) === 1
            || preg_match('/^hsla?\(\s*(360|3[0-5]\d|[12]?\d?\d)\s*,\s*(100|\d?\d)%\s*,\s*(100|\d?\d)%(\s*,\s*(0|1|0?\.\d+))?\s*\)$/i', $value) === 1;
    }

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

    /** Scheme allow-list for src/href (§4.8, §4.10). */
    private function safeUrl(string $url): string
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

    private function dataGet(mixed $data, string $path): mixed
    {
        foreach (explode('.', $path) as $segment) {
            if (is_array($data) && array_key_exists($segment, $data)) {
                $data = $data[$segment];
            } else {
                return null;
            }
        }

        return $data;
    }

    private function scalarToString(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return is_scalar($value) ? (string) $value : '';
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
