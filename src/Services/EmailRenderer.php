<?php

declare(strict_types=1);

namespace Heisenberg\Services;

use Heisenberg\Http\Controllers\EmailIconImageController;
use Heisenberg\Http\Controllers\EmailPreviewController;
use Heisenberg\Models\Post;
use Heisenberg\Models\PublicFile;
use Heisenberg\Rendering\HtmlEscaper;
use Heisenberg\Support\EmailRenderResult;
use Heisenberg\Support\LocaleConfig;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use TijsVerkoyen\CssToInlineStyles\CssToInlineStyles;

/**
 * Renders a `type = 'email'` post to a self-contained email (docs/email-system.md §5), beside
 * — never replacing — {@see BlockRenderer}'s web pipeline. `render()` walks the SAME block tree
 * a post always has, through the SAME substitution/sanitization engine, just reading each
 * block's `email.template` instead of `render.template` (`BlockRenderer::renderBlock($block,
 * $locale, 'email')` — the `$surface` parameter added for exactly this). A block whose contract
 * has no `email` section (embed; §4) renders empty — never fatal, silently absent from the
 * output, exactly like an unknown block name already does on the web surface.
 *
 * Pipeline per block, in order (§5):
 *  1. `BlockRenderer::renderBlock($block, $locale, 'email')` — table-based HTML for that one
 *     block. Every `var(--hb-<block>-…, fallback)` slot in its template is ALREADY literal when
 *     this returns: BlockStyleCompiler::resolveEmailVars() fills each block's slots from that
 *     block's own inspector values as the tree is walked, so nested blocks can never read one
 *     another's. No custom-property declaration is emitted on this surface at all.
 *  2. {@see self::rewriteImages()} — every `<img src>` that resolves to a {@see PublicFile}
 *     (media library upload), or to one of this install's GENERATED email-icon PNGs (§4.2 — an
 *     icon block's glyph, which has no media row by design), is replaced with `cid:$cid` and
 *     recorded in the embeds manifest; an `src` that does not resolve (an external URL) is left
 *     untouched — best effort, no host network fetch happens here.
 *  3. {@see self::resolveTokens()} — what is left is DESIGN tokens (`var(--ink)`,
 *     `var(--hb-t-brand)`), which arrive inside resolved values and are theme-wide rather than
 *     per block: each is replaced with the active theme's literal, so the invariant "no var(
 *     survives" holds.
 *
 * TOKEN MAPPING (design tokens, §5.2): built once per `render()` call from
 * {@see ThemeRepository}'s active theme (`load()`, which itself falls back to `defaults()` — so
 * this is always a complete 4-section theme). Every color/fontSize/space/radius token's bare
 * `name` (e.g. `ink`, `fs-md`) AND its `hb-t-`-prefixed form (the name a *custom* user token
 * picked via the Style panel is referenced by, {@see ThemeRepository::CSS_PREFIX}) both map to
 * the SAME literal `value` — a block's default (`var(--accent-1)`) and a user's own pick
 * (`var(--hb-t-brand)`) resolve through the identical path. `fw-*` font-WEIGHT tokens are not
 * tracked by ThemeRepository at all (they exist only as hardcoded chrome defaults today —
 * `preview.blade.php`'s base `:root` block) so they ride a small supplemental static map here
 * ({@see self::FONT_WEIGHT_TOKENS}); this is the one place email token resolution knows
 * something ThemeRepository doesn't, and it is called out here deliberately so it doesn't read
 * as an oversight.
 *
 * FONT-FAMILY MAPPING (§2): a font TOKEN (`font-sans`, `font-serif`, or a custom-named one)
 * becomes a STACK that leads with the author's real family and falls back to web-safe names:
 * `'Space Grotesk', Arial, Helvetica, sans-serif`. The message also links the theme's faces
 * ({@see self::webFontLink()}), so a client that loads webfonts (Apple Mail, iOS Mail) renders
 * what the author picked, while Gmail/Outlook ignore the link and land on the fallback.
 *
 * It used to resolve to the web-safe stack ALONE, so the chosen family never reached the message
 * at all and no client could render it. The stack was also picked by keyword-matching the
 * token's NAME before its family (`mono` -> Courier, `serif` -> Georgia, else Arial), which
 * inverted whenever the two disagreed: a token named `font-serif` holding Geist (a sans) shipped
 * Georgia, so switching to the second theme font turned the email into an unrelated serif. The
 * fallback now comes from {@see FontCatalogService::category()} — what the font IS — and the
 * keyword heuristic survives only for a family the catalog doesn't know (a system font).
 *
 * SHELL + INLINING (§5.3, §5.5): {@see self::wrapShell()} builds the canonical 100%-width
 * background table around a centered 600px content table, theme background/text colors already
 * literal (built from the same token map). `tijsverkoyen/css-to-inline-styles` (already on disk
 * via laravel/framework's mail dependency chain — verified with `composer show`, no new
 * composer dependency added) runs over the assembled document via
 * {@see self::inlineStyles()}: every block's own inline styles are already literal text by the
 * time it runs (this renderer's job, not the library's), so its ACTUAL job is narrow — inline
 * whatever a `<style>` tag in the shell declares (there is exactly one: a small `@media
 * (max-width: 600px)` block for the columns-block mobile stack, which the library's own
 * `doCleanup()` strips from what it INLINES but leaves untouched in the retained `<style>` tag
 * — precisely "the minimal head `<style>` for what inlining can't express" §2 calls for, since
 * a media query has no inline-style equivalent at all) and normalize the DOM round-trip. The
 * Outlook/iOS client-hack resets ({@see self::CLIENT_HACK_CSS}) are appended to that SAME
 * `<style>` tag only AFTER conversion returns — they are meaningless as inline declarations, and
 * the library would otherwise copy them onto every `<table>`/`<td>`/`<img>` in the document (they
 * were never candidates for inlining before this class existed to keep them out of its way).
 *
 * PLAIN TEXT (§5.6): {@see self::textFor()} walks the interpolated block tree directly (not the
 * rendered HTML) — attribute text, stripped of rich-text tags via `strip_tags()` + `html_entity_decode()`
 * — so it never depends on how a template happens to lay out markup. A button's URL is appended
 * in parentheses; a skipped block (no text-bearing mapping, e.g. embed/icon/separator) simply
 * contributes nothing.
 *
 * COLUMN CAP (§4 "capped at 2–3 columns"): {@see self::capColumns()} runs BEFORE rendering,
 * trimming any `columns` block's `innerBlocks` to at most {@see self::MAX_EMAIL_COLUMNS} column
 * children — the generic template-node vocabulary has no "first N children" primitive
 * (`inner-blocks` always expands the full list), so the cap is a tree transform here rather
 * than something a contract's `email.template` could express on its own.
 */
class EmailRenderer
{
    private const SURFACE = 'email';

    /** docs/email-system.md §4 — the email surface never shows more than this many columns. */
    private const MAX_EMAIL_COLUMNS = 3;

    /** Content width in pixels the shell + image variant selection both target (§2, §5.3). */
    private const CONTENT_WIDTH = 600;

    /**
     * `fw-*` font-WEIGHT design tokens: not tracked by {@see ThemeRepository} at all (see this
     * class's own docblock) — the same literal values `preview.blade.php`'s hardcoded base
     * `:root` block ships for the web surface, duplicated here deliberately rather than parsed
     * out of a Blade view.
     */
    private const FONT_WEIGHT_TOKENS = [
        'fw-regular' => '400',
        'fw-medium' => '500',
        'fw-semibold' => '600',
        'fw-bold' => '700',
    ];

    public function __construct(
        private BlockRenderer $renderer,
        private ThemeRepository $themes,
        private FontCatalogService $fonts,
    ) {
    }

    /**
     * `$preview` (docs/email-system.md §6, {@see EmailPreviewController})
     * is false for every real send/size measurement — the default, cid-embedded output the
     * Mailable attaches. Passed true ONLY for the editor's own browser-renderable preview tab: a
     * `cid:` reference has no meaning outside a MIME multipart message, so {@see self::rewriteImages()}
     * swaps in the image's real, publicly reachable URL instead and never populates the embeds
     * manifest (nothing there for a preview call to attach). The rest of the pipeline — token
     * resolution, shell, inlining, plain text, subject — is identical either way.
     *
     * The author writes `{{ variable_name }}` placeholders directly into the email's text. Those
     * tokens are NOT resolved here — Heisenberg renders what the author typed verbatim and the
     * host's own newsletter / mailing-list integration reads the rendered text and substitutes
     * values at send time. That keeps Heisenberg out of the subscriber / values / formatter
     * business: the package ships the editor and the email-rendering surface, and nothing else.
     */
    public function render(
        Post $email,
        string $locale,
        bool $preview = false,
    ): EmailRenderResult {
        $locale = LocaleConfig::isValid($locale) ? $locale : LocaleConfig::default();

        $blocks = $this->capColumns($email->blocks->map(fn ($block) => $block->content)->values()->all());

        $theme = $this->themes->load();
        $tokenMap = $this->themeTokenMap($theme);

        $embeds = [];
        $bodyHtml = '';
        foreach ($blocks as $block) {
            if (! is_array($block)) {
                continue;
            }

            $html = $this->renderer->renderBlock($block, $locale, self::SURFACE);
            if ($html === '') {
                continue; // no email section on this contract, or nothing rendered — skip, not fatal
            }

            $html = $this->rewriteImages($html, $embeds, $preview);
            $bodyHtml .= $this->resolveTokens($html, $tokenMap);
        }

        $subject = $email->title($locale);
        $shellHtml = $this->wrapShell($bodyHtml, $tokenMap, $subject);
        // One line ending, whatever the checkout uses. The shell is a heredoc in THIS file, so on
        // a Windows working tree (git's autocrlf) it carried CRLF and the same document rendered
        // differently — byte-for-byte — than on Linux, which made `sizeBytes` (the number the
        // Gmail clipping warning reads) ~3% larger there and any golden fixture platform-bound.
        $finalHtml = str_replace(["\r\n", "\r"], "\n", $this->inlineStyles($shellHtml));

        $embedBytes = 0;
        foreach ($embeds as $embed) {
            $embedBytes += (int) (@filesize($embed['path']) ?: 0);
        }

        return new EmailRenderResult(
            html: $finalHtml,
            text: trim($this->textFor($blocks)),
            subject: $subject,
            embeds: array_values(array_map(
                static fn (array $e): array => ['cid' => $e['cid'], 'path' => $e['path'], 'mime' => $e['mime']],
                $embeds
            )),
            sizeBytes: strlen($finalHtml) + $embedBytes,
        );
    }

    /**
     * Recursively cap every `columns` block's `innerBlocks` at {@see self::MAX_EMAIL_COLUMNS}
     * (§4). Prefix-aware (`config('heisenberg.block_prefix')`, default `heisenberg`) — the same
     * configurable namespace {@see BlockContractValidator} enforces on every contract `name`.
     *
     * @param list<array<string, mixed>> $blocks
     * @return list<array<string, mixed>>
     */
    private function capColumns(array $blocks): array
    {
        $columnsName = ((string) config('heisenberg.block_prefix', 'heisenberg')) . '/columns';

        $walk = function (array $blocks) use (&$walk, $columnsName): array {
            $out = [];
            foreach ($blocks as $block) {
                if (! is_array($block)) {
                    continue;
                }

                $inner = $block['innerBlocks'] ?? null;
                if (is_array($inner)) {
                    $inner = $walk($inner);
                    if (($block['name'] ?? null) === $columnsName) {
                        if (count($inner) > self::MAX_EMAIL_COLUMNS) {
                            $inner = array_slice($inner, 0, self::MAX_EMAIL_COLUMNS);
                        }
                        // Layout direction "column" stacks the cells one per row — each is then
                        // the full width, not a share of it.
                        $stacked = str_starts_with((string) ($block['supports']['layout']['direction'] ?? ''), 'column');
                        $inner = $this->assignColumnWidths($inner, $stacked);
                    }
                    $block['innerBlocks'] = $inner;
                }

                $out[] = $block;
            }

            return $out;
        };

        return $walk($blocks);
    }

    /**
     * Outlook needs an explicit per-cell width or the layout collapses unpredictably (§4/§9) —
     * the web surface leans on flexbox, which has no email equivalent. Whole-percent widths
     * summing to exactly 100 (the last column absorbs the rounding remainder), stashed as a
     * synthetic `_emailColWidthPercent` attribute {@see BlockRenderer}
     * substitutes into `column.json`'s `email.template` — this attribute exists ONLY for this
     * one render pass, never persisted, never part of the block's real schema.
     *
     * @param list<array<string, mixed>> $columns
     * @return list<array<string, mixed>>
     */
    private function assignColumnWidths(array $columns, bool $stacked = false): array
    {
        $count = count($columns);
        if ($count === 0) {
            return $columns;
        }

        $base = intdiv(100, $count);
        $last = 100 - $base * ($count - 1);

        foreach ($columns as $i => $column) {
            if (! is_array($column)) {
                continue;
            }
            $attributes = is_array($column['attributes'] ?? null) ? $column['attributes'] : [];
            $attributes['_emailColWidthPercent'] = ($stacked ? 100 : ($i === $count - 1 ? $last : $base)) . '%';
            $column['attributes'] = $attributes;
            $columns[$i] = $column;
        }

        return $columns;
    }

    /**
     * Rewrite every `<img src="...">` in $html to a `cid:` reference, appending a NEW embeds
     * entry when this exact (disk, path) pair hasn't been embedded yet by an earlier block in
     * this same render — a `cid` is reused (not re-attached) when the same image appears twice.
     * `src` values that don't resolve to a {@see PublicFile} (an external URL — the media
     * library was bypassed, or the file was since deleted) are left exactly as authored: no
     * network fetch happens here, and a broken/absent embed would be worse than a live URL.
     *
     * `$preview` (§7-E3): true swaps the `src` for the SAME variant's real, public URL
     * ({@see PublicFile::urlForPath()}) instead of a `cid:` reference, and
     * never touches `$embeds` — a browser preview tab has no MIME parts to attach, and a `cid:`
     * URL simply wouldn't load there.
     *
     * @param list<array{cid: string, path: string, mime: string, key: string}> $embeds
     */
    private function rewriteImages(string $html, array &$embeds, bool $preview = false): string
    {
        return (string) preg_replace_callback(
            '/(<img\b[^>]*\ssrc=")([^"]*)("[^>]*>)/i',
            function (array $m) use (&$embeds, $preview): string {
                $url = html_entity_decode($m[2], ENT_QUOTES);
                $file = PublicFile::forUrl($url);
                if ($file === null) {
                    // A GENERATED icon PNG (EmailIconImageController) — the icon block's glyph,
                    // which no mail client can render as SVG. These are artifacts, not uploads,
                    // so they deliberately have no media-library row for forUrl() to find; they
                    // still embed exactly like an uploaded image.
                    $generated = $this->generatedIconSource($url);
                    if ($generated === null) {
                        return $m[0];
                    }
                    [$disk, $path, $mime] = $generated;

                    return $m[1] . $this->embedRef($embeds, $disk, $path, $mime, $preview) . $m[3];
                }
                if (! $file->isImageType()) {
                    return $m[0];
                }

                [$path, $mime] = $this->embedSourceFor($file);

                return $m[1] . $this->embedRef($embeds, (string) $file->disk, $path, $mime, $preview) . $m[3];
            },
            $html
        );
    }

    /**
     * The `src` one image ends up with: a `cid:` reference (attaching the file once per render,
     * reusing the cid when the same file appears again) or, in preview, its public URL.
     *
     * @param list<array{cid: string, path: string, mime: string, key: string}> $embeds
     */
    private function embedRef(array &$embeds, string $disk, string $path, string $mime, bool $preview): string
    {
        if ($preview) {
            return PublicFile::urlForPath($disk, $path);
        }

        $key = $disk . '|' . $path;
        foreach ($embeds as $embed) {
            if ($embed['key'] === $key) {
                return 'cid:' . $embed['cid'];
            }
        }

        $cid = Str::random(24) . '@heisenberg';
        $embeds[] = [
            'cid' => $cid,
            'path' => Storage::disk($disk)->path($path),
            'mime' => $mime,
            'key' => $key,
        ];

        return 'cid:' . $cid;
    }

    /**
     * `[disk, path, mime]` when $url is one of this install's GENERATED email-icon PNGs, else
     * null. Fail-closed on every count: the media disk only, the one generated directory, the
     * exact name shape {@see EmailIconImageController} derives (so no traversal or arbitrary
     * path can be reached through an authored `src`), and the file must actually be there.
     *
     * @return array{0: string, 1: string, 2: string}|null
     */
    private function generatedIconSource(string $url): ?array
    {
        $path = PublicFile::storedPathFromUrl($url);
        if ($path === null) {
            return null;
        }

        $prefix = EmailIconImageController::DIRECTORY . '/';
        if (! str_starts_with($path, $prefix)) {
            return null;
        }

        $name = substr($path, strlen($prefix));
        if (preg_match('/^[a-z0-9-]+-[0-9a-f]{6}-\d{1,3}\.png$/', $name) !== 1) {
            return null;
        }

        $disk = (string) config('heisenberg.media.disk', 'uploads');

        return Storage::disk($disk)->exists($path) ? [$disk, $path, 'image/png'] : null;
    }

    /**
     * The embeddable variant of $file (§2): the WIDEST variant whose recorded width is
     * <= {@see self::CONTENT_WIDTH}, falling back to the original only when no variant
     * qualifies (none exist, or every one is wider than the content column).
     *
     * @return array{0: string, 1: string} [stored/variant path, mime type]
     */
    private function embedSourceFor(PublicFile $file): array
    {
        $bestPath = null;
        $bestWidth = -1;

        foreach ((array) ($file->variants ?? []) as $variant) {
            if (! is_array($variant)) {
                continue;
            }
            $width = (int) ($variant['width'] ?? 0);
            $path = (string) ($variant['path'] ?? '');
            if ($path === '' || $width <= 0 || $width > self::CONTENT_WIDTH) {
                continue;
            }
            if ($width > $bestWidth) {
                $bestWidth = $width;
                $bestPath = $path;
            }
        }

        $path = $bestPath ?? (string) $file->stored_path;

        return [$path, (string) ($file->mime_type ?: 'application/octet-stream')];
    }

    /**
     * Design-token name -> literal value, both bare (`ink`) and `hb-t-`-prefixed
     * (`hb-t-ink`) forms — see this class's docblock for why both, and the font-family/
     * font-weight special cases.
     *
     * @param array<string, mixed> $theme ThemeRepository::load()'s shape
     * @return array<string, string>
     */
    private function themeTokenMap(array $theme): array
    {
        $map = self::FONT_WEIGHT_TOKENS;

        foreach (['colors', 'fontSizes', 'spaces', 'radii'] as $section) {
            foreach ((array) ($theme[$section] ?? []) as $token) {
                if (! is_array($token)) {
                    continue;
                }
                $name = (string) ($token['name'] ?? '');
                $value = (string) ($token['value'] ?? '');
                if ($name === '' || $value === '') {
                    continue;
                }
                $map[$name] = $value;
                $map[ThemeRepository::CSS_PREFIX . $name] = $value;
            }
        }

        $base = null;
        foreach ((array) ($theme['fonts'] ?? []) as $token) {
            if (! is_array($token)) {
                continue;
            }
            $name = (string) ($token['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $stack = $this->emailFontStack($name, (string) ($token['family'] ?? ''));
            $map[$name] = $stack;
            $map[ThemeRepository::CSS_PREFIX . $name] = $stack;
            $base ??= $stack;
        }

        // The document's base face, by the same rule as ThemeRepository::css(): the theme's FIRST
        // font, whatever the author named it. Text that sets no font of its own reads this, so
        // the email matches the canvas under any theme rather than only one with a `font-sans`.
        $base ??= 'Arial, Helvetica, sans-serif';
        $map['font-base'] = $base;
        $map[ThemeRepository::CSS_PREFIX . 'font-base'] = $base;

        return $map;
    }

    /** See this class's docblock ("FONT-FAMILY MAPPING") for the classification rule. */
    private function emailFontStack(string $name, string $family): string
    {
        $family = trim($family);
        $fallback = $this->emailFallbackStack($name, $family);

        if ($family === '') {
            return $fallback;
        }

        // The author's ACTUAL font, first. A stack of nothing but web-safe names could never
        // render what they picked in ANY client; leading with the real family costs nothing
        // where it can't be loaded (the very same fallback follows it) and renders correctly in
        // the clients that can — Apple Mail and iOS Mail, which do load the linked face.
        $quoted = str_contains($family, ' ') ? "'" . $family . "'" : $family;

        return $quoted . ', ' . $fallback;
    }

    /**
     * A stylesheet link for the theme's own faces, so the family each stack now LEADS with can
     * actually be fetched. Apple Mail and iOS Mail honour it; Gmail and Outlook ignore or strip
     * it and land on the web-safe fallback that follows in every stack — which is exactly the
     * output this surface produced before, so nothing regresses where it cannot work.
     *
     * Empty string when the theme names no catalogued family: an email should not carry a link
     * that fetches nothing.
     */
    private function webFontLink(): string
    {
        $href = $this->fonts->css2Url($this->themes->fontFaces($this->themes->load()));
        if ($href === null) {
            return '';
        }

        return '<link href="' . htmlspecialchars($href, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '" rel="stylesheet" type="text/css">' . "\n";
    }

    /**
     * The web-safe part, chosen from the CATALOG's category for the family (what the font
     * actually is) and only falling back to keyword-matching the token's name when the family
     * isn't catalogued — a system font, or one this install doesn't know.
     */
    private function emailFallbackStack(string $name, string $family): string
    {
        $category = strtolower((string) $this->fonts->category($family));

        if ($category !== '') {
            return match (true) {
                str_contains($category, 'mono') => "'Courier New', Courier, monospace",
                str_contains($category, 'serif') && ! str_contains($category, 'sans') => "Georgia, 'Times New Roman', serif",
                // Display and Handwriting have no web-safe equivalent worth naming; a neutral
                // sans is the least wrong thing behind them.
                default => 'Arial, Helvetica, sans-serif',
            };
        }

        $needle = strtolower($name . ' ' . $family);

        if (str_contains($needle, 'mono')) {
            return "'Courier New', Courier, monospace";
        }
        if (str_contains($needle, 'serif')) {
            return "Georgia, 'Times New Roman', serif";
        }

        return 'Arial, Helvetica, sans-serif';
    }

    /**
     * Replace every `var(--name[, fallback])` in $html with a literal value, guaranteeing the
     * invariant "no `var(` survives" — the ONE non-negotiable output property (email clients,
     * Outlook foremost, do not support CSS custom properties at all; §1). Two-tier map, LOCAL
     * declarations (harvested from THIS fragment's own `--name: value;` custom-property
     * declarations — what {@see BlockRenderer}'s root-level
     * `style.variables` materialization always produces) winning over the global $tokenMap, so
     * an author's actual instance pick (e.g. a custom text color) resolves correctly even though
     * it is declared on the block's root and USED via `var()` on a non-root child within the
     * same fragment — string-scoped per block (not a real DOM cascade, but every fragment here
     * is exactly one block's own self-contained output, so the scope is correct by construction).
     *
     * Iterates up to 5 passes (design-token chains here are never more than one level deep: a
     * declaration's OWN value is at most one more `var(--design-token)`), then does one final
     * unconditional strip of anything still matching `var(...)` as an absolute last resort —
     * dead code on every path this renderer's own templates take, kept as a hard guarantee
     * rather than trusting the loop bound alone.
     *
     * @param array<string, string> $tokenMap
     */
    private function resolveTokens(string $html, array $tokenMap): string
    {
        $local = [];
        if (preg_match_all('/--([a-z0-9-]+)\s*:\s*([^;"]+);?/i', $html, $matches, PREG_SET_ORDER) > 0) {
            foreach ($matches as $decl) {
                $local[strtolower($decl[1])] = trim($decl[2]);
            }
        }

        $combined = $tokenMap;
        foreach ($local as $name => $value) {
            $combined[$name] = $value;
        }

        $resolveOnce = static function (string $text) use (&$combined): string {
            return (string) preg_replace_callback(
                '/var\(\s*--([a-z0-9-]+)\s*(?:,\s*([^)]*))?\)/i',
                static function (array $m) use ($combined): string {
                    $name = strtolower($m[1]);
                    if (isset($combined[$name]) && $combined[$name] !== '') {
                        return $combined[$name];
                    }

                    return isset($m[2]) ? trim($m[2], " '\"") : '';
                },
                $text
            );
        };

        // Pre-resolve any declaration value that is itself `var(--design-token)` before it is
        // used as a lookup target below (one level of chaining — see docblock).
        foreach ($combined as $name => $value) {
            if (str_contains($value, 'var(')) {
                $combined[$name] = $resolveOnce($value);
            }
        }

        for ($i = 0; $i < 5 && str_contains($html, 'var('); $i++) {
            $html = $resolveOnce($html);
        }

        $html = (string) preg_replace('/var\([^)]*\)/i', '', $html);

        return $this->stripCustomPropertyDeclarations($html);
    }

    /**
     * Remove every `--name: value;` CUSTOM-PROPERTY DECLARATION left sitting in a `style="…"`
     * attribute once the loop above has used it as a lookup source (§2 "no `var(` survives" —
     * the declarations themselves are the other half of that invariant: a `--hb-heading-color:
     * #0a0a0a;` decoration does nothing in mail, it is dead weight only `var()` USAGES elsewhere
     * ever read). Scoped to `style="…"` attribute VALUES specifically (not a blind whole-document
     * regex) so authored rich-text content that happens to contain literal `--` text is never
     * touched. A `style=""` left empty by this is dropped entirely.
     */
    private function stripCustomPropertyDeclarations(string $html): string
    {
        return (string) preg_replace_callback(
            '/\sstyle="([^"]*)"/i',
            static function (array $m): string {
                // Split the DECODED value: an escaped quote is `&#039;`, and its own `;` is not
                // a declaration boundary — splitting the raw attribute tore a quoted family
                // apart and rejoined it as `' Times New Roman'`, a name no client matches.
                $css = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $declarations = array_filter(
                    array_map('trim', explode(';', $css)),
                    static fn (string $d): bool => $d !== '' && ! str_starts_with($d, '--')
                );

                return $declarations === [] ? '' : ' style="' . HtmlEscaper::escape(implode('; ', $declarations)) . '"';
            },
            $html
        );
    }

    /**
     * The Outlook/iOS "client hack" resets (§2, §5.5, defect 8): meaningless as inline
     * declarations (`-webkit-text-size-adjust` on a `<td>` does nothing), they exist ONLY for
     * clients that read `<style>` at all. {@see self::inlineStyles()} injects this into the head
     * `<style>` AFTER `CssToInlineStyles::convert()` has already run, specifically so these rules
     * are never themselves candidates for inlining — `wrapShell()`'s own `<style>` carries only
     * the mobile `@media` block, the one thing inlining genuinely cannot express, so the library
     * has no non-media rule left to copy onto every `<table>`/`<td>`/`<img>` in the document.
     */
    private const CLIENT_HACK_CSS = <<<'CSS'
  body,table,td { -webkit-text-size-adjust:100%; -ms-text-size-adjust:100%; }
  table,td { mso-table-lspace:0pt; mso-table-rspace:0pt; }
  img { border:0; line-height:100%; outline:none; text-decoration:none; }
CSS;

    /**
     * The canonical shell (§5.3): a 100%-width background table (theme background literal)
     * around a centered {@see self::CONTENT_WIDTH}px content table (theme paper/ink literals),
     * plus the one `<style>` block inlining cannot replace — a mobile `@media` stack for the
     * columns block. `$subject` becomes the `<title>` (harmless for a message that is never
     * rendered as a standalone page, but keeps the document well-formed for any tool that opens
     * the raw HTML part directly).
     *
     * @param array<string, string> $tokenMap
     */
    private function wrapShell(string $bodyHtml, array $tokenMap, string $subject): string
    {
        $bg = $tokenMap['paper'] ?? '#f4f4f4';
        $ink = $tokenMap['ink'] ?? '#0a0a0a';
        $font = $tokenMap['font-base'] ?? 'Arial, Helvetica, sans-serif';
        $width = self::CONTENT_WIDTH;

        $title = htmlspecialchars($subject, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $fontLink = $this->webFontLink();

        return <<<HTML
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="x-apple-disable-message-reformatting">
<title>{$title}</title>
{$fontLink}<style>
  @media only screen and (max-width: {$width}px) {
    .hb-email-col { display:block !important; width:100% !important; }
  }
</style>
</head>
<body style="margin:0; padding:0; background-color:{$bg};">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:{$bg};">
<tr><td align="center" style="padding:24px 12px;">
<table role="presentation" width="{$width}" cellpadding="0" cellspacing="0" border="0" style="width:{$width}px; max-width:{$width}px; background-color:#ffffff; font-family:{$font}; color:{$ink};">
<tr><td align="left" style="text-align:left;">
{$bodyHtml}
</td></tr>
</table>
</td></tr>
</table>
</body>
</html>
HTML;
    }

    /**
     * Inline whatever the shell's own `<style>` declares (see this class's docblock — narrow
     * by design, every block's OWN style is already literal inline text by this point).
     * `CssToInlineStyles::convert()` round-trips through a real DOMDocument, which both
     * normalizes the markup and is why $html must already be a full document (it always is —
     * this is only ever called on {@see self::wrapShell()}'s output). The client-hack rules are
     * added to the retained `<style>` tag AFTER conversion — see {@see self::CLIENT_HACK_CSS}.
     *
     * PLACEHOLDER PASSTHROUGH (the host substitutes `{{ variable_name }}` tokens at send time,
     * outside Heisenberg): when the rendered HTML contains any `{{ ... }}` token we SKIP the
     * DOMDocument round-trip entirely. PHP's DOMDocument normalizes href/src values through
     * `rawurlencode` semantics, which would silently turn `{{ unsubscribe_url }}` into
     * `{{%20unsubscribe_url%20}}` — the host's substitution step would never find the token.
     * Skipping the round-trip is safe because the only `<style>` rule in the email shell is the
     * columns-block mobile `@media` query, which CssToInlineStyles doesn't actually INLINE
     * (its own `doCleanup()` strips media-query rules from what it inlines, leaving them in the
     * retained `<style>`). We append the client-hack CSS to that same `<style>` tag by regex,
     * identical to what the library would do after `convert()`.
     */
    private function inlineStyles(string $html): string
    {
        if (str_contains($html, '{{')) {
            return (string) preg_replace(
                '/<\/style>/i',
                self::CLIENT_HACK_CSS . "\n</style>",
                $html,
                1
            );
        }

        $inlined = (new CssToInlineStyles())->convert($html);

        return (string) preg_replace(
            '/<\/style>/i',
            self::CLIENT_HACK_CSS . "\n</style>",
            $inlined,
            1
        );
    }

    /**
     * Plain-text alternative (§5.6), walked from the interpolated block tree — never rendered HTML.
     * Recognizes the 10 email-safe block slugs by their bare name (after the configured
     * `block_prefix/`); anything else (an authored-but-excluded embed/icon, or an unknown
     * block) contributes nothing, same "skip, don't fail" posture as the HTML pipeline.
     *
     * @param list<array<string, mixed>> $blocks
     */
    private function textFor(array $blocks): string
    {
        $prefix = ((string) config('heisenberg.block_prefix', 'heisenberg')) . '/';
        $lines = [];

        foreach ($blocks as $block) {
            if (! is_array($block) || ! is_string($block['name'] ?? null)) {
                continue;
            }

            $slug = str_starts_with($block['name'], $prefix) ? substr($block['name'], strlen($prefix)) : $block['name'];
            $attributes = is_array($block['attributes'] ?? null) ? $block['attributes'] : [];
            $inner = is_array($block['innerBlocks'] ?? null) ? $block['innerBlocks'] : [];

            switch ($slug) {
                case 'heading':
                case 'paragraph':
                    $text = $this->plainText((string) ($attributes['content'] ?? ''));
                    if ($text !== '') {
                        $lines[] = $text;
                    }
                    break;

                case 'quote':
                    $text = $this->plainText((string) ($attributes['content'] ?? ''));
                    $citation = $this->plainText((string) ($attributes['citation'] ?? ''));
                    if ($text !== '') {
                        $lines[] = '"' . $text . '"' . ($citation !== '' ? ' — ' . $citation : '');
                    }
                    break;

                case 'list':
                    $content = (string) ($attributes['content'] ?? '');
                    $items = array_values(array_filter(array_map(
                        fn (string $line): string => $this->plainText($line),
                        preg_split('/\R/', $content) ?: []
                    ), static fn (string $l): bool => $l !== ''));
                    foreach ($items as $item) {
                        $lines[] = '- ' . $item;
                    }
                    break;

                case 'button':
                    $label = $this->plainText((string) ($attributes['text'] ?? ''));
                    $url = trim((string) ($attributes['url'] ?? ''));
                    if ($label !== '') {
                        $lines[] = $label . ($url !== '' ? ' (' . $url . ')' : '');
                    }
                    break;

                case 'image':
                    $caption = $this->plainText((string) ($attributes['caption'] ?? ''));
                    $alt = trim((string) ($attributes['alt'] ?? ''));
                    $label = $caption !== '' ? $caption : $alt;
                    if ($label !== '') {
                        $lines[] = '[Image: ' . $label . ']';
                    }
                    break;

                case 'separator':
                    $lines[] = '----------';
                    break;

                case 'group':
                case 'columns':
                case 'column':
                    // Structural only — no text of their own; recurse into innerBlocks below.
                    break;

                default:
                    // Unknown or excluded (embed/icon): contributes nothing, never fatal.
                    break;
            }

            if ($inner !== []) {
                $nested = $this->textFor($inner);
                if ($nested !== '') {
                    $lines[] = $nested;
                }
            }
        }

        return implode("\n\n", $lines);
    }

    /** Rich-text/plain attribute -> plain text: tags stripped, entities decoded, trimmed. */
    private function plainText(string $value): string
    {
        return trim(html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }
}
