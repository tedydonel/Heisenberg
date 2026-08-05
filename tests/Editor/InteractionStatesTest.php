<?php

declare(strict_types=1);

namespace Heisenberg\Tests\Editor;

use Heisenberg\Services\BlockContractValidator;
use Heisenberg\Services\BlockRegistryService;
use Heisenberg\Services\BlockRenderer;
use Heisenberg\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Interaction states, end to end (TODO 7.3).
 *
 * The renderer could always compile hover/active/focus from `supports.states` and was tested for
 * it — but `states` was absent from the contract schema so no block could declare it, nothing in
 * the editor ever wrote it, and stateStylesCss() was called only by PreviewController, so the
 * canvas never previewed one. The State tabs were inert decoration.
 *
 * The load-bearing property here is that the editor writes the SAME shape the renderer compiles.
 * If those two drift, an override authored in the inspector round-trips through the database and
 * then renders nothing on the public page — the exact silent failure this phase exists to remove.
 */
class InteractionStatesTest extends TestCase
{
    use RefreshDatabase;

    private function editorHtml(): string
    {
        return $this->get('/editor')->getContent();
    }

    public function test_the_validator_and_renderer_agree_on_which_states_exist(): void
    {
        // A state the contract accepts but the renderer cannot compile would validate and then
        // silently never emit any CSS.
        $this->assertSame(
            array_keys(BlockRenderer::INTERACTION_STATES),
            BlockContractValidator::INTERACTION_STATES,
        );
    }

    public function test_contracts_can_declare_states_and_both_shipped_ones_do(): void
    {
        foreach (['heisenberg/heading', 'heisenberg/paragraph'] as $name) {
            $supports = app(BlockRegistryService::class)->getBlock($name)['supports'] ?? [];
            $this->assertArrayHasKey('states', $supports, "{$name} must declare supports.states");

            foreach (BlockContractValidator::INTERACTION_STATES as $state) {
                $this->assertTrue($supports['states'][$state] ?? false, "{$name} should support {$state}");
            }
        }
    }

    public function test_an_unknown_state_is_rejected_by_the_validator(): void
    {
        $validator = new BlockContractValidator('heisenberg');
        $contract = json_decode(file_get_contents(__DIR__ . '/../../resources/blocks/heading/heading.json'), true);
        $contract['supports']['states']['disabled'] = true;

        $result = $validator->validate($contract);

        $this->assertFalse($result['valid']);
        $this->assertNotEmpty(array_filter(
            $result['errors'],
            static fn (string $e): bool => str_contains($e, "unknown state 'disabled'"),
        ));
    }

    public function test_the_editor_writes_the_exact_path_the_renderer_compiles(): void
    {
        $html = $this->editorHtml();

        // Editor side: a non-default State tab prefixes every supports path.
        $this->assertStringContainsString("return state === 'default' ? path : 'states.' + state + '.' + path;", $html);
        $this->assertStringContainsString("window.hbEditor.setSupport(id, hbStatePath(el.closest('.hb-blockstyle'), key), raw)", $html);

        // Renderer side: the same shape, proven by compiling one.
        $css = app(BlockRenderer::class)->stateStylesCss([[
            'id' => 'hb1',
            'name' => 'heisenberg/paragraph',
            'attributes' => ['content' => 'x'],
            'supports' => ['states' => ['hover' => ['color' => ['text' => '#ff0000']]]],
            'innerBlocks' => [],
        ]]);

        $this->assertStringContainsString('[data-block-id="hb1"]:hover', $css);
        $this->assertStringContainsString('--hb-paragraph-color: #ff0000 !important', $css);
    }

    public function test_controls_read_their_own_override_not_the_base_value(): void
    {
        $html = $this->editorHtml();

        // Without this every state tab opens showing the default and overwrites it on first edit.
        $this->assertStringContainsString(
            "? hbGet(source, hbStatePath(mountedStyleRoot(el) || el.closest('.hb-blockstyle'), key))",
            $html,
        );
    }

    public function test_switching_state_retargets_the_panel_and_previews_on_canvas(): void
    {
        $html = $this->editorHtml();

        $this->assertStringContainsString('data-hb-style-state', $html);
        $this->assertStringContainsString("root.dataset.hbStyleState = event.detail.value || 'default'", $html);
        $this->assertStringContainsString('window.hbEditor.previewState?.(id, root.dataset.hbStyleState)', $html);
    }

    public function test_the_canvas_can_force_a_state_and_exposes_it_on_the_runtime_api(): void
    {
        $html = $this->editorHtml();

        $this->assertStringContainsString('function previewState(id, state)', $html);
        $this->assertStringContainsString('previewState: previewState,', $html);

        // Merges supports.states.<state> over the base, and rides the same
        // .hb-state-preview-<state> hook the renderer emits for the public page.
        $this->assertStringContainsString("dataGet(model.supports || {}, 'states.' + state)", $html);
        $this->assertStringContainsString("el.classList.add('hb-state-preview-' + next)", $html);
    }

    public function test_toolbar_hides_select_parent_and_save_for_non_container_blocks(): void
    {
        $html = $this->editorHtml();

        // TODO 7.8 — both were rendered unconditionally and inert. select-parent is only
        // meaningful for a block that HAS a parent; save-as-reusable-block only for a container.
        $this->assertStringContainsString("show(tb.querySelector('[data-tb-action=\"select-parent\"]'), parentIdOf(model.id) !== null)", $html);
        $this->assertStringContainsString("show(tb.querySelector('[data-tb-action=\"save\"]'), !!(c.innerBlocks && c.innerBlocks.enabled))", $html);

        // Neither shipped contract is a container, so both stay hidden today.
        foreach (['heisenberg/heading', 'heisenberg/paragraph'] as $name) {
            $contract = app(BlockRegistryService::class)->getBlock($name);
            $this->assertFalse($contract['innerBlocks']['enabled'] ?? false, "{$name} is not a container");
        }
    }

    public function test_parent_lookup_walks_the_tree_rather_than_assuming_a_flat_document(): void
    {
        $html = $this->editorHtml();

        // The document IS flat today, so a hardcoded `false` would behave identically — and would
        // have to be found and corrected once containers exist. This walks innerBlocks instead,
        // so the gate is right now and stays right later.
        $this->assertStringContainsString('function parentIdOf(id, list, parent)', $html);
        $this->assertStringContainsString('const found = parentIdOf(id, inner, blocks[i].id);', $html);
        $this->assertStringContainsString('parentIdOf: function (id) { return parentIdOf(id); }', $html);
    }

    public function test_only_supports_sourced_variables_can_be_state_overridden(): void
    {
        $html = $this->editorHtml();

        // Matches stateDeclarations(), which skips any variable not sourced from `supports.` —
        // so the canvas cannot preview something the renderer would refuse to emit.
        $this->assertStringContainsString("if (source.indexOf('supports.') === 0) {", $html);
        $this->assertStringContainsString('if (overrides) value = dataGet(overrides, source.slice(9));', $html);

        // An attributes-sourced variable stays base-only server-side.
        $css = app(BlockRenderer::class)->stateStylesCss([[
            'id' => 'hb2',
            'name' => 'heisenberg/paragraph',
            'attributes' => ['content' => 'x'],
            'supports' => ['states' => ['hover' => ['dropCap' => true]]],
            'innerBlocks' => [],
        ]]);

        $this->assertSame('', $css);
    }
}
