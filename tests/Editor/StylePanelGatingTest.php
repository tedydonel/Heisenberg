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
            'border.color',
            'border.radius.topLeft',
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

    public function test_appearance_shows_for_border_radius_but_its_opacity_field_gates_separately(): void
    {
        $html = $this->editorHtml();

        // The section straddles two groups: opacity -> appearance, corners -> border.radius.
        // Neither contract declares `appearance`; both fully declare `border.radius`.
        foreach (['heisenberg/heading', 'heisenberg/paragraph'] as $name) {
            $supports = app(BlockRegistryService::class)->getBlock($name)['supports'] ?? [];
            $this->assertArrayNotHasKey('appearance', $supports);
            $this->assertArrayHasKey('radius', $supports['border'] ?? []);
        }

        // Section renders (for the corners)...
        $this->assertStringContainsString('<span class="hb-section__title">Appearance</span>', $html);
        $this->assertSame($this->blockCount(), $this->controlCount($html, 'border.radius.topLeft'));
        // ...but the opacity field does not, because `appearance` is undeclared.
        $this->assertSame(0, $this->controlCount($html, 'appearance.opacity'));
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

        // Fill/Stroke/Appearance all open the colour picker, and all three render today.
        $this->assertStringContainsString('data-hb-style-popup="color"', $html);
        // Effects is the effect editor's only trigger, and Effects is gated away.
        $this->assertStringNotContainsString('data-hb-style-popup="effect"', $html);
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
