<?php

declare(strict_types=1);

namespace Heisenberg\Tests\Editor;

use Heisenberg\Services\BlockRegistryService;
use Heisenberg\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Inspector → model wiring for the two sections that rendered but wrote nothing:
 * Style → Padding/Margin, and Content → General.
 *
 * Both were the same class of defect, and the reason it went unnoticed is worth pinning:
 * the contracts already declared the paths WITH matching style.variables, so the renderer
 * would have rendered them — only the panel was silent. A control that writes nowhere looks
 * identical to a working one until you reload the page. See docs/inspector-composition.md.
 *
 * RefreshDatabase: /editor reads the category table on every render (Phase 3.1's Categories
 * seed) — same note as EditorRendersTest and ColorPickerTest carry.
 */
class InspectorWiringTest extends TestCase
{
    use RefreshDatabase;

    private const SIDES = ['top', 'right', 'bottom', 'left'];

    private function editorHtml(): string
    {
        return $this->get('/editor')->getContent();
    }

    /** @return array<string, mixed> */
    private function contract(string $name): array
    {
        return app(BlockRegistryService::class)->getBlock($name) ?? [];
    }

    // ── Spacing ───────────────────────────────────────────────────────────────

    public function test_every_per_side_spacing_field_carries_a_supports_control_hook(): void
    {
        $html = $this->editorHtml();

        foreach (['padding', 'margin'] as $group) {
            foreach (self::SIDES as $side) {
                $this->assertStringContainsString(
                    'data-hb-control="spacing.' . $group . '.' . $side . '"',
                    $html,
                    "spacing.{$group}.{$side} has no control hook — the field writes nowhere",
                );
            }
        }

        // 8 paths — but the Block tab pre-renders one panel PER REGISTERED BLOCK TYPE and hides
        // all but the selected one (see inspector.blade.php's docblock), so the whole set repeats
        // per contract. Derived from the registry rather than hardcoded, or adding a block type
        // would fail this test for no real reason.
        $blockCount = count(app(BlockRegistryService::class)->registry()['blocks']);
        $this->assertGreaterThan(0, $blockCount);
        $this->assertSame(
            8 * $blockCount,
            substr_count($html, 'data-hb-control="spacing.'),
            'Expected the 8 per-side spacing controls once per registered block type',
        );
    }

    public function test_both_shipped_contracts_can_actually_render_what_the_panel_now_writes(): void
    {
        // The panel writing is only half of it: a supports path with no matching
        // contract.style.variable stores fine and renders nothing, forever.
        foreach (['heisenberg/heading', 'heisenberg/paragraph'] as $name) {
            $sources = array_column($this->contract($name)['style']['variables'] ?? [], 'source');

            foreach (['padding', 'margin'] as $group) {
                foreach (self::SIDES as $side) {
                    $this->assertContains(
                        "supports.spacing.{$group}.{$side}",
                        $sources,
                        "{$name} has no style variable sourced from supports.spacing.{$group}.{$side}",
                    );
                }
            }
        }
    }

    public function test_aggregate_spacing_modes_commit_through_a_single_object_write(): void
    {
        $html = $this->editorHtml();

        // "One value for all sides" and "Horizontal/Vertical" are aggregate views: one input
        // owning two or four model paths, which data-hb-control cannot express. They fan out
        // through commitSpacingGroup instead.
        $this->assertStringContainsString('function commitSpacingGroup(root, group)', $html);
        $this->assertStringContainsString("commitSpacingGroup(root, 'padding')", $html);
        $this->assertStringContainsString("commitSpacingGroup(root, 'margin')", $html);

        // One setSupport of the whole group object, not four scalar writes — setSupport
        // re-renders the block on every call, so four would rebuild the DOM four times per
        // keystroke while the user types.
        $this->assertStringContainsString("window.hbEditor.setSupport(id, 'spacing.' + group, {", $html);
    }

    public function test_aggregate_spacing_fields_are_re_derived_when_a_block_is_selected(): void
    {
        $html = $this->editorHtml();

        // They hold no model path, so syncControls (which walks [data-hb-control]) skips them —
        // without this a re-selected block shows real per-side values under a stale summary.
        $this->assertStringContainsString('function syncSpacingAggregates(root)', $html);
        $this->assertMatchesRegularExpression(
            '/syncSpacingAggregates\(root\);\s*\n\s*refreshConditionals\(root, model\);/',
            $html,
            'syncSpacingAggregates must run as part of syncControls',
        );
    }

    // ── Content → General ─────────────────────────────────────────────────────

    public function test_general_section_wires_the_three_contract_attributes_it_renders(): void
    {
        $html = $this->editorHtml();

        // Id / Title / Class were plain <x-ui.input value="" /> with no hooks, though both
        // contracts declare all three and consume them in render.template.
        foreach (['anchor', 'titleAttr'] as $key) {
            $this->assertMatchesRegularExpression(
                '/data-hb-control="' . $key . '"[^>]*data-hb-control-kind="attributes"/',
                $html,
                "General's {$key} field is not wired to the model",
            );
        }

        $this->assertMatchesRegularExpression(
            '/data-hb-control="extraClasses"[^>]*data-hb-control-kind="attributes"[^>]*data-hb-control-type="chips"/',
            $html,
            'extraClasses must be wired as a chips control',
        );
    }

    public function test_the_three_general_attributes_exist_on_both_contracts(): void
    {
        foreach (['heisenberg/heading', 'heisenberg/paragraph'] as $name) {
            $attributes = $this->contract($name)['attributes'] ?? [];

            foreach (['anchor', 'titleAttr', 'extraClasses'] as $key) {
                $this->assertArrayHasKey($key, $attributes, "{$name} does not declare {$key}");
                $this->assertSame(
                    'general',
                    $attributes[$key]['control']['section'] ?? null,
                    "{$name}'s {$key} is not a General-section control",
                );
            }
        }
    }

    public function test_chips_are_synced_before_the_generic_input_fallback(): void
    {
        $html = $this->editorHtml();

        // A chips host CONTAINS an <input> (its add-class field). If the generic branch ran
        // first it would fill that staging field with the whole space-separated class string.
        $chipsBranch = strpos($html, "if (type === 'chips') {");
        $genericBranch = strpos($html, "const input = el.matches('input, textarea')");

        $this->assertIsInt($chipsBranch);
        $this->assertIsInt($genericBranch);
        $this->assertLessThan(
            $genericBranch,
            $chipsBranch,
            'The chips branch must precede the generic input fallback in syncControls',
        );
    }

    public function test_the_generic_write_handler_bails_out_on_chips(): void
    {
        $html = $this->editorHtml();

        // Otherwise typing in the add-class field writes every keystroke into extraClasses.
        $this->assertStringContainsString("if (type === 'chips') return;", $html);

        // The only write path for chips.
        $this->assertStringContainsString('function writeChips(host, classes)', $html);
        $this->assertStringContainsString(
            "window.hbEditor.setAttribute(id, host.getAttribute('data-hb-control'), classes.join(' '))",
            $html,
        );
    }

    public function test_chips_render_from_the_shared_ui_chip_component(): void
    {
        $html = $this->editorHtml();

        // Cloned from a real x-ui.chip rather than hand-rolled in JS, so the markup can't
        // drift from the component.
        $this->assertStringContainsString('data-hb-chip-prototype', $html);
        $this->assertStringContainsString('prototype.cloneNode(true)', $html);
        $this->assertStringContainsString('data-hb-chip-input', $html);
        $this->assertStringContainsString('data-hb-chip-list', $html);
    }

    public function test_the_chip_prototype_is_not_a_template_so_ui_chips_once_assets_still_apply(): void
    {
        $html = $this->editorHtml();

        // ui/chip carries @once <style>/<script>, and its only other render on /editor is a
        // loop over a prop nothing passes — so a <template> prototype would be the FIRST chip
        // render on the page, putting those blocks inside template.content where browsers
        // neither apply the CSS nor run the script. Every chip would render unstyled. Same trap
        // ui/theme-preset-card hit (TODO 6.7).
        $this->assertStringNotContainsString('data-hb-chip-template', $html);

        // The component's own stylesheet must reach the page, and must not sit inside a
        // <template>. "Inside" means unbalanced open tags before it — the page has plenty of
        // unrelated, properly-closed templates, so counting opens alone proves nothing.
        $this->assertStringContainsString('.hb-chip__close', $html);
        $chipCss = strpos($html, '.hb-chip__close');
        $this->assertIsInt($chipCss);

        $prefix = substr($html, 0, $chipCss);
        $this->assertSame(
            substr_count($prefix, '</template>'),
            substr_count($prefix, '<template'),
            "ui/chip's @once assets are emitted inside an unclosed <template> — the CSS never applies",
        );
    }

    public function test_a_pasted_space_separated_class_string_becomes_separate_chips(): void
    {
        $html = $this->editorHtml();

        // Space is the model's separator, so one chip labelled "a b c" would silently re-split
        // into three on the next read — the chip list and the model would disagree.
        $this->assertStringContainsString('input.value.trim().split(/\s+/).filter(Boolean)', $html);
    }
}
