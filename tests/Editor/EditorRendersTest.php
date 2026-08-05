<?php

declare(strict_types=1);

namespace Heisenberg\Tests\Editor;

use Heisenberg\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Smoke coverage for the editor surface. The editor had zero test coverage while
 * four agents edited its view tree concurrently; these assertions exist so a Blade
 * or wiring regression fails here instead of in a browser.
 *
 * RefreshDatabase (2026-08-04): EditorController::index()/show() now read the category
 * table on every render (Categories combobox seed, Phase 3.1) — the blank /editor page
 * genuinely depends on that table existing now, same as tests/Editor/EditorSaveWiringTest.php
 * and PostPersistenceTest.php already needed it for the posts/blocks tables.
 */
class EditorRendersTest extends TestCase
{
    use RefreshDatabase;

    public function test_editor_page_renders(): void
    {
        $this->get('/editor')->assertOk();
    }

    public function test_component_showcase_renders(): void
    {
        $this->get('/editor/components')->assertOk();
    }

    public function test_media_demo_renders(): void
    {
        $this->get('/editor/media')->assertOk();
    }

    public function test_editor_ships_the_runtime_api_and_csrf_token(): void
    {
        $html = $this->get('/editor')->getContent();

        // The public runtime API other editor components build against.
        $this->assertStringContainsString('window.hbEditor', $html);
        // Required before any POST/PUT from the editor can succeed (media upload).
        $this->assertStringContainsString('name="csrf-token"', $html);
    }

    public function test_client_registry_ships_contract_version_for_schema_version(): void
    {
        // BlocksPayloadService requires every block instance to carry a schemaVersion equal to
        // its contract's version, and the client runtime stamps it from this field — so if the
        // key ever stops being shipped, every save payload silently becomes invalid. Asserted
        // against the payload builder rather than the rendered HTML, since @json hex-escapes.
        $blocks = \Heisenberg\Support\BlockViewData::clientBlocks(
            app(\Heisenberg\Services\BlockRegistryService::class),
            ['heisenberg/paragraph', 'heisenberg/heading'],
        );

        $this->assertNotSame([], $blocks);

        foreach ($blocks as $name => $block) {
            $this->assertArrayHasKey('version', $block, "{$name} must ship a contract version");
            $this->assertNotNull($block['version'], "{$name}'s version must not be null");
        }
    }

    public function test_inspector_block_tab_renders_contract_driven_panels(): void
    {
        $html = $this->get('/editor')->getContent();

        // The Block tab body was a permanently empty <div>; it must now carry a panel group per
        // enabled block, rendered from that block's own contract metadata.
        $this->assertStringContainsString('data-hb-block-panel="heisenberg/heading"', $html);
        $this->assertStringContainsString('data-hb-block-panel="heisenberg/paragraph"', $html);
    }

    public function test_block_tab_uses_the_existing_pencil_components(): void
    {
        $html = $this->get('/editor')->getContent();

        // These are the 1:1-from-Pencil components in live/block/**, also showcased at
        // /editor/components. The Block tab must MOUNT them, not re-implement the UI around
        // them — an earlier pass rewrote them and lost the design fidelity. Their root classes
        // are the cheapest proof the real components are what render.
        $this->assertStringContainsString('hb-blockcontent', $html);
        $this->assertStringContainsString('hb-blockstyle', $html);
        $this->assertStringContainsString('hb-blockadvanced', $html);
    }

    public function test_style_panel_mounts_only_the_sections_the_contract_supports(): void
    {
        $html = $this->get('/editor')->getContent();

        // SUPERSEDES test_style_panel_keeps_the_complete_pencil_section_stack_mounted (removed
        // 2026-08-05), which pinned the opposite rule: "mounting a selected block must not remove
        // designed sections". That treated the .pen composition as a per-block contract when it is
        // really the design's full vocabulary. The inspector loads only what the active block
        // needs, so a section whose supports group the contract never declares must not render —
        // it would write into the model and change nothing on the canvas, which is
        // indistinguishable from a working control until you reload.
        //
        // Both shipped contracts declare: align, color, typography, size, spacing, animation.
        // Neither declares: position, layout, effects, appearance, border.
        //
        // `border` was removed from both 2026-08-05 (TODO 7.2): text blocks do not support
        // borders or corner radius. That drops Stroke, and Appearance with it — radius was the
        // only thing keeping Appearance alive, since neither contract declares `appearance`.
        // Matched on ui/panel-section's own title span, not a bare '>Name<' — Stroke renders its
        // own "Position" label (inside/center/outside), which a loose match reads as the Position
        // SECTION still being mounted.
        $header = fn (string $section): string => '<span class="hb-section__title">' . $section . '</span>';

        // Position and Effects joined as of TODO 7.1, once their controls were actually wired.
        // Appearance is here for `appearance.opacity`, not corner radius — text has no border.
        foreach (['State', 'Alignment', 'Typography', 'Dimensions', 'Fill', 'Appearance', 'Position', 'Effects'] as $section) {
            $this->assertStringContainsString($header($section), $html);
        }
        // Flex Layout gates on being a CONTAINER, not on supports.layout — a flex container lays
        // out children, and neither text block can have any (innerBlocks.enabled false).
        foreach (['Flex Layout', 'Stroke'] as $section) {
            $this->assertStringNotContainsString(
                $header($section),
                $html,
                "'{$section}' has no supports group in either shipped contract and must not render",
            );
        }

        // Section-specific glyphs must resolve rather than falling back to generic arrows. Numeric
        // Style fields intentionally do not import arbitrary prefix/unit/caret chrome.
        $this->assertStringContainsString('data-icon-name="format_align_right"', $html);
        $this->assertStringContainsString('data-icon-name="gear-six"', $html);
        // `arrows-horizontal` is the Flex Layout gap field's icon and is gated away with it —
        // it appears nowhere else in the editor.
        $this->assertStringNotContainsString('data-icon-name="arrows-horizontal"', $html);
        $this->assertStringContainsString('data-icon-name="style-padding-left"', $html);
        // The corner-radius glyphs belong to Appearance, which goes with `border` (TODO 7.2).
        $this->assertStringNotContainsString('data-icon-name="style-corner-radius-top-left"', $html);
        $this->assertStringContainsString('data-icon-name="align-left-fill"', $html);
        $this->assertStringContainsString('data-hb-style-layer-template="fill"', $html);
        // Stroke's layer template goes with the Stroke section.
        $this->assertStringNotContainsString('data-hb-style-layer-template="stroke"', $html);
        // Fill still triggers the colour picker, so the popup stays mounted.
        $this->assertStringContainsString('data-hb-style-popup="color"', $html);
        $this->assertStringContainsString('data-cp-gradient-add', $html);
        $this->assertStringContainsString('data-cp-gradient-reverse', $html);
        $this->assertStringContainsString("document.addEventListener('gradientchange'", $html);
        $this->assertStringContainsString('data-hb-style-padding-mode="one"', $html);
        $this->assertStringContainsString('data-hb-style-padding-mode="two"', $html);
        $this->assertStringContainsString('data-hb-style-padding-mode="four"', $html);
        $this->assertStringContainsString('setPaddingAxisValue(root, paddingAxis.dataset.hbStylePaddingAxis, event.target.value)', $html);
    }

    public function test_style_specific_and_alignment_fill_icons_resolve_to_non_empty_svg_paths(): void
    {
        $icons = [
            'style-corner-radius-all',
            'style-corner-radius-top-left',
            'style-corner-radius-top-right',
            'style-corner-radius-bottom-left',
            'style-corner-radius-bottom-right',
            'style-padding-all',
            'style-padding-horizontal',
            'style-padding-vertical',
            'style-padding-left',
            'style-padding-right',
            'style-padding-top',
            'style-padding-bottom',
            'align-left-fill',
            'align-center-horizontal-fill',
            'align-right-fill',
            'align-top-fill',
            'align-center-vertical-fill',
            'align-bottom-fill',
        ];

        foreach ($icons as $icon) {
            $this->assertSame($icon, \Heisenberg\Editor\EditorIcon::resolveSlug($icon), "{$icon} must resolve to its vendored SVG");
            $this->assertStringNotContainsString('<path d=""/>', \Heisenberg\Editor\EditorIcon::svg($icon), "{$icon} must not render as an empty SVG");
        }
    }

    public function test_inspector_scrolls_per_sub_tab_not_as_one_block_tab(): void
    {
        $html = $this->get('/editor')->getContent();

        // Every other scrollable surface (left panels, canvas) uses ui/custom-scrollbar; the right
        // sidebar was the only one left on a native scrollbar, and its Post tab had no scroller at
        // all. Each of the three Block sub-tabs is its own scroll region — a single scroller on the
        // Block tab would carry the Style tab's scroll offset over to Content.
        $this->assertStringContainsString('data-hb-scroll-container="[data-hb-subpanel-content]"', $html);
        $this->assertStringContainsString('data-hb-scroll-container="[data-hb-subpanel-style]"', $html);
        $this->assertStringContainsString('data-hb-scroll-container="[data-hb-subpanel-advanced]"', $html);
        $this->assertStringContainsString('data-hb-scroll-container="[data-hb-inspector-post-body]"', $html);
    }

    public function test_post_body_rows_cannot_shrink_when_a_disclosure_opens_or_closes(): void
    {
        $html = $this->get('/editor')->getContent();

        // The Post stack uses compact 32px rows, separated only by their top edge. Summary is a
        // disclosure too, so it needs the same top separator as the navigation rows.
        $this->assertMatchesRegularExpression('/\.hb-inspector__post-body\s*>\s*\*\s*\{[^}]*flex:\s*none/', $html);
        $this->assertStringContainsString('height:32px;padding:0 var(--hb-space-3, 12px);', $html);
        $this->assertMatchesRegularExpression('/\.hb-disclosure--border\s*\{[^}]*border-top:\s*1px solid/', $html);
        $this->assertStringNotContainsString('.hb-disclosure--border { border-bottom:', $html);
        $this->assertMatchesRegularExpression('/class="hb-disclosure hb-disclosure--border"[^>]*>.*?<span class="hb-disclosure__label">Featured image</s', $html);
        $this->assertMatchesRegularExpression('/class="hb-disclosure hb-disclosure--border"[^>]*>.*?<span class="hb-disclosure__label">Summary</s', $html);
    }

    public function test_fixed_height_chrome_rows_do_not_shrink(): void
    {
        $html = $this->get('/editor')->getContent();

        // Both tab strips declare a fixed height, so both must opt out of flex shrinking — inside a
        // clamped flex column the default flex-shrink: 1 squeezes them by however tall the panel
        // below happens to be, so the row's height changed as you switched sub-tabs.
        $this->assertMatchesRegularExpression('/\.hb-subtabs\s*\{[^}]*flex:\s*none/', $html);
        $this->assertMatchesRegularExpression('/\.hb-paneltabs\s*\{[^}]*flex:\s*none/', $html);
    }

    public function test_runtime_exposes_both_model_write_paths(): void
    {
        $html = $this->get('/editor')->getContent();

        // Attribute-keyed and supports-keyed writes each need a runtime setter that owns its own
        // re-render and events. If either disappears, the inspector starts mutating models directly.
        $this->assertStringContainsString('setAttribute: setAttribute', $html);
        $this->assertStringContainsString('setSupport: setSupport', $html);
    }

    public function test_editor_has_no_alpine_directives(): void
    {
        $html = $this->get('/editor')->getContent();

        // Alpine is not loaded on this page, so any x-data/x-show is dead on arrival.
        // panel-section shipped with them once; this stops that regressing. Matched as attribute
        // syntax, not bare substrings — the CSS comment explaining the fix mentions x-show by name.
        $this->assertStringNotContainsString('x-data="', $html);
        $this->assertStringNotContainsString('x-show="', $html);
        $this->assertStringNotContainsString('x-on:click="', $html);
    }

    public function test_drag_and_drop_surfaces_are_wired(): void
    {
        $html = $this->get('/editor')->getContent();

        $this->assertStringContainsString('wireCanvasBlockDrag', $html);
        $this->assertStringContainsString('wirePaletteDrag', $html);
        // The dead event the canvas used to dispatch must be gone.
        $this->assertStringNotContainsString('hb:insert-block', $html);
    }

    public function test_components_tab_only_lists_cards_for_registered_blocks(): void
    {
        $html = $this->get('/editor')->getContent();

        // Cards are derived straight from the registry now (2026-08-02) — every
        // discovered contract gets one, heading and paragraph both included.
        $this->assertStringContainsString('data-hb-insert-block="heisenberg/heading"', $html);
        $this->assertStringContainsString('data-hb-insert-block="heisenberg/paragraph"', $html);

        // The design's original other 10 cards (Form, Input, Text Area, Select, Checkbox, Radio,
        // Link, Video, Image, Button — Divider/separator's contract is also gone) map to no block
        // contract that exists anywhere. Clicking any of them did nothing, so they must not render
        // at all, not merely render inert.
        foreach (['Form', 'Input', 'Text Area', 'Select', 'Checkbox', 'Radio', 'Link', 'Video', 'Image', 'Divider', 'Button'] as $label) {
            $this->assertStringNotContainsString('hb-toolcard__label">' . $label . '<', $html);
        }
    }

    public function test_components_base_category_head_is_wired_to_collapse_its_body(): void
    {
        $html = $this->get('/editor')->getContent();

        // ui/category-head only reports collapse intent by itself (a 'toggle' event); the caller
        // must mark its content sibling as the component's collapse target or "Base" never
        // actually hides the block grid.
        $this->assertStringContainsString('data-hb-category-head', $html);
        $this->assertStringContainsString('class="hb-panel-cb__body" data-hb-category-body', $html);
    }

    public function test_style_panel_controls_carry_supports_binding_hooks(): void
    {
        $html = $this->get('/editor')->getContent();

        // The Style sub-panels (live/block/style/*.blade.php) used to render with zero binding
        // hooks — nothing they displayed reflected the selected block and nothing they changed
        // wrote back. Every control a block's contract supports must carry a real data-hb-control
        // hook keyed by its dotted supports path, per inspector.blade.php's syncControls()/
        // handleControlEvent() contract.
        //
        // Paths chosen from what both contracts still declare after `border` was removed
        // (TODO 7.2): typography, size, spacing, color.
        // fontFamily is a ui/combobox against the live font catalog, not a select (TODO 7.5).
        $this->assertStringContainsString('data-hb-control="typography.fontFamily" data-hb-control-kind="supports" data-hb-control-type="combobox"', $html);
        $this->assertStringContainsString('data-hb-control="size.width" data-hb-control-kind="supports" data-hb-control-type="text"', $html);
        $this->assertStringContainsString('data-hb-control="spacing.padding.top" data-hb-control-kind="supports" data-hb-control-type="text"', $html);
        // NOT color.text: Fill is a layer STACK, and a per-row hook would make every layer
        // overwrite the same scalar so the last row always won. The stack composites and writes
        // once — see style/fill.blade.php.
        $this->assertStringContainsString('data-hb-style-layer-list="fill"', $html);
    }

    public function test_advanced_panel_controls_carry_attribute_binding_hooks(): void
    {
        $html = $this->get('/editor')->getContent();

        // live/block/advanced.blade.php's visibility toggles and animation controls are keyed by
        // real contract attributes (hideXs.../animate/animateDuration/animateDelay), not supports
        // paths, so they must route through setAttribute rather than setSupport.
        $this->assertStringContainsString('data-hb-control="hideXs" data-hb-control-kind="attributes" data-hb-control-type="toggle"', $html);
        $this->assertStringContainsString('data-hb-control="animate" data-hb-control-kind="attributes" data-hb-control-type="select"', $html);
        $this->assertStringContainsString('data-hb-control="animateDuration" data-hb-control-kind="attributes" data-hb-control-type="range"', $html);
    }

    public function test_block_toolbar_ships_the_align_and_color_popover_containers(): void
    {
        $html = $this->get('/editor')->getContent();

        // block-toolbar.blade.php resolves a popover trigger via
        // tb.querySelector('[data-tb-pop="' + name + '"]') — only "type" existed, so the Align and
        // Color triggers (groups/style.blade.php) opened nothing. Both containers must now render,
        // following the same .hb-tb__pop pattern the type switcher already used.
        $this->assertStringContainsString('data-tb-pop="align"', $html);
        $this->assertStringContainsString('data-tb-pop="color"', $html);
        $this->assertStringContainsString('data-hb-alignmenu', $html);
        $this->assertStringContainsString('data-hb-colormenu', $html);
    }

    public function test_block_toolbar_ships_a_real_listener_not_a_decorative_one(): void
    {
        $html = $this->get('/editor')->getContent();

        // The toolbar used to only toggle its own aria-pressed/hidden state and dispatch events
        // nothing listened for. It must now actually call into window.hbEditor: execCommand for
        // formatting, and setAttribute/setSupport for the type/align/color popovers.
        $this->assertStringContainsString('document.execCommand(', $html);
        $this->assertStringContainsString("window.hbEditor.setAttribute(ctx.id, 'level'", $html);
        $this->assertStringContainsString("window.hbEditor.setSupport(ctx.id, 'align'", $html);
        $this->assertStringContainsString("window.hbEditor.setSupport(ctx.id, 'color.text'", $html);
        // Still emits the pre-existing events for any other listener — additive, not a rewrite.
        $this->assertStringContainsString("new CustomEvent('hb:format'", $html);
        $this->assertStringContainsString("new CustomEvent('hb:toolbar-action'", $html);
    }

    public function test_type_menu_switches_heading_level_not_a_fake_type_list(): void
    {
        $html = $this->get('/editor')->getContent();

        // The old list (Text, Heading 1/2/3, Paragraph, List, Quote, Code) didn't match any real
        // contract — heisenberg/heading stores its level as a single integer attribute. The menu
        // must offer H1-H6 (wired to setAttribute('level', n) via the `blocktype` event) instead of
        // entries that look actionable but silently do nothing.
        $this->assertStringContainsString('data-type-level="1"', $html);
        $this->assertStringContainsString('data-type-level="6"', $html);
        $this->assertStringNotContainsString('Heading 1', $html);
        $this->assertStringNotContainsString('>List<', $html);
        $this->assertStringNotContainsString('>Quote<', $html);
    }

    public function test_excerpt_section_was_removed(): void
    {
        // panel-seo-social's own meta-description field already covers this — see
        // inspector.blade.php's top docblock note on why the row was dropped 2026-08-03. The
        // translated label itself is gone (the lang key was removed too), and nothing else on
        // the page renders the word "Excerpt".
        $html = $this->get('/editor')->getContent();

        $this->assertStringNotContainsString('Excerpt', $html);
    }

    public function test_categories_and_tags_render_as_a_shared_checkbox_checklist(): void
    {
        $html = $this->get('/editor')->getContent();

        // Both fields share one widget (data-hb-post-taxonomy-field) and render real
        // ui/checkbox rows (a `<label class="hb-checkbox ...">`), not the earlier
        // button+aria-pressed checklist or chip-list markup.
        $this->assertStringContainsString('data-hb-post-taxonomy-field', $html);
        $this->assertStringContainsString('hb-post-taxonomy-item', $html);
        $this->assertStringNotContainsString('data-hb-post-category-item', $html);
        $this->assertStringNotContainsString('data-hb-post-tags-chips', $html);
    }

    public function test_an_existing_posts_categories_and_tags_render_pre_checked(): void
    {
        $post = \Heisenberg\Models\Post::create(['title_en' => 'X', 'status' => 'draft']);
        $category = \Heisenberg\Models\Category::create(['name_en' => 'Field Notes']);
        $tag = \Heisenberg\Models\Tag::create(['name_en' => 'Featured']);
        $post->categories()->attach($category->id);
        $post->tags()->attach($tag->id);

        $html = $this->get("/editor/{$post->id}")->getContent();

        // Whitespace-tolerant: ui/checkbox renders `value="..."` and (conditionally) `checked`
        // as separate attribute lines, not a fixed one-line fragment.
        $this->assertMatchesRegularExpression('/value="' . $category->id . '"\s*checked/', $html);
        $this->assertMatchesRegularExpression('/value="' . $tag->id . '"\s*checked/', $html);
    }

    public function test_discussion_and_page_layout_sections_are_no_longer_inert(): void
    {
        $html = $this->get('/editor')->getContent();

        // A real ui/toggle, not a bare navigation row.
        $this->assertStringContainsString('data-hb-post-allow-comments', $html);
        // Two real ui/slider controls, not a bare navigation row.
        $this->assertStringContainsString('data-hb-post-layout-x', $html);
        $this->assertStringContainsString('data-hb-post-layout-y', $html);
    }
}
