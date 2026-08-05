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

        // Neither shipped contract declares position, layout or effects. Their controls must be
        // absent entirely — not merely disabled, since a rendered-but-dead control is the exact
        // failure this change exists to remove.
        foreach (['position.x', 'position.y', 'position.rotation', 'layout.gap'] as $path) {
            $this->assertSame(0, $this->controlCount($html, $path), "{$path} is unsupported and must not render");
        }

        $this->assertStringNotContainsString('<span class="hb-section__title">Position</span>', $html);
        $this->assertStringNotContainsString('<span class="hb-section__title">Flex Layout</span>', $html);
        $this->assertStringNotContainsString('<span class="hb-section__title">Effects</span>', $html);
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

        // Fill is the colour picker's only surviving trigger now that Stroke and Appearance are
        // gone with `border` (TODO 7.2), and Fill still renders — so the popup stays mounted.
        $this->assertStringContainsString('data-hb-style-popup="color"', $html);
        // Effects is the effect editor's only trigger, and Effects is gated away.
        $this->assertStringNotContainsString('data-hb-style-popup="effect"', $html);
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

        // Options come from ThemeRepository::tokens(), whose keys ARE the CSS values.
        $this->assertStringContainsString('data-vm-name="var(--hb-t-accent-1)"', $html);
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
