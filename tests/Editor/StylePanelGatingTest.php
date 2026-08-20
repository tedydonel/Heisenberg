<?php

declare(strict_types=1);

namespace Heisenberg\Tests\Editor;

use Heisenberg\Services\BlockRegistryService;
use Heisenberg\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * live/block/style-panel.blade.php gating.
 *
 * The panel accepted a `$supports` prop and never read it, so every block type rendered all ten
 * Style sections regardless of what its contract declared. Sections with no matching supports
 * group write into the model and render nothing — indistinguishable from a working control until
 * you reload the page.
 *
 * Counting occurrences across the whole document is deliberate and is a stronger assertion than
 * scoping to one panel: the inspector pre-renders ONE Style panel per registered block type, so a
 * control supported by exactly one of the two shipped contracts must appear exactly once. Scoping
 * to a single panel would pass even if the other panel wrongly rendered it too.
 *
 * RefreshDatabase: /editor reads the category table on every render — same note as EditorRendersTest.
 */
class StylePanelGatingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The user theme is a JSON file the RUNNING APP writes (the Style/Themes panel
        // persists every token edit), and phpunit shares testbench's storage with
        // `testbench serve`. Tests below assert the DEFAULT tokens, so anyone who renamed
        // or added a colour in the browser used to turn this suite red — a failure that
        // says nothing about the code. Point the repository at a path that does not exist:
        // ThemeRepository::load() falls back to defaults, and the developer's own theme is
        // left untouched.
        config()->set('heisenberg.theme_path', sys_get_temp_dir() . '/hb-theme-defaults-' . uniqid() . '.json');
    }

    private function editorHtml(): string
    {
        return $this->get('/editor')->getContent();
    }

    private function blockCount(): int
    {
        return count(app(BlockRegistryService::class)->registry()['blocks']);
    }

    private function controlCount(string $html, string $path): int
    {
        return substr_count($html, 'data-hb-control="' . $path . '"');
    }

    /**
     * How many registered contracts DECLARE a supports feature (dotted path under a
     * group; `true` at the leaf). This replaced blockCount() in the count assertions
     * when the registry stopped being two identical text contracts (2026-08-06:
     * group/columns/column/embed each declare different subsets) — a control must
     * render once per contract that declares its feature, not once per contract.
     */
    private function declaring(string $group, ?string $feature = null): int
    {
        $count = 0;
        foreach (app(BlockRegistryService::class)->registry()['blocks'] as $block) {
            $value = $block['supports'][$group] ?? null;
            if ($feature === null) {
                if ($value !== null && $value !== false) {
                    $count++;
                }
                continue;
            }
            $leaf = is_array($value) ? data_get($value, $feature) : null;
            if ($leaf === true || (is_array($leaf) && $leaf !== [])) {
                $count++;
            }
        }

        return $count;
    }

    public function test_sections_render_only_for_contracts_declaring_their_features(): void
    {
        $html = $this->editorHtml();

        // Every count is per-DECLARING-contract: a control renders exactly as many times as
        // there are contracts declaring its feature — never once per registered block, and
        // never for a contract that didn't opt in (a rendered-but-dead control is the exact
        // failure this suite exists to remove).
        $this->assertGreaterThan(0, $this->declaring('layout', 'gap'), 'containers declare layout.gap now');
        $this->assertSame($this->declaring('layout', 'gap'), $this->controlCount($html, 'layout.gap'));
        $this->assertSame($this->declaring('position', 'x'), $this->controlCount($html, 'position.x'));
        $this->assertStringContainsString('<span class="hb-section__title">Position</span>', $html);
        $this->assertStringContainsString('<span class="hb-section__title">Effects</span>', $html);
    }

    public function test_supported_sections_render_once_per_declaring_block_type(): void
    {
        $html = $this->editorHtml();

        // One instance per contract DECLARING the feature. color.text is absent by design —
        // Fill is a layer stack that composites and writes once (§Fill).
        foreach ([
            'typography.fontFamily' => ['typography', 'fontFamily'],
            'typography.fontWeight' => ['typography', 'fontWeight'],
            'typography.fontSize' => ['typography', 'fontSize'],
            'size.width' => ['size', 'width'],
            'size.height' => ['size', 'height'],
            'spacing.padding.top' => ['spacing', 'padding.top'],
            'spacing.margin.top' => ['spacing', 'margin.top'],
        ] as $path => [$group, $feature]) {
            $expected = $this->declaring($group, $feature);
            $this->assertGreaterThan(0, $expected, "{$path} should be declared by at least one contract");
            $this->assertSame($expected, $this->controlCount($html, $path), "{$path} should render once per declaring block type");
        }
    }

    public function test_dimensions_gates_on_size_not_on_the_key_its_title_suggests(): void
    {
        $html = $this->editorHtml();

        // `dimensions` and `size` are BOTH legal SUPPORT_KEYS. The section is titled "Dimensions"
        // but its controls write size.width/size.height, and no shipped contract declares a
        // `dimensions` group at all — so gating on the title's key would hide a section both
        // blocks fully support.
        foreach (['heisenberg/heading', 'heisenberg/paragraph'] as $name) {
            $supports = app(BlockRegistryService::class)->getBlock($name)['supports'] ?? [];
            $this->assertArrayNotHasKey('dimensions', $supports);
            $this->assertArrayHasKey('size', $supports);
        }

        $this->assertStringContainsString('<span class="hb-section__title">Dimensions</span>', $html);
        $this->assertSame($this->declaring('size', 'width'), $this->controlCount($html, 'size.width'));
    }

    public function test_stroke_and_the_corner_fields_follow_border_which_text_blocks_still_never_declare(): void
    {
        $html = $this->editorHtml();

        // TODO 7.2: text blocks do not support borders or corner radius. Both contracts had
        // `border` removed 2026-08-05 along with their seven border-sourced style variables.
        // That half is unchanged and still asserted the same way.
        foreach (['heisenberg/heading', 'heisenberg/paragraph'] as $name) {
            $contract = app(BlockRegistryService::class)->getBlock($name);
            $this->assertArrayNotHasKey('border', $contract['supports'] ?? []);

            $sources = array_column($contract['style']['variables'] ?? [], 'source');
            foreach ($sources as $source) {
                $this->assertStringStartsNotWith('supports.border', $source);
            }
        }

        // The other half is obsolete as of 2026-08-07. This test used to assert that NOTHING on
        // the page mentioned Stroke or a corner field, which was only true while no contract at
        // all declared `border`. The container contracts (group/columns/column) now do, and
        // SupportsStyle backs the corners with --hb-border-radius-{tl,tr,br,bl}, so both mount —
        // for THOSE panels only. Counting per declaring contract keeps the real claim (a section
        // renders exactly where its support is declared) and would still catch the original bug:
        // a leak onto a text block's panel makes the count exceed declaring('border').
        $declarers = $this->declaring('border');
        $this->assertGreaterThan(0, $declarers, 'the container contracts declare border now');
        $this->assertSame($declarers, substr_count($html, '<span class="hb-section__title">Stroke</span>'));

        // Appearance is NOT gated on border alone — TODO 7.1 declared `appearance.opacity`, so it
        // renders for opacity even on a text block, with its four corner fields absent there.
        $this->assertStringContainsString('<span class="hb-section__title">Appearance</span>', $html);
        $this->assertSame($this->declaring('appearance', 'opacity'), $this->controlCount($html, 'appearance.opacity'));

        // Per-side/per-corner paths are the ones the fields actually write, and the contracts map
        // each to the --hb-border-* variable SupportsStyle consumes.
        foreach (['border.width.top', 'border.radius.topLeft'] as $path) {
            $this->assertSame($declarers, $this->controlCount($html, $path), "{$path} renders once per declaring contract");
        }

        // Paths with NO control by design, all of which must stay at zero:
        //  - `border.width`/`border.color` are scalar aggregates — the Weight "all" field fans
        //    out to the four sides (a scalar write would clobber the side map) and the colour is
        //    committed by the composited layer stack (a per-row hook would make the last row win).
        //  - `border.style` lost its control 2026-08-07: the design's Cap select was removed at
        //    the user's request. The contracts still default the style variable to `solid`, so a
        //    Weight the user types paints without a control to set it.
        foreach (['border.width', 'border.color', 'border.style'] as $path) {
            $this->assertSame(0, $this->controlCount($html, $path), "{$path} must not gain a control hook");
        }
    }

    public function test_typography_gates_per_control_not_only_per_section(): void
    {
        $html = $this->editorHtml();

        // heading declares lineHeight; paragraph does not. The hardcoded all-true map this
        // replaced gave paragraph a line-height field with no style variable behind it.
        // letterSpacing is now declared by BOTH (TODO 7.1) — it is the per-control gating
        // mechanism that matters here, not which keys happen to be set today.
        $heading = app(BlockRegistryService::class)->getBlock('heisenberg/heading')['supports']['typography'] ?? [];
        $paragraph = app(BlockRegistryService::class)->getBlock('heisenberg/paragraph')['supports']['typography'] ?? [];
        $this->assertArrayHasKey('lineHeight', $heading);
        $this->assertArrayNotHasKey('lineHeight', $paragraph);

        $this->assertSame(
            $this->declaring('typography', 'lineHeight'),
            $this->controlCount($html, 'typography.lineHeight'),
            'line height renders only for declaring contracts (heading today)',
        );
        $this->assertSame(
            $this->declaring('typography', 'letterSpacing'),
            $this->controlCount($html, 'typography.letterSpacing'),
        );
    }

    public function test_gating_uses_the_same_truthiness_rule_as_the_toolbar(): void
    {
        // Asserted against the Blade SOURCE, not rendered output — the rule is server-side PHP
        // and never reaches the browser.
        $views = __DIR__ . '/../../resources/views/components/live';
        $panel = file_get_contents($views . '/block/style-panel.blade.php');
        $toolbar = file_get_contents($views . '/toolbar/block-toolbar.blade.php');

        // The two surfaces must not drift: present-and-not-false counts as supported, so a
        // contract declaring an empty group has still opted in.
        //
        // Normalised before comparing, because the two files differ in ways that are not the
        // rule: the toolbar writes Arr fully-qualified inline, the panel imports it, and the
        // expression wraps across lines in both.
        $normalise = static fn (string $src): string => preg_replace(
            '/\s+/',
            ' ',
            str_replace('\\Illuminate\\Support\\', '', $src),
        );

        $rule = 'Arr::get($supports, $key, null) !== null && Arr::get($supports, $key) !== false';

        foreach (['style-panel' => $panel, 'block-toolbar' => $toolbar] as $name => $source) {
            $this->assertStringContainsString(
                $rule,
                $normalise($source),
                "{$name} must use the shared supports-truthiness rule",
            );
        }
    }

    public function test_popups_are_not_mounted_when_their_only_trigger_is_gated_away(): void
    {
        $html = $this->editorHtml();

        // Fill and Appearance both trigger the colour picker, so it stays mounted.
        $this->assertStringContainsString('data-hb-style-popup="color"', $html);
        // Effects is the effect editor's only trigger; it renders now that effects.shadow is
        // declared and wired (TODO 7.1), so the editor is mounted with it.
        $this->assertStringContainsString('data-hb-style-popup="effect"', $html);
        $this->assertStringContainsString('data-hb-fx-blur', $html);
    }

    public function test_typography_font_field_searches_the_live_catalog_not_a_static_list(): void
    {
        $html = $this->editorHtml();

        // TODO 7.5. This was a ui/select with five literal families; the left sidebar's Style tab
        // already paged the vendored Google Fonts catalog properly, so this reuses that endpoint
        // and contract rather than a second implementation.
        $this->assertStringContainsString('data-hb-style-font-family', $html);
        $this->assertStringContainsString('data-hb-control-type="combobox"', $html);

        // The five hardcoded families are gone.
        foreach (['JetBrains Mono', 'Georgia'] as $family) {
            $this->assertStringNotContainsString(
                "['value' => '{$family}'",
                $html,
                "'{$family}' looks like a leftover hardcoded font option",
            );
        }

        // Paged search wiring, same shape as panel-style-themes.
        $this->assertStringContainsString('function hbSearchFonts(combobox, query)', $html);
        $this->assertStringContainsString('function hbLoadMoreFonts(combobox, query)', $html);
        $this->assertStringContainsString('__hbCombobox?.replaceOptions(list)', $html);
        $this->assertStringContainsString('__hbCombobox?.appendOptions(list)', $html);
        $this->assertStringContainsString("data-hb-fonts-search-url=\"" . route('heisenberg.editor.fonts.search') . '"', $html);
    }

    public function test_font_page_state_is_per_combobox_not_shared(): void
    {
        $html = $this->editorHtml();

        // The Style panel is pre-rendered once per registered block type, so several font
        // comboboxes exist at once. A shared offset would make one field's scroll paginate
        // another's results.
        $this->assertStringContainsString('combobox.__hbFontPage = page', $html);
        $this->assertStringContainsString('if (combobox.__hbFontPage !== page) return;', $html);
    }

    public function test_contracts_opt_into_the_capability_sheet_and_map_its_generic_variables(): void
    {
        // TODO 7.1. SupportsStyle implements opacity/letter-spacing/text-align against generic
        // --hb-* names, gated behind an opt-in `hb-supports` class. Declaring the supports group
        // alone does nothing without BOTH the class and a style.variables entry pointing at it.
        foreach (['heisenberg/heading', 'heisenberg/paragraph'] as $name) {
            $contract = app(BlockRegistryService::class)->getBlock($name);

            $this->assertStringContainsString('hb-supports', $contract['style']['className'] ?? '');

            $bySource = [];
            foreach ($contract['style']['variables'] ?? [] as $var => $def) {
                $bySource[$def['source'] ?? ''] = $var;
            }

            $this->assertSame('--hb-text-align', $bySource['supports.typography.textAlign'] ?? null);
            $this->assertSame('--hb-text-align-v', $bySource['supports.typography.textAlignVertical'] ?? null);
            $this->assertSame('--hb-letter-spacing', $bySource['supports.typography.letterSpacing'] ?? null);
            $this->assertSame('--hb-opacity', $bySource['supports.appearance.opacity'] ?? null);
        }
    }

    public function test_the_capability_stylesheet_actually_reaches_the_editor(): void
    {
        $html = $this->editorHtml();

        // The route serving it (/heisenberg-assets/editor-supports.css) existed but no view ever
        // linked it, so every capability SupportsStyle implements was unreachable in the canvas
        // regardless of what a contract declared. blocksCss now prepends it.
        $this->assertStringContainsString('[data-block-id].hb-supports', $html);
        $this->assertStringContainsString('--hb-text-align', $html);
        $this->assertStringContainsString('--hb-opacity', $html);
    }

    public function test_typography_text_alignment_is_wired_and_distinct_from_block_alignment(): void
    {
        $html = $this->editorHtml();

        // TODO 7.4: the standalone Alignment section places the BLOCK in its parent
        // (supports.align -> hb-align-* class, no style variable); Typography's segmenteds place
        // the TEXT inside the block. Both were decorative; the text pair is now wired.
        $this->assertSame($this->declaring('typography', 'textAlign'), $this->controlCount($html, 'typography.textAlign'));
        $this->assertSame($this->declaring('typography', 'textAlignVertical'), $this->controlCount($html, 'typography.textAlignVertical'));
        $this->assertStringContainsString('data-hb-control-type="segmented"', $html);

        // Vertical alignment compiles through align-self, whose sanitizer is `align-3`
        // (start|center|end) — top/middle/bottom would fail validation and render nothing.
        foreach (['start', 'center', 'end'] as $value) {
            $this->assertStringContainsString('data-hb-tab="' . $value . '"', $html);
        }

        // Labels distinguish the two, since both read as "alignment" otherwise.
        $this->assertStringContainsString('Text horizontal', $html);
        $this->assertStringContainsString('Text vertical', $html);
    }

    public function test_segmented_controls_deselect_when_the_support_is_unset(): void
    {
        $html = $this->editorHtml();

        // A tablist always has one tab selected by default; for a control bound to an unset
        // support that would read as a real choice the user never made.
        $this->assertStringContainsString("if (type === 'segmented')", $html);
        $this->assertStringContainsString("tab.dataset.hbTab === text && text !== '' ? 'true' : 'false'", $html);
    }

    public function test_theme_variables_actually_resolve_in_the_editor(): void
    {
        $html = $this->editorHtml();

        // Only preview.blade.php ever emitted ThemeRepository::css(), so in the editor every
        // `var(--hb-t-*)` reference resolved to nothing: the Style/Themes panel could save tokens
        // the canvas could not display, and binding a block style to one was pointless (TODO 7.6).
        $this->assertStringContainsString('id="hb-theme-vars"', $html);
        $this->assertStringContainsString('--hb-t-accent-1', $html);
    }

    public function test_variable_menu_is_mounted_in_the_inspector_with_real_theme_tokens(): void
    {
        $html = $this->editorHtml();

        // live/pickers/variable-menu existed but was mounted only in the components gallery, and
        // its token list was a hardcoded array — wiring it without real tokens would offer names
        // that do not exist.
        $this->assertStringContainsString('data-hb-style-popup="var-color"', $html);
        $this->assertStringContainsString('data-hb-style-popup="var-number"', $html);
        $this->assertStringContainsString('data-hb-varmenu', $html);

        // A row READS as the name the user gave the token in the Style tab and WRITES the CSS
        // reference. Passing ThemeRepository::tokens() straight through inverted this — that map
        // is keyed by CSS reference, so every row rendered as "var(--hb-t-accent-1)".
        $this->assertStringContainsString('data-vm-name="Accent"', $html);
        $this->assertStringContainsString('data-vm-value="var(--hb-t-accent-1)"', $html);
        $this->assertStringNotContainsString('data-vm-name="var(--hb-t-', $html);

        // Consumers must read detail.value (the reference), not detail.name (the label).
        $this->assertStringContainsString('const value = event.detail?.value ?? event.detail?.name ?? \'\';', $html);
    }

    public function test_style_text_fields_get_the_theme_variable_trigger_with_three_states(): void
    {
        $html = $this->editorHtml();

        // TODO 7.7 — selection-all-fill at the right end of Block.style text fields.
        $this->assertStringContainsString('data-hb-style-var-trigger', $html);
        $this->assertStringContainsString('data-icon-name="selection-all-fill"', $html);

        // bound -> accent, unset -> muted, manual -> muted but hover-only.
        $this->assertStringContainsString("if (v === '') return 'unset';", $html);
        $this->assertStringContainsString("return /^var\\(\\s*--/.test(v) ? 'bound' : 'manual';", $html);
    }

    public function test_the_variable_trigger_is_scoped_to_the_style_sub_tab_only(): void
    {
        $html = $this->editorHtml();

        // "only for the Block.style sub-tab" — the decorator reads the mounted style root, so
        // Content/Advanced/Post fields never receive it. A prop on ui/field would have put the
        // affordance on every field in the editor.
        $this->assertStringContainsString('function hbDecorateVarTriggers(root)', $html);
        $this->assertStringContainsString('[data-hb-style-var-prototype] [data-hb-style-var-trigger]', $html);

        // The prototype is the only occurrence rendered server-side; the rest are cloned at
        // runtime, so exactly one trigger per Style panel exists in the delivered HTML.
        $this->assertSame(
            $this->blockCount(),
            substr_count($html, 'data-hb-style-var-trigger aria-expanded'),
            'only the per-panel prototype should be server-rendered',
        );
    }

    public function test_variable_selection_writes_through_the_shared_control_path(): void
    {
        $html = $this->editorHtml();

        // Writing via setSupport directly would bypass the linked-value handlers (spacing's
        // aggregate modes, the corner group), leaving those summaries stale.
        $this->assertStringContainsString("input.dispatchEvent(new Event('input', { bubbles: true }))", $html);
        $this->assertStringContainsString("input.dispatchEvent(new Event('change', { bubbles: true }))", $html);
    }

    public function test_the_variable_trigger_survives_the_outside_click_handler(): void
    {
        $html = $this->editorHtml();

        // REGRESSION. The Style panel has a document-level handler that closes every popup when
        // the click was not on an allow-listed trigger. Both it and each trigger's own handler
        // are on `document`, so stopPropagation cannot keep them apart — a trigger missing from
        // this list has its popup closed the instant it opens, which reads as the button doing
        // nothing at all. That is exactly what happened to data-hb-style-var-trigger.
        // The allow-list is the HB_STYLE_CHROME constant now, shared by the panel-scoped close
        // and the canvas-click close, so the trigger is pinned in the constant rather than in
        // one inlined copy of it.
        $this->assertMatchesRegularExpression(
            "/const HB_STYLE_CHROME = '\[data-hb-style-popup\][^']*\[data-hb-style-var-trigger\][^']*';/",
            $html,
            'the var trigger must be in the outside-click allow-list, or its popup closes on open',
        );
        $this->assertMatchesRegularExpression(
            "/if \(!event\.target\.closest\(HB_STYLE_CHROME\)\) \{\s*closeStylePopups\(root\);/",
            $html,
        );

        // Its aria-expanded must be reset alongside the other triggers too.
        $this->assertMatchesRegularExpression(
            "/querySelectorAll\('\[data-hb-style-color-trigger\][^']*\[data-hb-style-var-trigger\][^']*'\)\.forEach/",
            $html,
        );
    }

    public function test_every_menu_a_trigger_can_request_is_actually_mounted(): void
    {
        $html = $this->editorHtml();

        // hbVarMenuFor() routes to one of three names; a route with no mounted popup makes
        // showStylePopup() a silent no-op.
        foreach (['var-color', 'var-number', 'var-font'] as $menu) {
            $this->assertStringContainsString('data-hb-style-popup="' . $menu . '"', $html, "{$menu} is routed to but not mounted");
        }
        $this->assertStringContainsString("if (/fontFamily$/i.test(path)) return 'var-font';", $html);
    }

    public function test_the_font_clear_button_is_replaced_by_the_variable_trigger(): void
    {
        $html = $this->editorHtml();

        // The `x` clear-font button is gone, per instruction, and its handler with it.
        $this->assertStringNotContainsString('data-hb-style-clear-font', $html);

        // Replaced in place by the trigger. It is a SIBLING of the combobox rather than a
        // descendant (a combobox owns its own trailing caret), so it names its target explicitly
        // — and that target must exist.
        $this->assertStringContainsString('data-hb-style-var-for="typography.fontFamily"', $html);
        $this->assertStringContainsString('data-hb-control="typography.fontFamily"', $html);

        // Clearing is preserved: each menu leads with a "Default" row whose emitted value is
        // empty, so picking it writes '' exactly as the x did.
        $this->assertMatchesRegularExpression('/data-vm-name="Default"\s+data-vm-value=""/', $html);
    }

    public function test_a_combobox_bound_to_a_token_commits_through_its_own_api(): void
    {
        $html = $this->editorHtml();

        // Writing a combobox's inner <input> is reverted on its next render, and the delegated
        // write handler ignores events whose target is not the combobox root — so the model
        // would never see the change either.
        $this->assertStringContainsString("if (control.getAttribute('data-hb-control-type') === 'combobox') {", $html);
        // Two arguments, not one: the model gets the CSS reference, the field shows the
        // token's resolved value (integer or family name). Passing the reference as the label
        // made a bound field read as var(--hb-t-…).
        $this->assertStringContainsString('control.__hbCombobox?.setValue(value, resolved);', $html);
    }

    public function test_a_composed_shadow_survives_the_renderer(): void
    {
        // The Effects editor's five fields compose ONE box-shadow string into
        // supports.effects.shadow. This proves the composed shape actually renders rather than
        // being silently dropped by the `shadow` sanitizer — the failure mode that made every
        // other "wired" control in this phase look like it worked.
        $html = app(\Heisenberg\Services\BlockRenderer::class)->renderBlock([
            'id' => 'fx1',
            'name' => 'heisenberg/paragraph',
            'attributes' => ['content' => 'x'],
            // Exactly what hbComposeShadow() builds: x, y, blur, then rgba() folding opacity in.
            'supports' => ['effects' => ['shadow' => '0px 8px 28px rgba(0, 0, 0, 0.14)']],
            'innerBlocks' => [],
        ], 'en');

        $this->assertStringContainsString('--hb-shadow: 0px 8px 28px rgba(0, 0, 0, 0.14)', $html);

        // And a malformed one is refused rather than injected.
        $bad = app(\Heisenberg\Services\BlockRenderer::class)->renderBlock([
            'id' => 'fx2',
            'name' => 'heisenberg/paragraph',
            'attributes' => ['content' => 'x'],
            'supports' => ['effects' => ['shadow' => 'red; background: url(javascript:alert(1))']],
            'innerBlocks' => [],
        ], 'en');

        $this->assertStringNotContainsString('--hb-shadow', $bad);
        $this->assertStringNotContainsString('javascript:', $bad);
    }

    public function test_flex_layout_gates_on_being_a_container_not_on_supports_layout(): void
    {
        $html = $this->editorHtml();

        // A flex container lays out its children; the control is incoherent on a block that
        // cannot have any. Gating on innerBlocks.enabled means the section appeared
        // automatically the day the container contracts landed (2026-08-06: group/columns/
        // column), with nothing to remember. Asserted against the Blade source — the gate is
        // server-side and never reaches the page.
        $panel = file_get_contents(__DIR__ . '/../../resources/views/components/live/block/style-panel.blade.php');
        $this->assertStringContainsString("\$isContainer = (bool) (\$innerBlocks['enabled'] ?? false);", $panel);
        $this->assertStringContainsString('@if ($isContainer && $has(\'layout\'))', $panel);

        $containers = 0;
        foreach (app(BlockRegistryService::class)->registry()['blocks'] as $block) {
            if (($block['innerBlocks']['enabled'] ?? false) === true) {
                $containers++;
            }
        }
        $this->assertGreaterThanOrEqual(3, $containers, 'group/columns/column are containers');
        foreach (['heisenberg/heading', 'heisenberg/paragraph'] as $name) {
            $this->assertFalse(app(BlockRegistryService::class)->getBlock($name)['innerBlocks']['enabled'] ?? false);
        }
        // The section mounts once per CONTAINER panel and never for a text block's.
        $this->assertSame(
            $containers,
            substr_count($html, '<span class="hb-section__title">Flex Layout</span>'),
        );
    }

    public function test_absolute_position_checkbox_writes_a_css_keyword_not_a_boolean(): void
    {
        $html = $this->editorHtml();

        // supports.position.mode is sanitised as `position-mode` (static|relative|absolute), so
        // a plain boolean would be rejected and render nothing. The control declares the strings
        // it writes for each state instead.
        $this->assertMatchesRegularExpression(
            '/data-hb-control="position\.mode"[^>]*data-hb-control-type="checkbox"/',
            $html,
        );
        $this->assertStringContainsString('data-hb-control-on="absolute"', $html);
        $this->assertStringContainsString(
            "raw = (on === null && off === null) ? checked : (checked ? (on ?? 'true') : (off ?? ''));",
            $html,
        );

        // Off writes '' so the variable falls back to SupportsStyle's own default rather than
        // pinning an explicit `static` the user never chose.
        $this->assertStringContainsString('data-hb-control-off=""', $html);
    }

    public function test_alignment_section_mounts_only_for_contracts_declaring_align(): void
    {
        $html = $this->editorHtml();

        // The Alignment section is the BLOCK's placement in its parent (`supports.align` →
        // hb-align-* class). Text contracts deliberately declare no `align` — text alignment
        // is Typography's textAlign/textAlignVertical. Containers (group/columns) DO declare
        // it, and they mount the SAME extracted section, never a parallel UI.
        $declarers = 0;
        foreach (app(BlockRegistryService::class)->registry()['blocks'] as $block) {
            if (is_array($block['supports']['align'] ?? null) && ($block['supports']['align'] ?? []) !== []) {
                $declarers++;
            }
        }
        $this->assertGreaterThanOrEqual(2, $declarers, 'group and columns declare align');
        $this->assertSame(
            $declarers,
            preg_match_all('/data-hb-control="align"[^>]*data-hb-control-type="segmented"/', $html),
        );

        $this->assertArrayNotHasKey('align', app(BlockRegistryService::class)->getBlock('heisenberg/heading')['supports']);
        $this->assertArrayNotHasKey('align', app(BlockRegistryService::class)->getBlock('heisenberg/paragraph')['supports']);
    }

    public function test_theme_token_edits_repaint_immediately(): void
    {
        $html = $this->editorHtml();

        // The editor emits #hb-theme-vars once at render time, so before this a token edit was
        // invisible until reload — and any block bound to that token appeared not to respond.
        $this->assertStringContainsString('const applyThemeVars = () => {', $html);
        $this->assertStringContainsString("document.getElementById('hb-theme-vars')", $html);

        // Applied immediately rather than on the save debounce, so dragging a colour reads live.
        $this->assertMatchesRegularExpression(
            '/const scheduleSave = \(\) => \{\s*applyThemeVars\(\);/',
            $html,
        );

        // Must mirror ThemeRepository::css()'s prefix and font quoting, or preview and saved
        // render disagree.
        $this->assertStringContainsString("'  --hb-t-' + token.name + ': ' + token.value + ';'", $html);
        $this->assertStringContainsString("', sans-serif;'", $html);
    }

    public function test_fill_hug_clip_reach_the_rendered_block_as_classes(): void
    {
        $renderer = app(\Heisenberg\Services\BlockRenderer::class);

        // These could NOT be supports. They are class-based capabilities in SupportsStyle —
        // `hb-size-fill-w` sets width:100%, which a bare custom property cannot express — and
        // classes come from style.classNames, whose predicates BlockRenderer::predicateMatches()
        // resolves against `attributes` only, never `supports`. So an attribute is the only shape
        // that reaches the class. This proves the whole route, not just that hooks exist.
        $on = $renderer->renderBlock([
            'id' => 'sz1',
            'name' => 'heisenberg/paragraph',
            'attributes' => ['content' => 'x', 'fillWidth' => true, 'clipContent' => true],
            'supports' => [],
            'innerBlocks' => [],
        ], 'en');

        $this->assertStringContainsString('hb-size-fill-w', $on);
        $this->assertStringContainsString('hb-size-clip', $on);
        // SupportsStyle's rules are `[data-block-id].hb-supports.hb-size-*`, so the opt-in class
        // must be present too or the markers select nothing.
        $this->assertStringContainsString('hb-supports', $on);
        $this->assertStringContainsString('data-block-id="sz1"', $on);

        // Unset means absent, not merely inert.
        $off = $renderer->renderBlock([
            'id' => 'sz2',
            'name' => 'heisenberg/paragraph',
            'attributes' => ['content' => 'x'],
            'supports' => [],
            'innerBlocks' => [],
        ], 'en');

        foreach (['hb-size-fill-w', 'hb-size-fill-h', 'hb-size-hug-w', 'hb-size-hug-h', 'hb-size-clip'] as $marker) {
            $this->assertStringNotContainsString($marker, $off);
        }
    }

    public function test_a_boolean_checkbox_writes_a_real_boolean_not_a_string(): void
    {
        $html = $this->editorHtml();

        // classNames predicates compare with ===, so the string 'true' would never match and the
        // class would never appear. The checkbox type only stringifies when on/off are declared.
        $this->assertStringContainsString(
            "raw = (on === null && off === null) ? checked : (checked ? (on ?? 'true') : (off ?? ''));",
            $html,
        );
        $this->assertMatchesRegularExpression(
            '/data-hb-control="fillWidth"[^>]*data-hb-control-kind="attributes"[^>]*data-hb-control-type="checkbox"/',
            $html,
        );
        // No on/off attributes on the size boxes — that is what selects boolean mode.
        $this->assertDoesNotMatchRegularExpression(
            '/data-hb-control="fillWidth"[^>]*data-hb-control-on=/',
            $html,
        );
    }

    public function test_no_inert_control_renders_in_the_style_panel(): void
    {
        $html = $this->editorHtml();

        // The completion criterion for TODO 7.1: a control that renders must write somewhere.
        // Every remaining unhooked element in style/*.blade.php is either chrome (panel-section,
        // icon) or a deliberate AGGREGATE — spacing's "all sides"/axis fields and Appearance's
        // "all corners" field hold no model path of their own and commit through their group
        // (§4.3). What must not appear is a control that looks editable and writes nothing.
        //
        // These are the known inert ones. They live in sections gated off for text blocks —
        // Stroke and Appearance's corners need `border` (7.2); the Flex Layout grid needs a
        // container (7.1) — so none of them should reach the page.
        //
        // Scripts are stripped first: the handlers for these controls still exist and reference
        // the same markers as SELECTOR STRINGS. Matching raw HTML finds those and reports a
        // rendered control that is not there.
        $markup = preg_replace('#<script\b[^>]*>.*?</script>#is', '', $html);

        // This list shrinks as extracted controls get WIRED, never as they get replaced:
        // hb-agrid + hb-iradio left it 2026-08-06 (the Flex Layout grid writes
        // layout.justify×align, the radios layout.justify); stroke-sides and
        // appearance-corners left it 2026-08-07 (their per-side/corner fields carry
        // border.width.*/border.radius.* hooks and the "all" field fans out to them).
        // Stroke's Position/Join selects stay listed: they are vector-editor concepts with
        // no CSS border equivalent, so they render only behind the `vectorControls` flag.
        $inert = [
            'hb-style-stroke__position' => 'Stroke position select',
        ];

        foreach ($inert as $marker => $what) {
            $this->assertStringNotContainsString(
                $marker,
                $markup,
                "{$what} renders but writes nothing — it should be gated off or wired",
            );
        }

        // Guard the guard: stripping must not have removed the panel itself, or this passes
        // vacuously.
        $this->assertStringContainsString('hb-blockstyle', $markup);
        $this->assertStringContainsString('<span class="hb-section__title">Typography</span>', $markup);
    }

    public function test_a_bound_field_displays_the_token_value_not_its_css_reference(): void
    {
        $html = $this->editorHtml();

        // Picking a token wrote var(--hb-t-…) straight into the field, so a bound control read as
        // its own CSS reference. The field now shows the VALUE (the integer the Style/Themes
        // panel holds, e.g. "16") while the model keeps the reference, split by data-hb-var-bound.
        $this->assertStringContainsString('function hbVarLabelOf(root, ref)', $html);
        $this->assertStringContainsString('function hbVarResolvedValue(root, ref)', $html);
        $this->assertStringContainsString('data-hb-var-labels="{', $html);
        $this->assertStringContainsString('data-hb-var-values="{', $html);
        $this->assertStringContainsString('if (label) control.dataset.hbVarBound = value;', $html);
        $this->assertStringContainsString('input.value = resolved;', $html);

        // The write path must send the reference, never the displayed integer.
        $this->assertStringContainsString('raw = el.dataset.hbVarBound || input.value;', $html);

        // The label/value maps have to survive reload and re-selection, so they are re-derived
        // on sync from the stored value rather than trusting a leftover attribute.
        $this->assertStringContainsString('if (label) el.dataset.hbVarBound = ref;', $html);

        // The indicator reads the binding, not the visible text — a bound field displays an
        // integer, which would otherwise look like a hand-typed literal.
        $this->assertStringContainsString("if (control.dataset.hbVarBound) {", $html);
        $this->assertStringContainsString("button.dataset.hbVarState = 'bound';", $html);
    }

    public function test_typing_over_a_bound_field_breaks_the_binding(): void
    {
        $html = $this->editorHtml();

        // Otherwise the stale reference would keep being written and silently discard what was
        // typed. Compared against the resolved integer (the value the field actually displays)
        // — falling back to the label when a token has no resolvable number.
        $this->assertStringContainsString(
            'const expected = hbVarResolvedValue(root, bound) ?? hbVarLabelOf(root, bound);',
            $html,
        );
        $this->assertStringContainsString(
            "if (input && input.value !== expected) {\n                delete control.dataset.hbVarBound;",
            $html,
        );
    }

    public function test_a_colour_layer_has_one_trigger_per_affordance_not_two(): void
    {
        $html = $this->editorHtml();

        // The row shipped a selection-all-fill button that opened the COLOUR PICKER, and the
        // decorator then injected a second identical icon outside the field for the variable
        // popup — two of the same glyph doing different things. The swatch now opens the picker
        // and the icon opens the variable popup, so the decorator's "already has a var trigger"
        // check skips the row and the duplicate disappears at its source.
        $this->assertStringContainsString('class="hb-colorlayer__swatch"', $html);
        // Markup follows the .pen composition (Documents/head-ui.html): the swatch stays a
        // <span> and opacity stays text. Only hooks were added — an earlier pass rewrote both
        // into form controls, which was a redesign rather than wiring.
        $this->assertMatchesRegularExpression(
            '/<span class="hb-colorlayer__swatch"[^>]*data-hb-style-color-trigger/s',
            $html,
            'the swatch must open the colour picker and remain a span',
        );
        $this->assertMatchesRegularExpression(
            '/class="hb-colorlayer__open"[^>]*data-hb-style-var-trigger/s',
            $html,
            'the inline icon must open the theme-variable popup',
        );
        // The old wiring, where the icon was the picker trigger.
        $this->assertDoesNotMatchRegularExpression(
            '/class="hb-colorlayer__open"[^>]*data-hb-style-color-trigger/s',
            $html,
        );
    }

    public function test_fill_and_stroke_composite_a_layer_stack(): void
    {
        $html = $this->editorHtml();

        // Layers paint bottom-up, so the newest sits on top. CSS colour takes one value, so the
        // stack is flattened with source-over alpha compositing.
        $this->assertStringContainsString('function hbCompositeLayers(layers)', $html);
        $this->assertStringContainsString('const outA = a + out.a * (1 - a);', $html);
        $this->assertStringContainsString("const HB_LAYER_PATHS = { fill: 'color.text', stroke: 'border.color' };", $html);

        // Both shapes are stored: the flattened colour the renderer already sanitizes, and the
        // raw stack so reopening a block restores every layer rather than just the result.
        $this->assertStringContainsString("path.split('.')[0] + '.layers'", $html);

        // A per-row control hook would make each layer overwrite the same scalar, so the last row
        // would always win and stacking could never work.
        $this->assertStringNotContainsString('data-hb-control="color.text"', $html);
        $this->assertStringNotContainsString('data-hb-control="border.color"', $html);

        // Opacity is read per layer. It stays a DISPLAY span fed by the colour picker's alpha —
        // the picker already emits `a` on colorchange, so a second typed field for the same
        // number would be redundant, and the .pen composition has no such field.
        $this->assertStringContainsString('data-hb-style-layer-opacity', $html);
        $this->assertStringContainsString("row.querySelector('[data-hb-style-layer-opacity]')?.textContent", $html);
    }

    public function test_the_trigger_state_styling_is_keyed_on_behaviour_not_a_class(): void
    {
        // Triggers come from two places with different classes: injected into a field
        // (.hb-varbtn, absolutely positioned) and rendered inline inside a colour layer
        // (.hb-colorlayer__open, placed by the .pen composition). Keying the STATE colours on
        // .hb-varbtn meant a layer's trigger never showed any state — selecting a token looked
        // like nothing had happened. Positioning stays class-scoped; the states are shared.
        $css = $this->get('/heisenberg-assets/editor.css')->getContent();

        foreach (['bound', 'unset', 'manual'] as $state) {
            $this->assertStringContainsString(
                '[data-hb-style-var-trigger][data-hb-var-state="' . $state . '"]',
                $css,
                "the {$state} state must key on the behaviour attribute",
            );
        }

        // A layer reveals its manual-state trigger on row hover, the injected ones on field hover.
        $this->assertStringContainsString('.hb-colorlayer:hover [data-hb-style-var-trigger]', $css);
        // Positioning must NOT leak onto the inline layer trigger.
        $this->assertStringContainsString('.hb-varbtn {', $css);
    }

    public function test_selecting_a_token_on_a_layer_paints_its_swatch(): void
    {
        $html = $this->editorHtml();

        // The swatch takes the REFERENCE, not a resolved hex: the theme's --hb-t-* properties are
        // on the page so the browser resolves it, and the swatch then tracks the token if its
        // value is later edited in the Style tab.
        $this->assertStringContainsString("const swatch = control.querySelector('.hb-colorlayer__swatch');", $html);
        $this->assertStringContainsString("swatch.style.background = value || 'transparent';", $html);

        // A layer carries no data-hb-control, so it has to be synced explicitly or its indicator
        // renders unstyled — including on a freshly added row.
        $this->assertStringContainsString("root.querySelectorAll('[data-hb-control], .hb-colorlayer').forEach(hbSyncVarTrigger);", $html);
        $this->assertStringContainsString("list.querySelectorAll('.hb-colorlayer').forEach(hbSyncVarTrigger);", $html);
    }

    public function test_state_section_is_never_contract_gated(): void
    {
        $html = $this->editorHtml();

        // BlockRenderer::stateStylesCss() reads `supports.states` off the block INSTANCE, and
        // `states` is deliberately absent from BlockContractValidator::SUPPORT_KEYS — so no
        // contract can declare it and it must not be gated on one.
        $this->assertStringContainsString('<span class="hb-section__title">State</span>', $html);

        $reflection = new \ReflectionClass(\Heisenberg\Services\BlockContractValidator::class);
        $keys = $reflection->getConstant('SUPPORT_KEYS');
        $this->assertIsArray($keys);
        $this->assertNotContains('states', $keys);
    }
}
