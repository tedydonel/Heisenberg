<?php

declare(strict_types=1);

namespace Heisenberg\Tests\Engine;

use Heisenberg\Support\SupportsStyle;
use Heisenberg\Tests\TestCase;

/**
 * Builder full-kit overhaul (Phase 1) — the generated shared "supports
 * capabilities" stylesheet ({@see SupportsStyle}), mirroring how
 * {@see \Heisenberg\Support\AnimationCatalog} is tested. Confirms every
 * capability rule is present with a safe, no-op default, and that the
 * dedicated route serves it.
 */
class SupportsStyleCatalogTest extends TestCase
{
    public function test_css_contains_the_appearance_typography_position_and_effects_rules_with_safe_defaults(): void
    {
        $css = SupportsStyle::css();

        $this->assertStringContainsString('[data-block-id].hb-supports', $css);
        $this->assertStringContainsString('opacity: var(--hb-opacity, 1)', $css);
        $this->assertStringContainsString('letter-spacing: var(--hb-letter-spacing, normal)', $css);
        $this->assertStringContainsString('text-align: var(--hb-text-align, initial)', $css);
        $this->assertStringContainsString('align-self: var(--hb-text-align-v, auto)', $css);
        $this->assertStringContainsString('position: var(--hb-position-mode, static)', $css);
        $this->assertStringContainsString('translate(var(--hb-tx, 0px), var(--hb-ty, 0px))', $css);
        $this->assertStringContainsString('rotate(var(--hb-rotate, 0deg))', $css);
        $this->assertStringContainsString('box-shadow: var(--hb-shadow, none)', $css);
        $this->assertStringContainsString('overflow: var(--hb-overflow, visible)', $css);
    }

    public function test_css_contains_per_side_border_rules_with_safe_defaults(): void
    {
        $css = SupportsStyle::css();

        foreach (['top', 'right', 'bottom', 'left'] as $side) {
            $this->assertStringContainsString("border-{$side}-width: var(--hb-border-{$side}-width, 0)", $css);
            $this->assertStringContainsString("border-{$side}-style: var(--hb-border-{$side}-style, none)", $css);
            $this->assertStringContainsString("border-{$side}-color: var(--hb-border-{$side}-color, transparent)", $css);
        }
    }

    public function test_css_contains_per_corner_radius_rules_with_safe_defaults(): void
    {
        // The corner half of the `border` support group (Appearance's four corner fields write
        // supports.border.radius.*). Until 2026-08-07 nothing in SupportsStyle read a radius at
        // all, so those fields wrote a value no stylesheet consumed — a rendered, dead control.
        $css = SupportsStyle::css();

        foreach ([
            'tl' => 'top-left',
            'tr' => 'top-right',
            'br' => 'bottom-right',
            'bl' => 'bottom-left',
        ] as $suffix => $corner) {
            $this->assertStringContainsString("border-{$corner}-radius: var(--hb-border-radius-{$suffix}, 0)", $css);
        }

        // Longhands, never the shorthand: `border-radius` takes all four corners at once, so it
        // could not leave an unset corner alone.
        $this->assertStringNotContainsString('border-radius: var(', $css);
    }

    public function test_the_radius_variables_do_not_share_the_editor_chrome_radius_token_prefix(): void
    {
        // resources/css/tokens.css declares --hb-radius-{xs,sm,swatch,md,control,lg} on :root, and
        // :root properties inherit into every block root in the canvas. A block capability named
        // --hb-radius-* would sit one token name away from colliding with the design system, so
        // the corner vars stay under the --hb-border- prefix that owns the rest of the group.
        $css = SupportsStyle::css();

        $this->assertStringNotContainsString('var(--hb-radius-', $css);
        $this->assertStringContainsString('var(--hb-border-radius-tl,', $css);
    }

    public function test_css_contains_the_flex_layout_rule_gated_behind_its_own_class(): void
    {
        $css = SupportsStyle::css();

        $this->assertStringContainsString('.hb-flex-layout', $css);
        $this->assertStringContainsString('display: flex', $css);
        $this->assertStringContainsString('flex-direction: var(--hb-flex-direction, row)', $css);
        $this->assertStringContainsString('justify-content: var(--hb-flex-justify, flex-start)', $css);
        $this->assertStringContainsString('align-items: var(--hb-flex-align, stretch)', $css);
        $this->assertStringContainsString('gap: var(--hb-flex-gap, 0)', $css);
        $this->assertStringContainsString('padding: var(--hb-flex-padding, 0)', $css);
    }

    public function test_css_contains_size_utility_classes_for_fill_hug_and_clip(): void
    {
        $css = SupportsStyle::css();

        $this->assertStringContainsString('.hb-size-fill-w { width: 100%; }', $css);
        $this->assertStringContainsString('.hb-size-fill-h { height: 100%; }', $css);
        $this->assertStringContainsString('.hb-size-hug-w { width: fit-content;', $css);
        $this->assertStringContainsString('.hb-size-hug-h { height: fit-content; }', $css);
        $this->assertStringContainsString('.hb-size-clip { overflow: hidden; }', $css);
    }

    public function test_css_contains_the_align_wide_and_full_breakout_rules(): void
    {
        $css = SupportsStyle::css();

        $this->assertStringContainsString('.hb-align-wide', $css);
        $this->assertStringContainsString('.hb-align-full', $css);
        $this->assertStringContainsString('width: 100vw', $css);
    }

    public function test_css_contains_the_align_left_center_right_placement_rules(): void
    {
        // Block PLACEMENT via margins, not text-align — text alignment is Typography's
        // --hb-text-align variable.
        $css = SupportsStyle::css();

        $this->assertStringContainsString('.hb-align-left { margin-left: 0; margin-right: auto; }', $css);
        $this->assertStringContainsString('.hb-align-center { margin-left: auto; margin-right: auto; }', $css);
        $this->assertStringContainsString('.hb-align-right { margin-left: auto; margin-right: 0; }', $css);
    }

    public function test_every_always_on_rule_requires_both_the_data_block_id_hook_and_the_hb_supports_marker(): void
    {
        // The [data-block-id] hook alone is the SAME specificity as a working
        // block's own root rule — the extra hb-supports marker is what keeps
        // this sheet a true no-op for blocks that don't opt in.
        //
        // ONE rule is allowed to use the bare hook: the inheritance reset (2026-08-07),
        // which declares nothing but custom properties. Custom properties inherit, so a
        // container's border/shadow/flex values reached every nested child that didn't set
        // its own; declaring them per block root is what stops that. It stays visually inert
        // (no real CSS property is set) and deliberately sits BELOW the capability rules'
        // specificity so inline styles and hover-state overrides both still win.
        $css = SupportsStyle::css();

        foreach (explode("\n", $css) as $line) {
            if (! str_starts_with(trim($line), '[data-block-id] {')) {
                continue;
            }

            preg_match('/\{(.*)\}/', $line, $m);
            foreach (array_filter(array_map('trim', explode(';', $m[1] ?? ''))) as $declaration) {
                $this->assertStringStartsWith(
                    '--',
                    $declaration,
                    'a bare-hook rule may only declare custom properties, never a visual one',
                );
            }
        }
    }

    public function test_capability_variables_are_reset_so_a_container_never_bleeds_onto_its_children(): void
    {
        // REGRESSION: custom properties inherit. A group with a 4px border drew that border
        // around every block inside it, because each child is also a block root reading
        // `border-top-width: var(--hb-border-top-width, 0)` and inherited the parent's value.
        $css = SupportsStyle::css();

        foreach ([
            '--hb-border-top-width', '--hb-border-left-color', '--hb-border-radius-tl',
            '--hb-shadow', '--hb-opacity', '--hb-rotate', '--hb-flex-gap', '--hb-overflow',
        ] as $var) {
            $this->assertMatchesRegularExpression(
                '/\[data-block-id\] \{[^}]*' . preg_quote($var, '/') . '\s*:/',
                $css,
                "{$var} must be neutralised on every block root",
            );
        }

        // text-align and letter-spacing are inherited properties by nature: a container
        // setting them SHOULD reach its text. They must NOT be in the reset.
        preg_match('/\[data-block-id\] \{([^}]*)\}/', $css, $m);
        $this->assertStringNotContainsString('--hb-text-align:', $m[1] ?? '');
        $this->assertStringNotContainsString('--hb-letter-spacing:', $m[1] ?? '');
    }

    public function test_stylesheet_route_serves_the_catalog(): void
    {
        $response = $this->get('/heisenberg-assets/editor-supports.css');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/css; charset=UTF-8');
        $this->assertStringContainsString('--hb-opacity', $response->getContent());
        $this->assertStringContainsString('hb-flex-layout', $response->getContent());
    }
}
