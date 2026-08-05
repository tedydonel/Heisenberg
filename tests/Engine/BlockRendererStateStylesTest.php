<?php

declare(strict_types=1);

namespace Heisenberg\Tests\Engine;

use Heisenberg\Services\BlockContractValidator;
use Heisenberg\Services\BlockRegistryService;
use Heisenberg\Services\BlockRenderer;
use Heisenberg\Tests\TestCase;

/**
 * Interaction-state CSS (supports.states.hover/active/focus) — the
 * per-instance stylesheet channel. Values re-use the contract's own
 * style-variable sanitizers; anything invalid is dropped, never emitted.
 */
class BlockRendererStateStylesTest extends TestCase
{
    private function renderer(): BlockRenderer
    {
        return new BlockRenderer(new BlockRegistryService(new BlockContractValidator('heisenberg')));
    }

    private function paragraph(array $states): array
    {
        return [
            'id' => 'hb7', 'name' => 'heisenberg/paragraph',
            'attributes' => ['content' => 'Hello'],
            'supports' => ['states' => $states],
            'innerBlocks' => [],
        ];
    }

    public function test_hover_override_emits_a_scoped_important_rule_with_the_preview_alias(): void
    {
        $css = $this->renderer()->stateStylesCss([$this->paragraph([
            'hover' => ['color' => ['text' => '#ff0000'], 'typography' => ['fontSize' => '18px']],
        ])]);

        $this->assertStringContainsString('[data-block-id="hb7"]:hover', $css);
        $this->assertStringContainsString('[data-block-id="hb7"].hb-state-preview-hover', $css);
        $this->assertStringContainsString('--hb-paragraph-color: #ff0000 !important', $css);
        $this->assertStringContainsString('--hb-paragraph-fs: 18px !important', $css);
    }

    public function test_active_and_focus_states_scope_to_their_own_pseudo_classes(): void
    {
        $css = $this->renderer()->stateStylesCss([$this->paragraph([
            'active' => ['color' => ['background' => 'var(--hb-t-accent-1)']],
            'focus' => ['border' => ['color' => '#00ff00']],
        ])]);

        $this->assertStringContainsString('[data-block-id="hb7"]:active', $css);
        $this->assertStringContainsString('--hb-paragraph-bg: var(--hb-t-accent-1) !important', $css);
        $this->assertStringContainsString('[data-block-id="hb7"]:focus-within', $css);
        $this->assertStringContainsString('--hb-paragraph-bc: #00ff00 !important', $css);
    }

    public function test_invalid_values_and_unsafe_ids_are_dropped_fail_closed(): void
    {
        $renderer = $this->renderer();

        $poison = $renderer->stateStylesCss([$this->paragraph([
            'hover' => ['color' => ['text' => 'red; } body { display: none']],
        ])]);
        $this->assertSame('', $poison);

        $badId = $this->paragraph(['hover' => ['color' => ['text' => '#ff0000']]]);
        $badId['id'] = '"]{}<script>';
        $this->assertSame('', $renderer->stateStylesCss([$badId]));

        $unknownState = $renderer->stateStylesCss([$this->paragraph([
            'levitating' => ['color' => ['text' => '#ff0000']],
        ])]);
        $this->assertSame('', $unknownState);
    }

    public function test_blocks_without_states_emit_nothing(): void
    {
        $block = $this->paragraph([]);
        unset($block['supports']['states']);

        $this->assertSame('', $this->renderer()->stateStylesCss([$block]));
    }

    public function test_preview_page_carries_the_state_stylesheet(): void
    {
        $this->postJson('/editor/preview', [
            'title' => 'States',
            'blocks' => [$this->paragraph(['hover' => ['color' => ['text' => '#ff0000']]])],
        ])->assertOk();

        $page = $this->get('/editor/preview');
        $page->assertOk();
        $page->assertSee('[data-block-id="hb7"]:hover', false);
        $page->assertSee('--hb-paragraph-color: #ff0000 !important', false);
    }
}
