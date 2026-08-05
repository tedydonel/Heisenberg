<?php

declare(strict_types=1);

namespace Heisenberg\Tests\Engine;

use Heisenberg\Services\BlockContractValidator;
use Heisenberg\Services\BlockRegistryService;
use Heisenberg\Services\BlockRenderer;
use Heisenberg\Support\AnimationCatalog;
use Heisenberg\Tests\TestCase;

/**
 * supports.animation — the registry expands the capability (attributes,
 * controls, classNames, variables, runtime data attrs) from the shared
 * AnimationCatalog; the renderer materializes enum-gated classes + vars.
 */
class AnimationCapabilityTest extends TestCase
{
    private function registry(): BlockRegistryService
    {
        return new BlockRegistryService(new BlockContractValidator('heisenberg'));
    }

    public function test_catalog_css_compiles_keyframes_triggers_and_a11y(): void
    {
        $css = AnimationCatalog::css();

        $this->assertStringContainsString('@keyframes hb-fade-up', $css);
        $this->assertStringContainsString('@keyframes hb-flip-3d-y', $css);
        $this->assertStringContainsString('.hb-anim-fade-up.hb-anim-play { animation: hb-fade-up calc(var(--hb-anim-dur, 600) * 1ms)', $css);
        $this->assertStringContainsString('.hb-anim-fade-up.hb-anim-wait { opacity: 0; }', $css);
        // Attention presets loop and never hide while waiting.
        $this->assertStringContainsString('both infinite', $css);
        $this->assertStringNotContainsString('.hb-anim-pulse.hb-anim-wait', $css);
        $this->assertStringContainsString('prefers-reduced-motion', $css);
        $this->assertStringContainsString('.hb-ease-spring { --hb-anim-ease: cubic-bezier(0.34, 1.56, 0.64, 1); }', $css);
        $this->assertGreaterThan(30, count(AnimationCatalog::keys()), 'the catalog should be a broad library');
    }

    public function test_registry_expands_the_capability_into_the_paragraph_contract(): void
    {
        $contract = $this->registry()->getBlock('heisenberg/paragraph');

        $this->assertNotNull($contract);
        $this->assertTrue($contract['supports']['animation']);
        $this->assertArrayHasKey('animate', $contract['attributes']);
        $this->assertArrayHasKey('animateEasing', $contract['attributes']);
        $this->assertArrayHasKey('animateOnce', $contract['attributes']);
        $this->assertContains('flip-3d-x', $contract['attributes']['animate']['enum']);

        $classes = array_column($contract['style']['classNames'], 'class');
        $this->assertContains('hb-anim-bounce-in', $classes);
        $this->assertContains('hb-ease-spring', $classes);

        $this->assertSame('attributes.animateDuration', $contract['style']['variables']['--hb-anim-dur']['source']);
        $this->assertSame(['value' => '{{attributes.animate}}', 'omitWhenEmpty' => true], $contract['render']['template']['attributes']['data-hb-anim']);
    }

    public function test_inspector_controls_derive_with_groups_and_conditions(): void
    {
        $registry = $this->registry()->registry();
        $paragraph = null;
        foreach ($registry['blocks'] as $block) {
            if ($block['name'] === 'heisenberg/paragraph') {
                $paragraph = $block;
            }
        }

        $animate = collect($paragraph['controls'])->firstWhere('attribute', 'animate');
        $this->assertSame('select', $animate['type']);
        $this->assertSame('animation', $animate['section']);
        $this->assertSame('Animation', $animate['label']);
        $groups = array_values(array_unique(array_filter(array_column($animate['options'], 'group'))));
        $this->assertContains('3D', $groups);
        $this->assertContains('Attention', $groups);

        $easing = collect($paragraph['controls'])->firstWhere('attribute', 'animateEasing');
        $this->assertSame(['attribute' => 'animate', 'in' => AnimationCatalog::keys()], $easing['showWhen']);
    }

    public function test_renderer_materializes_animation_classes_vars_and_data_attrs(): void
    {
        $renderer = new BlockRenderer($this->registry());
        $html = $renderer->renderBlock([
            'id' => 'a1', 'name' => 'heisenberg/paragraph',
            'attributes' => [
                'content' => 'Animated', 'animate' => 'flip-3d-y',
                'animateDuration' => 900, 'animateDelay' => 150,
                'animateEasing' => 'spring', 'animateOnce' => true,
            ],
            'supports' => [], 'innerBlocks' => [],
        ], 'en');

        $this->assertStringContainsString('hb-anim-flip-3d-y', $html);
        $this->assertStringContainsString('hb-ease-spring', $html);
        $this->assertStringContainsString('data-hb-anim="flip-3d-y"', $html);
        $this->assertStringContainsString('data-hb-anim-once', $html);
        $this->assertStringContainsString('--hb-anim-dur: 900', $html);
        $this->assertStringContainsString('--hb-anim-delay: 150', $html);
    }

    public function test_unknown_preset_values_never_reach_the_markup(): void
    {
        $renderer = new BlockRenderer($this->registry());
        $html = $renderer->renderBlock([
            'id' => 'a2', 'name' => 'heisenberg/paragraph',
            'attributes' => ['content' => 'Nope', 'animate' => 'evil"><script>'],
            'supports' => [], 'innerBlocks' => [],
        ], 'en');

        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('hb-anim-evil', $html);
        // The runtime data attribute renders escaped and harmless; no
        // catalog class binds to it, so nothing animates.
        $this->assertStringNotContainsString('class="hb-block hb-block-paragraph hb-anim', $html);
    }

    public function test_animations_stylesheet_route_serves_the_catalog(): void
    {
        $response = $this->get('/heisenberg-assets/editor-animations.css');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/css; charset=UTF-8');
        $this->assertStringContainsString('@keyframes hb-zoom', $response->getContent());
    }
}
