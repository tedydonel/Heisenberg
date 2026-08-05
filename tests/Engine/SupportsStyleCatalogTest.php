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

    public function test_every_always_on_rule_requires_both_the_data_block_id_hook_and_the_hb_supports_marker(): void
    {
        // The [data-block-id] hook alone is the SAME specificity as a working
        // block's own root rule — the extra hb-supports marker is what keeps
        // this sheet a true no-op for blocks that don't opt in.
        $css = SupportsStyle::css();

        $this->assertStringNotContainsString('[data-block-id] {', $css, 'no rule should scope to the bare hook without the hb-supports marker');
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
