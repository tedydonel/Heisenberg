<?php

declare(strict_types=1);

namespace Heisenberg\Ai;

use Heisenberg\Services\BlockRegistryService;
use Heisenberg\Services\ShortcodeDialect;
use Heisenberg\Services\ThemeRepository;

/**
 * Builds the system prompt that teaches a model to author Heisenberg documents.
 *
 * The output format is the **shortcode dialect** (docs/code-view.md), not prose
 * and not HTML — that dialect is the editor's machine-authoring surface, and
 * routing AI output through it means an AI-written block is validated against
 * exactly the same registry the canvas uses, reports line-numbered errors, and
 * lands through the same undo stack as a hand-drawn one. Nothing else has to be
 * built for AI insertion to be safe.
 *
 * The block-contract and design-token sections are generated from the live
 * registry/theme rather than hardcoded, so a contract or token added tomorrow
 * is taught tomorrow and one removed stops being suggested — and so the model
 * never has to spend a tool round discovering what this file already told it.
 * See docs/ai-phase0-findings.md §4 for why that round-tripping was the #1
 * product failure this rewrite exists to fix.
 */
class EditorPrompt
{
    /**
     * Attribute names present on (essentially) every block contract — id,
     * tooltip, custom classes, per-breakpoint hide toggles, and the
     * inspector-hidden fill/hug/clip flags the canvas edge-resize writes.
     * Computed from the registry itself (the intersection of every block's
     * attribute keys) rather than hand-listed, so the exclusion can't drift
     * from the contracts it's meant to describe. Documented once, globally,
     * instead of repeated on all twelve block lines.
     */
    private const TYPE_ABBR = [
        'boolean' => 'bool', 'integer' => 'int', 'number' => 'num',
        'string' => 'str', 'rich-text' => 'rich', 'url' => 'url',
        'media' => 'media', 'array' => 'array', 'object' => 'object', 'token' => 'token',
    ];

    public function __construct(
        private BlockRegistryService $registry,
        private ThemeRepository $theme,
    ) {
    }

    /** Everything the model needs to own the page from message 1. */
    public function system(): string
    {
        $identity = $this->identity();
        $dialect = $this->dialect();
        $blocks = $this->blockContracts();
        $tokens = $this->designTokens();
        $discipline = $this->toolDiscipline();
        $locales = $this->locales();
        $seo = $this->seo();

        return <<<PROMPT
        {$identity}

        {$dialect}

        BLOCK CONTRACTS — every block this editor knows, generated from the live registry.
        This list is complete; you should not need describe_block for normal authoring.
        {$blocks}

        DESIGN TOKENS — this site's theme, as CSS custom properties.
        {$tokens}
        Prefer a token variable (var(--hb-t-…)) over a literal value for color, spacing,
        radius and font attributes, so content follows the site theme instead of fighting it.
        A literal is fine when nothing in the token list fits.

        {$discipline}

        {$locales}

        {$seo}

        Rules:
        - Body text may contain inline HTML (<strong>, <em>, <a href="...">). Block-level HTML may not.
        - Only set attributes the user actually asked for (or that the request clearly implies). Omit everything else — contract defaults apply.
        - Shortcode goes ONLY in write_canvas's `code` argument — bare block tags, no code fences, no preamble. Never paste it into your chat reply.
        - When the user asks a question about their document rather than requesting content, answer in plain prose and skip write_canvas.
        - Never write <think> or any other reasoning tag into your reply.
        PROMPT;
    }

    /**
     * The user turn: their instruction, plus whatever the editor selection gives
     * us as context.
     *
     * @param array<string, mixed> $context
     */
    public function user(string $prompt, array $context = []): string
    {
        $parts = [$prompt];

        // The whole document, as the shortcode the model is asked to produce.
        // Sending it unprompted is the difference between an assistant that can
        // edit the page and one that replies "paste your current shortcode".
        $document = trim((string) ($context['document'] ?? ''));
        if ($document !== '') {
            $parts[] = "The document currently on the page:\n\n{$document}"
                . "\n\n(This is what write_canvas edits: mode=\"append\" adds after it, "
                . 'mode="replace" swaps it for your code — pass the full updated document '
                . 'to rework the page.)';
        } else {
            $parts[] = 'The page is currently empty. Call write_canvas with shortcode and it '
                . 'becomes the page content, live in front of the user.';
        }

        // The editor's editing locale (docs/content-translation.md §0/Wave 2), sent by the
        // panel every turn (block-runtime.blade.php's getEditingLocale()/getHomeLocale()).
        // When it differs from the post's own home locale, write_canvas is TRANSLATING —
        // restate the LOCALES-section rule against the CONCRETE locales this turn, since a
        // named pair ("fr" vs "en") is much harder to miss than the generic rule alone.
        $editingLocale = trim((string) ($context['editingLocale'] ?? ''));
        $homeLocale = trim((string) ($context['homeLocale'] ?? ''));
        if ($editingLocale !== '' && $homeLocale !== '' && $editingLocale !== $homeLocale) {
            $parts[] = "You are editing the '{$editingLocale}' locale; the post's home locale is "
                . "'{$homeLocale}'. This turn is a TRANSLATION: any write_canvas call must reproduce the "
                . 'SAME block sequence as the document above with only human-readable text changed — '
                . 'never add, remove, or reorder blocks, and never change ids/urls/media refs. Use '
                . 'mode="replace" only; mode="append" is refused while editing a non-home locale (tell '
                . 'the user to switch back to the home locale to add new blocks).';
        }

        $selection = trim((string) ($context['selection'] ?? ''));
        if ($selection !== '') {
            $blockName = trim((string) ($context['blockName'] ?? ''));
            $label = $blockName !== '' ? "the selected {$blockName} block" : 'the selected block';
            $parts[] = "The user has {$label} selected:\n\n{$selection}";
        }

        $title = trim((string) ($context['title'] ?? ''));
        if ($title !== '') {
            $parts[] = "The post title is: {$title}";
        }

        return implode("\n\n", $parts);
    }

    /** §1 — what this is, where it lives, what it can do here. */
    private function identity(): string
    {
        return <<<TXT
        You are the writing assistant built into Heisenberg, a block-based page/post builder.
        You live inside the editor's AI panel, alongside the visual canvas the user is looking
        at right now.

        HOW YOU BUILD THE PAGE — read this first.
        You have direct write access to the editor: the write_canvas tool. Its `code` argument
        is Heisenberg shortcode (dialect below); the blocks appear on the user's canvas the
        moment the call runs. mode="append" (the default) adds them after what is already on
        the page; mode="replace" swaps the whole document for your code — use replace when
        editing or restructuring existing content, passing the FULL updated document.
        write_canvas is the ONLY route content takes to the page. Shortcode or article text in
        your chat reply or reasoning never reaches the editor — don't write page content there;
        chat text is for answering questions and a one-line note of what you did.
        So: a request to make, write, add, edit, or design anything on this page → call
        write_canvas with the shortcode, in this same turn.

        BUILD INCREMENTALLY — think a little, build, think a little, build.
        Your FIRST write_canvas call comes after only a few sentences of planning: the
        opening section is enough to start, and the user watches it land while you think
        about the next one. Then keep going, one append call per section, fixing an earlier
        section with a replace call if needed. NEVER compose the whole page in your head
        before the first call — reasoning and output share one token budget, so a long
        silent think burns the budget and the build never happens: that is a failed turn.
        Draft the actual content INSIDE the write_canvas calls, not in your reasoning.

        Beyond that you can:
        - Set the page's title with set_page_title (it fills the editor's title field live).
        - Manage the post's taxonomy (categories, tags) through tools.
        - Read saved posts and media, and translate a saved post's fields into another locale
          (create_translation, see LOCALES).
        The exact tool argument shapes arrive via the tool-calling channel, not here.
        TXT;
    }

    /** §2 — the shortcode dialect cheat-sheet, upgraded from docs/code-view.md. */
    private function dialect(): string
    {
        return <<<TXT
        SHORTCODE DIALECT

        Grammar:
          [tag attr=value long-attr="value with spaces"]body or children[/tag]
          [tag /]                                       self-closing (no body, no children)

        Tags are the contract slug (heading, paragraph, list, ...) or an HTML-familiar alias:
        `p` = paragraph, `h1`..`h6` = heading (the level rides the tag, so [h3] means
        heading + level=3); a real slug wins over an alias.

        Plain attributes are contract attributes (anchor, url, variant, ...). Types coerce
        per the contract: booleans from true/1, numbers via normal parsing, object/media/array
        attributes take a JSON string. An enum violation is an error. `anchor` is the block's
        HTML id — set it so a link or the post's table of contents can jump straight to that
        block; must match /^[A-Za-z][\w-]*$/.

        Style attributes are CSS-familiar short names over the block's `supports` paths — the
        full dotted path (e.g. typography.fontSize) is always accepted too, as an escape hatch
        for anything without a short alias:
          color=color.text            bg=color.background
          font=typography.fontFamily  weight=typography.fontWeight
          font-size / line-height / letter-spacing = typography.*
          text-align / text-valign = typography.textAlign / textAlignVertical
          w / h / min-w / min-h / max-w / max-h = size.*      clip = size.clip
          padding / margin / radius = box shorthands (see below); padding-top .. margin-left,
            radius-tl .. radius-bl = the same paths per side/corner
          border-width / border-color / border-style = border.*
          border-top / border-right / border-bottom / border-left = border.width per side
          gap / direction / wrap / justify / align-items = layout.*
          position / x / y / rotate = position.mode / .x / .y / .rotation
          opacity = appearance.opacity     shadow = effects.shadow
        Box shorthands use CSS value semantics (TRBL sides, like CSS): padding=12px or "4px 8px"
        (V H) or "1px 2px 3px 4px" (TRBL); same for margin and radius.
        State prefixes target supports.states: hover:color=#123456, active:..., focus:...
        Not every block supports every style group (e.g. a separator has no typography) — an
        attribute the block doesn't support is a parse error naming the block and the attribute.

        Values are unquoted when simple (40px, #fff, var(--hb-t-c-1), space-between); anything
        with spaces, slashes, or quotes takes "..." with \\" / \\\\ escapes.

        Container semantics — a block is exactly one of:
          - Rich-text body: its ONE `rich-text` attribute (shown as "body: <attr>" below) is
            the tag's body text, e.g. [p]Hello <em>world</em>[/p]. Inline HTML is allowed there.
          - Nested blocks: `innerBlocks.enabled` is true, so the body is child block tags
            instead of text, e.g. [group][p]...[/p][/group].
          - Neither: no body/children — use attributes only, self-closing ([separator /]) or
            content set via a plain string attribute (e.g. list's `content`, one item per line).
        Putting text where a block expects nested blocks (or vice versa) is a parse error.

        Only set non-default values — omitted attributes/styles fall back to the contract
        default, so a short tag is normal.

        Warning: a literal "[word]" in prose is scanned as a tag. If body text would look like
        [this], escape it by breaking the brackets — an unknown "tag" errors, not renders as text.
        TXT;
    }

    /** §3 — compact per-block contracts, generated from BlockRegistryService::discover(). */
    private function blockContracts(): string
    {
        $blocks = $this->registry->registry()['blocks'] ?? [];
        if ($blocks === []) {
            return '(no blocks registered)';
        }

        $common = $this->commonAttributeNames($blocks);
        $lines = [];
        foreach ($blocks as $contract) {
            $lines[] = $this->blockLine($contract, $common);
        }
        sort($lines);

        // Common attributes carry their FULL tokens (type, enum, default), not
        // just names — "animate" listed bare is how a model ends up guessing
        // `animate=true` and burning a tool round discovering it's an enum.
        $commonTokens = [];
        foreach ($common as $name) {
            $def = null;
            foreach ($blocks as $contract) {
                $candidate = $contract['attributes'][$name] ?? null;
                if (is_array($candidate)) {
                    $def = $candidate;
                    break;
                }
            }
            $commonTokens[] = is_array($def) ? $this->attrToken($name, $def, false) : $name;
        }
        $commonList = implode(', ', $commonTokens);

        return "Every block also accepts these common attributes (omitted from the per-block lines): {$commonList}.\n\n"
            . "Each block's `styles:` list below names the ONLY style attributes it accepts — an attribute outside it is a parse error.\n\n"
            . implode("\n", $lines);
    }

    /** @param list<array<string, mixed>> $blocks @return list<string> attribute names shared by every block */
    private function commonAttributeNames(array $blocks): array
    {
        $sets = [];
        foreach ($blocks as $contract) {
            $attrs = $contract['attributes'] ?? null;
            $sets[] = is_array($attrs) ? array_keys($attrs) : [];
        }
        if ($sets === []) {
            return [];
        }

        $common = array_shift($sets);
        foreach ($sets as $set) {
            $common = array_intersect($common, $set);
        }
        sort($common);

        return array_values($common);
    }

    /** @param array<string, mixed> $contract @param list<string> $common */
    private function blockLine(array $contract, array $common): string
    {
        $name = (string) ($contract['name'] ?? '');
        $slug = ShortcodeDialect::slugOf($name);
        $desc = trim((string) ($contract['description'] ?? ''));
        $aliases = $this->aliasesFor($slug);

        $attributes = is_array($contract['attributes'] ?? null) ? $contract['attributes'] : [];
        $richAttr = null;
        foreach ($attributes as $key => $def) {
            if (is_array($def) && ($def['type'] ?? null) === 'rich-text') {
                $richAttr = (string) $key;
                break;
            }
        }

        $innerBlocks = is_array($contract['innerBlocks'] ?? null) ? $contract['innerBlocks'] : [];
        $acceptsChildren = ($innerBlocks['enabled'] ?? false) === true;

        if ($acceptsChildren) {
            $allowed = $innerBlocks['allowedBlocks'] ?? '*';
            $bodyNote = is_array($allowed)
                ? 'children: ' . implode('/', array_map([ShortcodeDialect::class, 'slugOf'], $allowed))
                : 'children: any block';
            $parent = $innerBlocks['parent'] ?? null;
            if (is_array($parent) && $parent !== []) {
                $bodyNote .= ' (only valid inside ' . implode('/', array_map([ShortcodeDialect::class, 'slugOf'], $parent)) . ')';
            }
        } elseif ($richAttr !== null) {
            $bodyNote = "body: {$richAttr}";
        } else {
            $bodyNote = 'no body/children — attributes only';
        }

        $attrParts = [];
        foreach ($attributes as $key => $def) {
            if (! is_array($def) || in_array($key, $common, true)) {
                continue;
            }
            $attrParts[] = $this->attrToken((string) $key, $def, $key === $richAttr);
        }

        $head = $slug . ($aliases !== '' ? " ({$aliases})" : '');
        $desc = rtrim($desc, '.');
        $line = "- {$head} — " . ($desc !== '' ? $desc . '. ' : '') . $bodyNote . '.';
        if ($attrParts !== []) {
            $line .= ' attrs: ' . implode(', ', $attrParts) . '.';
        }

        $styles = $this->stylesToken(is_array($contract['supports'] ?? null) ? $contract['supports'] : []);
        if ($styles !== '') {
            $line .= ' styles: ' . $styles;
        }

        return $line;
    }

    /**
     * The style attributes a block's `supports` actually allows, written with
     * the dialect's own short names so the model can go straight from this line
     * to a legal attribute. This is what stops "lineHeight on a paragraph" or
     * "border on a heading" — before this line, a model only found out which
     * styles a block accepts by hitting the parse error.
     *
     * @param array<string, mixed> $supports
     */
    private function stylesToken(array $supports): string
    {
        $parts = [];

        $color = is_array($supports['color'] ?? null) ? $supports['color'] : [];
        if (! empty($color['text'])) {
            $parts[] = 'color';
        }
        if (! empty($color['background'])) {
            $parts[] = 'bg';
        }

        $typo = is_array($supports['typography'] ?? null) ? $supports['typography'] : [];
        $typoMap = [
            'fontFamily' => 'font', 'fontWeight' => 'weight', 'fontSize' => 'font-size',
            'lineHeight' => 'line-height', 'letterSpacing' => 'letter-spacing',
            'textAlign' => 'text-align', 'textAlignVertical' => 'text-valign',
        ];
        $typoShorts = [];
        foreach ($typoMap as $key => $short) {
            if (! empty($typo[$key])) {
                $typoShorts[] = $short;
            }
        }
        if ($typoShorts !== []) {
            $parts[] = implode('/', $typoShorts);
        }

        if (! empty($supports['size'])) {
            $clip = ! empty($supports['size']['clip']);
            $parts[] = 'w/h/min-*/max-*' . ($clip ? '/clip' : '');
        }

        $spacing = is_array($supports['spacing'] ?? null) ? $supports['spacing'] : [];
        $layout = is_array($supports['layout'] ?? null) ? $supports['layout'] : [];
        if (! empty($spacing['padding']) || ! empty($layout['padding'])) {
            $parts[] = 'padding';
        }
        if (! empty($spacing['margin'])) {
            $parts[] = 'margin';
        }

        if (! empty($supports['border'])) {
            $parts[] = 'border' . (! empty($supports['border']['radius']) ? '/radius' : '');
        }

        $layoutMap = ['direction' => 'direction', 'wrap' => 'wrap', 'justify' => 'justify', 'align' => 'align-items', 'gap' => 'gap'];
        $layoutShorts = [];
        foreach ($layoutMap as $key => $short) {
            if (! empty($layout[$key])) {
                $layoutShorts[] = $short;
            }
        }
        if ($layoutShorts !== []) {
            $parts[] = implode('/', $layoutShorts);
        }

        if (! empty($supports['position'])) {
            $parts[] = 'position/x/y/rotate';
        }
        if (! empty($supports['appearance']['opacity'])) {
            $parts[] = 'opacity';
        }
        if (! empty($supports['effects']) && array_key_exists('shadow', (array) $supports['effects'])) {
            $parts[] = 'shadow';
        }
        if (! empty($supports['states'])) {
            $parts[] = 'hover:/active:/focus:';
        }

        return implode(', ', $parts);
    }

    /** @param array<string, mixed> $def */
    private function attrToken(string $name, array $def, bool $isBody): string
    {
        $type = self::TYPE_ABBR[$def['type'] ?? ''] ?? (string) ($def['type'] ?? 'str');
        $token = "{$name}:{$type}";

        $enum = $def['enum'] ?? null;
        if (is_array($enum) && $enum !== []) {
            $token .= '(' . implode('|', array_map(static fn ($v) => (string) $v, $enum)) . ')';
        }

        if (array_key_exists('default', $def)) {
            $token .= '=' . $this->scalarize($def['default']);
        }

        return $isBody ? $token . '[body]' : $token;
    }

    private function scalarize(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if ($value === null) {
            return 'null';
        }
        if (is_string($value)) {
            // Always quoted, matching the dialect's own quoting rule for values with
            // spaces — "Click here" reads unambiguously where `Click here` doesn't.
            return '"' . $value . '"';
        }

        return (string) $value;
    }

    /** `p` for paragraph, `h1`..`h6` for heading — read straight off the dialect's own table. */
    private function aliasesFor(string $slug): string
    {
        $aliases = [];
        foreach (ShortcodeDialect::TAG_SHORT as $short => $spec) {
            if ($spec['slug'] === $slug) {
                $aliases[] = $short;
            }
        }

        return implode('/', $aliases);
    }

    /** §4 — design tokens, generated from ThemeRepository (falls back to its documented defaults). */
    private function designTokens(): string
    {
        $theme = $this->theme->load();
        $prefix = ThemeRepository::CSS_PREFIX;
        $lines = [];

        $section = static function (string $label, array $tokens, callable $describe) use (&$lines, $prefix): void {
            if ($tokens === []) {
                return;
            }
            $parts = [];
            foreach ($tokens as $token) {
                $name = (string) ($token['name'] ?? '');
                if ($name === '') {
                    continue;
                }
                $parts[] = "var(--{$prefix}{$name})={$describe($token)}";
            }
            if ($parts !== []) {
                $lines[] = "{$label}: " . implode(', ', $parts);
            }
        };

        $section('Colors', $theme['colors'] ?? [], static fn ($t) => ($t['label'] ?? $t['name']) . '(' . $t['value'] . ')');
        $section('Font sizes', $theme['fontSizes'] ?? [], static fn ($t) => ($t['label'] ?? $t['name']) . '(' . $t['value'] . ')');
        $section('Spacing', $theme['spaces'] ?? [], static fn ($t) => ($t['label'] ?? $t['name']) . '(' . $t['value'] . ')');
        $section('Radii', $theme['radii'] ?? [], static fn ($t) => ($t['label'] ?? $t['name']) . '(' . $t['value'] . ')');
        $section('Fonts', $theme['fonts'] ?? [], static fn ($t) => ($t['label'] ?? $t['name']) . '(' . ($t['family'] ?? '') . ')');

        return $lines === [] ? '(no theme tokens defined)' : implode("\n", $lines);
    }

    /** §5 — tool discipline: replaces the old "use tools instead of asking" closer. */
    private function toolDiscipline(): string
    {
        return <<<TXT
        TOOL DISCIPLINE
        - The document arrives below on every turn, already read. Never ask the user to paste
          it or tell you what's on the page.
        - Building the current page IS a tool action: write_canvas (see HOW YOU BUILD above).
          Never merely announce a build — make the call in the same turn, then close with a
          one-line note of what you did.
        - The block contracts above are complete: every attribute with its type, enum and
          default, and every style attribute each block accepts. Do NOT call describe_block to
          double-check them — spend those tokens building.
        - ICONS ARE THE EXCEPTION to that. An icon block's `icon` is a "<set>/<slug>" reference
          into a library of tens of thousands of icons, listed nowhere above. Call search_icons
          and use a reference it returned verbatim; one you composed yourself renders nothing.
        - Never call render_preview for the current page: the canvas IS the preview, live in
          front of the user.
        - Do not spend rounds on discovery. For an authoring request call write_canvas
          immediately; batch any other calls you genuinely need into as few rounds as possible.
        - Tool errors are descriptive (line-numbered). Fix from the message alone and resubmit —
          never retry the same call unchanged.
        TXT;
    }

    /** §6 — the single-row translation model, create_translation, and the live editing locale
     *  (docs/content-translation.md §0). */
    private function locales(): string
    {
        return <<<TXT
        LOCALES — one post, several languages on the SAME row
        Structure exists once; only words differ: a locale's text is a suffixed attribute variant
        (`content` -> `content_fr`), never a separate post. get_post's `translations`: locale ->
        {is_default, title, excerpt, blocks_translated, blocks_total, complete}.
        create_translation(post_id, target_locale, title?, excerpt?, code?) folds `code` (same
        block sequence, text only) into the post by position; no new post/slug/status change.
        EDITING LOCALE (context): differs from home_locale => write_canvas is TRANSLATING too —
        same sequence, text only, no add/remove/reorder/id/url/media change, mode="replace" only,
        positions must match exactly.
        TXT;
    }

    /** §7 — SEO/social metadata + score, and media metadata (docs/seo-system.md §6). */
    private function seo(): string
    {
        return <<<TXT
        SEO — get_seo reads a post's meta/social row (both locales); update_seo writes it.
        `locale` routes meta_title/meta_description/og_title/og_description/focus_keyphrase to
        that locale's column (default: the post's own); og_image/canonical_url/robots/
        schema_type/schema_data/in_sitemap are locale-neutral. Good meta: title 30-60 chars,
        description 50-160, set focus_keyphrase and use it in title/slug/description/intro.
        Workflow: analyze_seo -> fix the worst fail/warn checks -> analyze_seo again.
        MEDIA — update_media sets alt_text_en/_fr + caption_en/_fr (REAL French, never a copy)
        and credit. set_featured_image sets/clears a post's featured image.
        TXT;
    }
}
