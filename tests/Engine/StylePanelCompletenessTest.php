<?php

declare(strict_types=1);

namespace Heisenberg\Tests\Engine;

use Heisenberg\Blocks\StylePanelDeriver;
use Heisenberg\Rendering\CssValueSanitizer;
use Heisenberg\Services\BlockRegistryService;
use Heisenberg\Support\SupportsStyle;
use Heisenberg\Tests\TestCase;

/**
 * The Style tab's Appearance and Stroke sections are complete on every block.
 *
 * Appearance is opacity + corner radius, but each half was offered only where the contract
 * happened to declare it: columns/column showed corners without opacity, and paragraph,
 * heading, list, separator and embed showed opacity without corners. Stroke's Position
 * control existed in the markup from the start, hidden and never wired to anything; it now
 * writes `supports.border.position`, which SupportsStyle turns into `box-sizing`.
 */
class StylePanelCompletenessTest extends TestCase
{
    /** @return list<array<string, mixed>> */
    private function contracts(): array
    {
        return $this->app->make(BlockRegistryService::class)->discover()['blocks'];
    }

    public function test_every_block_offers_both_opacity_and_corner_radius(): void
    {
        foreach ($this->contracts() as $contract) {
            $name = (string) $contract['name'];
            $supports = $contract['supports'] ?? [];
            $variables = $contract['style']['variables'] ?? [];

            $this->assertTrue(($supports['appearance']['opacity'] ?? false) === true, "{$name}: no opacity");
            $this->assertArrayHasKey('--hb-opacity', $variables, "{$name}: opacity is offered but never reaches the CSS");

            $radius = $supports['border']['radius'] ?? null;
            $this->assertIsArray($radius, "{$name}: no corner radius");
            foreach (['tl' => 'topLeft', 'tr' => 'topRight', 'br' => 'bottomRight', 'bl' => 'bottomLeft'] as $suffix => $corner) {
                $this->assertTrue(($radius[$corner] ?? false) === true, "{$name}: no {$corner} radius");
                $this->assertSame(
                    "supports.border.radius.{$corner}",
                    $variables["--hb-border-radius-{$suffix}"]['source'] ?? null,
                    "{$name}: the {$corner} radius never reaches the CSS",
                );
            }
        }
    }

    public function test_every_block_with_a_stroke_offers_its_position(): void
    {
        $checked = 0;
        foreach ($this->contracts() as $contract) {
            if (! isset($contract['supports']['border']['width'])) {
                continue;
            }
            $name = (string) $contract['name'];
            $checked++;

            $this->assertTrue(($contract['supports']['border']['position'] ?? false) === true, "{$name}: stroke without a position");
            $this->assertSame(
                ['source' => 'supports.border.position', 'default' => '', 'sanitize' => 'box-sizing'],
                $contract['style']['variables']['--hb-box-sizing'] ?? null,
                "{$name}: the stroke position never reaches the CSS",
            );

            $panels = $this->app->make(StylePanelDeriver::class)->derivePanels($contract);
            $rows = array_merge(...array_column(
                array_filter($panels, static fn (array $p): bool => $p['key'] === 'borderStroke'),
                'controls',
            ));
            $sources = array_column($rows, 'source');
            $this->assertContains('supports.border.position', $sources, "{$name}: the Stroke panel omits Position");
        }

        $this->assertGreaterThan(0, $checked);
    }

    /**
     * A block that never picks a position must render exactly as its own CSS drew it, so the
     * rule is gated on the block declaring the variable rather than applied to every block.
     */
    public function test_box_sizing_applies_only_to_a_block_that_sets_a_stroke_position(): void
    {
        $css = SupportsStyle::css();

        $this->assertStringContainsString('[data-block-id].hb-supports[style*="--hb-box-sizing"] { box-sizing: var(--hb-box-sizing, border-box); }', $css);
        $this->assertSame(1, substr_count($css, 'box-sizing:'), 'box-sizing must not be part of the blanket capability rule');
    }

    /**
     * Effects offered one drop shadow and nothing else. Every block now takes a drop shadow
     * (box-shadow), layer blur (filter: blur()) and background blur (backdrop-filter).
     */
    public function test_every_block_offers_drop_shadow_layer_blur_and_background_blur(): void
    {
        $sources = [
            '--hb-shadow' => ['supports.effects.shadow', 'shadow'],
            '--hb-filter' => ['supports.effects.filter', 'filter'],
            '--hb-backdrop' => ['supports.effects.backdrop', 'filter'],
        ];

        foreach ($this->contracts() as $contract) {
            $name = (string) $contract['name'];
            $this->assertSame(['shadow' => true, 'filter' => true, 'backdrop' => true], $contract['supports']['effects'] ?? null, "{$name}: incomplete effects");
            foreach ($sources as $variable => [$source, $sanitize]) {
                $this->assertSame($source, $contract['style']['variables'][$variable]['source'] ?? null, "{$name}: {$variable} never reaches the CSS");
                $this->assertSame($sanitize, $contract['style']['variables'][$variable]['sanitize'] ?? null, "{$name}: {$variable} is not sanitized as {$sanitize}");
            }

            $effects = array_values(array_filter(
                $this->app->make(StylePanelDeriver::class)->derivePanels($contract),
                static fn (array $p): bool => $p['key'] === 'effects',
            ));
            $this->assertSame(
                ['supports.effects.shadow', 'supports.effects.filter', 'supports.effects.backdrop'],
                array_column($effects[0]['controls'] ?? [], 'source'),
                "{$name}: the Effects panel is incomplete",
            );
        }
    }

    /**
     * Zero specificity with a `none` default: a block's own `filter` (the button darkens on hover)
     * keeps working until the author sets one, and hover-state filters still apply.
     */
    public function test_blurs_apply_at_zero_specificity_with_a_no_op_default(): void
    {
        $css = SupportsStyle::css();

        $this->assertStringContainsString(':where([data-block-id].hb-supports) { filter: var(--hb-filter, none); -webkit-backdrop-filter: var(--hb-backdrop, none); backdrop-filter: var(--hb-backdrop, none); }', $css);
        $this->assertStringContainsString('--hb-filter: none', $css, 'a container filter must not cascade into its children');
        $this->assertStringContainsString('--hb-backdrop: none', $css);
    }

    /** Blur is the only filter function: the colour filters (brightness, grayscale…) were removed. */
    public function test_the_filter_sanitizer_accepts_only_blur(): void
    {
        $sanitizer = $this->app->make(CssValueSanitizer::class);

        foreach ([
            'none',
            'blur(4px)',
            'blur(2.5px)',
            'blur(0px)',
        ] as $safe) {
            $this->assertSame($safe, $sanitizer->sanitizeCssValue($safe, 'filter', ''), $safe);
        }

        foreach ([
            'url(#evil)',
            'url(https://example.com/x.svg#f)',
            'drop-shadow(0 0 4px red)',
            'blur(4px); color: red',
            'blur(4em)',
            'opacity(50%)',
            'blur(var(--x))',
            'blur(1px) , blur(2px)',
            'grayscale(100%)',
            'brightness(120%)',
            'contrast(80%)',
            'saturate(150%)',
            'sepia(40%)',
            'invert(100%)',
            'hue-rotate(90deg)',
            'blur(4px) grayscale(100%)',
        ] as $unsafe) {
            $this->assertSame('', $sanitizer->sanitizeCssValue($unsafe, 'filter', ''), $unsafe);
        }
    }

    public function test_the_position_sanitizer_accepts_only_inside_and_outside(): void
    {
        $sanitizer = $this->app->make(CssValueSanitizer::class);

        $this->assertSame('border-box', $sanitizer->sanitizeCssValue('border-box', 'box-sizing', ''));
        $this->assertSame('content-box', $sanitizer->sanitizeCssValue('content-box', 'box-sizing', ''));
        $this->assertSame('', $sanitizer->sanitizeCssValue('padding-box', 'box-sizing', ''));
        $this->assertSame('', $sanitizer->sanitizeCssValue('inherit; color: red', 'box-sizing', ''));
    }
}
