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

    public function test_sections_with_no_supports_group_do_not_render_at_all(): void
    {
        $html = $this->editorHtml();

        // `layout` is the remaining unsupported group. Its controls must be absent entirely —
        // not merely disabled, since a rendered-but-dead control is the exact failure this
        // change exists to remove.
        $this->assertSame(0, $this->controlCount($html, 'layout.gap'), 'layout.gap is unsupported and must not render');
        $this->assertStringNotContainsString('<span class="hb-section__title">Flex Layout</span>', $html);

        // Position and Effects were in this list until their controls were wired (TODO 7.1) —
        // declaring a group whose section writes nothing just recreates the problem, so they
        // were declared only once they could actually do something.
        $this->assertSame($this->blockCount(), $this->controlCount($html, 'position.x'));
        $this->assertStringContainsString('<span class="hb-section__title">Position</span>', $html);
        $this->assertStringContainsString('<span class="hb-section__title">Effects</span>', $html);
    }

    public function test_supported_sections_render_once_per_registered_block_type(): void
    {
        $html = $this->editorHtml();
        $n = $this->blockCount();
        $this->assertGreaterThan(0, $n);

        // Declared by BOTH contracts, so one instance per pre-rendered panel.
        foreach ([
            'color.text',
            'typography.fontFamily',
            'typography.fontWeight',
            'typography.fontSize',
            'size.width',
            'size.height',
            'spacing.padding.top',
            'spacing.margin.top',
        ] as $path) {
            $this->assertSame($n, $this->controlCount($html, $path), "{$path} should render once per block type");
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
        $this->assertSame($this->blockCount(), $this->controlCount($html, 'size.width'));
    }

    public function test_text_blocks_support_no_border_so_stroke_and_appearance_both_disappear(): void
    {
        $html = $this->editorHtml();

        // TODO 7.2: text blocks do not support borders or corner radius. Both contracts had
        // `border` removed 2026-08-05 along with their seven border-sourced style variables.
        foreach (['heisenberg/heading', 'heisenberg/paragraph'] as $name) {
            $contract = app(BlockRegistryService::class)->getBlock($name);
            $this->assertArrayNotHasKey('border', $contract['supports'] ?? []);

            $sources = array_column($contract['style']['variables'] ?? [], 'source');
            foreach ($sources as $source) {
                $this->assertStringStartsNotWith('supports.border', $source);
            }
        }

        // Stroke follows `border` directly and is gone. Appearance is NOT — TODO 7.1 declared
        // `appearance.opacity`, that section's other control, so it renders for opacity alone
        // while its four corner-radius fields stay gone with `border`.
        $this->assertStringNotContainsString('<span class="hb-section__title">Stroke</span>', $html);
        $this->assertStringContainsString('<span class="hb-section__title">Appearance</span>', $html);

        foreach (['border.color', 'border.width', 'border.radius.topLeft'] as $path) {
            $this->assertSame(0, $this->controlCount($html, $path), "{$path} must not render");
        }
        $this->assertSame($this->blockCount(), $this->controlCount($html, 'appearance.opacity'));
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

        $this->assertSame(1, $this->controlCount($html, 'typography.lineHeight'), 'line height is heading-only');
        $this->assertSame(
            $this->blockCount(),
            $this->controlCount($html, 'typography.letterSpacing'),
            'letter spacing is declared by both',
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
        $n = $this->blockCount();

        // TODO 7.4: the standalone Alignment section places the BLOCK in its parent
        // (supports.align -> hb-align-* class, no style variable); Typography's segmenteds place
        // the TEXT inside the block. Both were decorative; the text pair is now wired.
        $this->assertSame($n, $this->controlCount($html, 'typography.textAlign'));
        $this->assertSame($n, $this->controlCount($html, 'typography.textAlignVertical'));
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
        $this->assertMatchesRegularExpression(
            "/if \(!event\.target\.closest\('\[data-hb-style-popup\][^']*\[data-hb-style-var-trigger\][^']*'\)\) \{\s*closeStylePopups\(root\);/",
            $html,
            'the var trigger must be in the outside-click allow-list, or its popup closes on open',
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
        $this->assertStringContainsString('control.__hbCombobox?.setValue(value, value);', $html);
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
        // cannot have any. Gating on innerBlocks.enabled means the section appears automatically
        // when a real container contract exists, rather than needing `layout` remembered too.
        // Asserted against the Blade source — the gate is server-side and never reaches the page.
        $panel = file_get_contents(__DIR__ . '/../../resources/views/components/live/block/style-panel.blade.php');
        $this->assertStringContainsString("\$isContainer = (bool) (\$innerBlocks['enabled'] ?? false);", $panel);
        $this->assertStringContainsString('@if ($isContainer && $has(\'layout\'))', $panel);

        foreach (['heisenberg/heading', 'heisenberg/paragraph'] as $name) {
            $this->assertFalse(app(BlockRegistryService::class)->getBlock($name)['innerBlocks']['enabled'] ?? false);
        }
        $this->assertStringNotContainsString('<span class="hb-section__title">Flex Layout</span>', $html);
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

    public function test_alignment_offers_only_values_the_contract_declares(): void
    {
        $html = $this->editorHtml();

        // The section is the BLOCK's placement in its parent, wired to `align` — which needs no
        // style variable, since resolveClass() emits hb-align-<value> directly.
        $this->assertMatchesRegularExpression(
            '/data-hb-control="align"[^>]*data-hb-control-type="segmented"/',
            $html,
        );

        // The design's three vertical options are NOT rendered: `align` accepts only
        // left|center|right|wide|full, so top/middle/bottom would write a value resolveClass()
        // discards — three controls on screen writing nowhere, the defect this phase removes.
        foreach (['top', 'middle', 'bottom'] as $vertical) {
            $this->assertStringNotContainsString('data-hb-tab="' . $vertical . '"', $html);
        }

        // And it offers exactly what the contract declares.
        $declared = app(BlockRegistryService::class)->getBlock('heisenberg/heading')['supports']['align'] ?? [];
        $this->assertSame(['left', 'center', 'right'], $declared);
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

        $inert = [
            'hb-agrid' => 'Flex Layout 3x3 alignment grid',
            'hb-iradio' => 'Flex Layout space-between/around radios',
            'stroke-sides' => 'Stroke per-side width fields',
            'hb-style-stroke__position' => 'Stroke position select',
            'appearance-corners' => 'Appearance corner-radius fields',
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
