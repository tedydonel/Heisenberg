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
            $this->assertArrayNotHasKey('appearance', $contract['supports'] ?? []);

            $sources = array_column($contract['style']['variables'] ?? [], 'source');
            foreach ($sources as $source) {
                $this->assertStringStartsNotWith('supports.border', $source);
            }
        }

        // Stroke follows `border` directly. Appearance follows it too, because corner radius was
        // the only thing keeping that section alive — its other control, opacity, needs
        // `appearance`, which neither contract declares.
        $this->assertStringNotContainsString('<span class="hb-section__title">Stroke</span>', $html);
        $this->assertStringNotContainsString('<span class="hb-section__title">Appearance</span>', $html);

        foreach (['border.color', 'border.width', 'border.radius.topLeft', 'appearance.opacity'] as $path) {
            $this->assertSame(0, $this->controlCount($html, $path), "{$path} must not render");
        }
    }

    public function test_typography_gates_per_control_not_only_per_section(): void
    {
        $html = $this->editorHtml();

        // heading declares lineHeight; paragraph does not. Neither declares letterSpacing.
        // The hardcoded all-true map this replaced gave paragraph a line-height field with no
        // style variable behind it.
        $heading = app(BlockRegistryService::class)->getBlock('heisenberg/heading')['supports']['typography'] ?? [];
        $paragraph = app(BlockRegistryService::class)->getBlock('heisenberg/paragraph')['supports']['typography'] ?? [];
        $this->assertArrayHasKey('lineHeight', $heading);
        $this->assertArrayNotHasKey('lineHeight', $paragraph);
        $this->assertArrayNotHasKey('letterSpacing', $heading);
        $this->assertArrayNotHasKey('letterSpacing', $paragraph);

        $this->assertSame(1, $this->controlCount($html, 'typography.lineHeight'), 'line height is heading-only');
        $this->assertSame(0, $this->controlCount($html, 'typography.letterSpacing'), 'letter spacing is declared by neither');
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
