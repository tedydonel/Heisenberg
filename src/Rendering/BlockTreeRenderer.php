<?php

declare(strict_types=1);

namespace Heisenberg\Rendering;

use Heisenberg\Services\BlockRegistryService;
use Heisenberg\Services\BlockRenderer;
use Heisenberg\Services\BlocksPayloadService;
use Heisenberg\Services\EmailRenderer;
use Heisenberg\Services\IconLibraryService;

/**
 * Compiles blocks to safe HTML by walking each block's contract `render.template`
 * (blueprint §4). ONE generic walk renders every contract — no per-block code.
 * Container blocks nest via an `inner-blocks` template node: the walk recurses each
 * child through its OWN contract (depth-capped), and every nested block is its own
 * security boundary — a container never sanitizes its children's content. Extracted
 * verbatim from {@see BlockRenderer}.
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
 *
 * The collaborators below are constructed once (by {@see BlockRenderer}'s
 * singleton) and reused for every block on every render — no per-node container
 * lookups, no repeated disk reads (the icon library's manifest is read at most
 * once per request, not once per icon block).
 */
final class BlockTreeRenderer
{
    private const VOID_ELEMENTS = ['area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'param', 'source', 'track', 'wbr'];

    /**
     * Hard cap on inner-block nesting depth — defends against pathologically deep
     * trees. Public: {@see BlocksPayloadService} reuses this
     * exact limit (via {@see BlockRenderer::MAX_NESTING_DEPTH})
     * when validating a save payload's `innerBlocks` tree, so the save-time
     * rejection and the render-time silent drop never drift apart.
     */
    public const MAX_NESTING_DEPTH = 20;

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

    public function __construct(
        private BlockRegistryService $registry,
        private BlockStyleCompiler $styleCompiler,
        private TemplateInterpolator $interpolator = new TemplateInterpolator(),
        private RichTextSanitizer $richText = new RichTextSanitizer(),
        private IconLibraryService $icons = new IconLibraryService(),
    ) {
    }

    /**
     * `$surface` selects WHICH top-level contract section supplies the template tree —
     * `'render'` (the default, web output, `render.template`) or `'email'`
     * (`email.template`, docs/email-system.md §4) — threaded through every recursive call
     * below unchanged from its default so the web rendering path is byte-for-byte what it
     * was before this parameter existed; {@see EmailRenderer} is the
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
            return HtmlEscaper::escape($this->interpolator->substitute((string) ($node['content'] ?? ''), $block, $locale));
        }

        if ($type === 'rich-text') {
            $value = $this->interpolator->localizedAttribute($block, (string) ($node['attribute'] ?? ''), $locale);
            $tier = (string) ($contract['security']['richText'] ?? 'inline-basic');
            $inner = $this->richText->sanitize((string) $value, $tier);
            $class = isset($node['class']) ? $this->interpolator->substitute((string) $node['class'], $block, $locale) : '';

            return $class !== '' ? '<span class="' . HtmlEscaper::escape($class) . '">' . $inner . '</span>' : $inner;
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
            $value = (string) $this->interpolator->localizedAttribute($block, (string) ($node['attribute'] ?? ''), $locale);
            $tag = strtolower((string) ($node['tag'] ?? 'li'));
            if (preg_match('/^[a-z][a-z0-9-]*$/', $tag) !== 1) {
                $tag = 'li';
            }
            $class = isset($node['class']) ? $this->interpolator->substitute((string) $node['class'], $block, $locale) : '';
            $open = '<' . $tag . ($class !== '' ? ' class="' . HtmlEscaper::escape($class) . '"' : '') . '>';
            $html = '';
            foreach ((preg_split('/\R/', $value) ?: []) as $line) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }
                $html .= $open . HtmlEscaper::escape($line) . '</' . $tag . '>';
            }

            return $html;
        }

        // icon: inline one sanitized SVG from the block-icon library (the imported VvvebJs
        // collection — IconLibraryService), resolved from a plain attribute holding
        // "<set>/<slug>". Manifest-gated fail-closed: an unknown/empty reference renders
        // nothing at all. The files were sanitized at import time, so inlining them adds no
        // new markup surface; the wrapping span carries the reference for the canvas runtime.
        if ($type === 'icon') {
            $reference = trim((string) $this->interpolator->localizedAttribute($block, (string) ($node['attribute'] ?? ''), $locale));
            $svg = $reference === '' ? null : $this->icons->svg($reference);
            if ($svg === null) {
                return '';
            }
            $class = isset($node['class']) ? $this->interpolator->substitute((string) $node['class'], $block, $locale) : '';

            return '<span' . ($class !== '' ? ' class="' . HtmlEscaper::escape($class) . '"' : '')
                . ' data-hb-icon="' . HtmlEscaper::escape($reference) . '">' . $svg . '</span>';
        }

        // Conditionally-unwrapped element: `{ "omitTagWhenAttributeEmpty": "href", "tag": "a", ... }`
        // renders children with NO wrapping element at all when that attribute resolves empty —
        // e.g. the email image template's `<a>` around an `<img>`: an anchor with no `href` is
        // dead markup (`<a><img></a>`), so an unlinked image gets no anchor rather than an empty one.
        $unwrapAttribute = $node['omitTagWhenAttributeEmpty'] ?? null;
        if (is_string($unwrapAttribute)
            && $unwrapAttribute !== ''
            && trim($this->interpolator->scalarToString($this->interpolator->localizedAttribute($block, $unwrapAttribute, $locale))) === '') {
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
            && (! is_string($attributes['src']) || preg_match(EmbedUrlResolver::EMBED_SRC_PATTERN, $attributes['src']) !== 1)) {
            unset($attributes['src']); // fail closed: only allowlisted players embed
        }

        // Same guard, one element over: a <video> may only ever point at a plain https
        // media FILE. Enforced here (not just in embedFileSrcFor) so a future contract
        // that wires a <video src> through the ordinary value path inherits the gate.
        if ($tag === 'video'
            && isset($attributes['src'])
            && (! is_string($attributes['src']) || preg_match(EmbedUrlResolver::EMBED_FILE_SRC_PATTERN, $attributes['src']) !== 1)) {
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
            $style = $this->styleCompiler->blockStyleDeclarations($block, $contract, $surface);
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

                $value = $this->interpolator->localizedAttribute($block, $attribute, $locale);
                if (! in_array($value, $enum, true)) {
                    $value = $enum[0];
                }

                return $this->interpolator->scalarToString($value);
            },
            $raw
        ) ?? $raw;
        $tag = strtolower(trim($this->interpolator->substitute($enumConstrained, $block, $locale)));

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
        $class = isset($node['class']) ? $this->interpolator->substitute((string) $node['class'], $block, $locale) : '';

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
            $value = $this->interpolator->localizedAttribute($block, $attribute, $locale);
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
     *                                    boolean/presence attribute
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
                if ($this->isTruthyAttribute($this->interpolator->substitute((string) $raw['boolean'], $block, $locale))) {
                    $out[$name] = true;
                }

                continue;
            }

            // Embed attribute: { "embed": "{{attributes.url}}" } — the pasted video URL
            // normalized to an allow-listed player src, omitted when it isn't one.
            if (is_array($raw) && array_key_exists('embed', $raw)) {
                $embed = EmbedUrlResolver::embedSrcFor($this->interpolator->substitute((string) $raw['embed'], $block, $locale));
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
                $file = EmbedUrlResolver::embedFileSrcFor($this->interpolator->substitute((string) $raw['embedFile'], $block, $locale));
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
                $key = $this->interpolator->substitute((string) $raw['enumMap'], $block, $locale);
                $cases = is_array($raw['cases'] ?? null) ? $raw['cases'] : [];
                $chosen = is_string($cases[$key] ?? null) ? $cases[$key] : (string) ($raw['default'] ?? '');
                $value = $this->interpolator->substitute($chosen, $block, $locale);

                if (in_array($name, ['src', 'href', 'srcset', 'poster'], true)) {
                    $value = SafeUrlResolver::safeUrl($value);
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

            $value = $this->interpolator->substitute((string) $raw, $block, $locale);

            if (in_array($name, ['src', 'href', 'srcset', 'poster'], true)) {
                $value = SafeUrlResolver::safeUrl($value);
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
            $s .= ' ' . $name . '="' . HtmlEscaper::escape((string) $value) . '"';
        }

        return $s;
    }
}
