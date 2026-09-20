<?php

declare(strict_types=1);

namespace Heisenberg\Tests\Editor;

use Heisenberg\Contracts\RoleGate;
use Heisenberg\Editor\EditorIcon;
use Heisenberg\Models\Category;
use Heisenberg\Models\Post;
use Heisenberg\Models\Tag;
use Heisenberg\Services\BlockRegistryService;
use Heisenberg\Support\BlockViewData;
use Heisenberg\Tests\Support\AssertsHtmlStructure;
use Heisenberg\Tests\TestCase;
use Illuminate\Auth\GenericUser;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\View\ComponentAttributeBag;

/**
 * Smoke coverage for the editor surface. The editor had zero test coverage while
 * four agents edited its view tree concurrently; these assertions exist so a Blade
 * or wiring regression fails here instead of in a browser.
 *
 * RefreshDatabase (2026-08-04): EditorController::index()/show() now read the category
 * table on every render (Categories combobox seed, Phase 3.1) — the blank /editor page
 * genuinely depends on that table existing now, same as tests/Editor/EditorSaveWiringTest.php
 * and PostPersistenceTest.php already needed it for the posts/blocks tables.
 *
 * Structural-assertion note: this file used to assert almost entirely with whole-page
 * `assertStringContainsString()`/`assertStringNotContainsString()`/regex calls against raw
 * HTML — including literal inline-JS lines pinned character-for-character. It now uses
 * AssertsHtmlStructure to assert DOM structure (an element/attribute/class exists, a JSON
 * data island decodes to the expected shape) wherever the thing under test is really markup,
 * and assertInlineScriptContains()/assertInlineScriptMatches() (scoped to actual `<script>`
 * bodies, whitespace-tolerant) for behaviour that only ever lives in inline JS with nothing
 * server-rendered to key off. A few assertions were found to be checking JS STRING LITERALS
 * (a template-literal fragment like `data-nav-depth="` built at runtime) against the whole
 * page rather than real server-rendered attributes; those are now correctly scoped to inline
 * script bodies instead of element selectors, which is what they always meant to test.
 */
class EditorRendersTest extends TestCase
{
    use AssertsHtmlStructure;
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
        $this->assertInlineScriptContains($html, 'window.hbEditor = {');
        // Required before any POST/PUT from the editor can succeed (media upload).
        $this->assertElementExists($html, 'meta[name="csrf-token"]');
    }

    public function test_editor_ships_the_code_view_and_its_footer_toggle(): void
    {
        $html = $this->get('/editor')->getContent();

        // The Code view panel (shortcode source) and the whole-document swap it applies through.
        $this->assertElementExists($html, '[data-hb-codeview]');
        $this->assertElementExists($html, '[data-hb-cv-input]');
        $this->assertInlineScriptContains($html, 'replaceDoc');
        // The footer chip is a real toggle now, not an inert label. Both attributes on the
        // SAME element, order-independent.
        $this->assertElementExists($html, '[data-hb="code-editor"][aria-pressed="false"]');
    }

    public function test_editor_ships_the_history_revisions_and_code_view_chrome(): void
    {
        $html = $this->get('/editor')->getContent();

        // Undo/redo: the topbar's two centre buttons are the only surface for the runtime's history
        // stack, and they must ship DISABLED — nothing is undoable on a freshly-opened document, and
        // an enabled-by-default button that no-ops is indistinguishable from a broken one. The
        // `hb:history` events flip them from there.
        $this->assertElementExists($html, '[data-hb-undo][disabled]');
        $this->assertElementExists($html, '[data-hb-redo][disabled]');

        // Post revisions: the inspector's Post tab row that opens the history dialog, and the dialog
        // itself. The dialog is asserted [data-hb-revisions][hidden] together — data-hb-revisions is
        // a prefix of data-hb-revisions-open, so an unscoped attribute-presence check would pass on
        // the ROW alone and never notice the dialog going missing.
        $this->assertElementExists($html, '[data-hb-revisions-open]');
        $this->assertElementExists($html, '[data-hb-revisions][hidden]');

        // Code view: the current-line band is the one piece of the editor's chrome that has to be in
        // the DOM up front (the highlight overlay and gutter are built from the textarea's content,
        // the band is positioned against it).
        $this->assertElementExists($html, '[data-hb-cv-band]');
    }

    /**
     * Table of contents (2026-08-10): the Post tab's Edit trigger + the modal it opens
     * (live/toc-dialog.blade.php), including its custom scrollbar and the Load-from-headings
     * wiring that writes a generated anchor back through window.hbEditor.setAttribute().
     */
    public function test_editor_ships_the_toc_section_modal_and_load_from_headings_wiring(): void
    {
        $html = $this->get('/editor')->assertOk()->getContent();

        // The Post tab's Edit trigger, and the dialog scrim itself (hidden until opened).
        $this->assertElementExists($html, '[data-hb-toc-open]');
        $this->assertElementExists($html, '[data-hb-toc][hidden]');

        // The dialog reuses the SAME modal shell (scrim classes + custom scrollbar) every other
        // editor dialog does — never a bespoke one. Both classes asserted on the TOC scrim itself.
        $this->assertElementExists($html, '[data-hb-toc].hb-mediadialog__scrim');
        $this->assertElementExists($html, '[data-hb-toc-scroll]');
        // The custom-scrollbar instance must target THIS scroll container specifically — decoded
        // via XPath rather than a CSS attribute selector because the value itself contains brackets.
        $this->assertElementExists(
            $html,
            "xpath://*[@data-hb-scroll-container='[data-hb-toc-scroll]']",
            'the custom scrollbar must target the toc scroll container',
        );

        // Add/reorder/remove + Load from headings + Save chrome.
        $this->assertElementExists($html, '[data-hb-toc-add]');
        $this->assertElementExists($html, '[data-hb-toc-load]');
        $this->assertElementExists($html, '[data-hb-toc-save]');
        $this->assertElementExists($html, '[data-hb-toc-up]');
        $this->assertElementExists($html, '[data-hb-toc-down]');
        $this->assertElementExists($html, '[data-hb-toc-remove]');

        // Load from headings walks the real runtime doc and writes the anchor back onto the
        // heading block — never a client-only, throwaway computation. Real client logic with no
        // server-rendered data to key off, so identifier/expression-scoped script assertions.
        $this->assertInlineScriptContains($html, 'window.hbEditor.getDoc()');
        $this->assertInlineScriptContains($html, "window.hbEditor.setAttribute(block.id, 'anchor'");
        $this->assertInlineScriptContains($html, "b.name === 'heisenberg/heading'");
    }

    public function test_client_registry_ships_contract_version_for_schema_version(): void
    {
        // BlocksPayloadService requires every block instance to carry a schemaVersion equal to
        // its contract's version, and the client runtime stamps it from this field — so if the
        // key ever stops being shipped, every save payload silently becomes invalid. Asserted
        // against the payload builder rather than the rendered HTML, since @json hex-escapes.
        $blocks = BlockViewData::clientBlocks(
            app(BlockRegistryService::class),
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
        $this->assertElementExists($html, '[data-hb-block-panel="heisenberg/heading"]');
        $this->assertElementExists($html, '[data-hb-block-panel="heisenberg/paragraph"]');
    }

    public function test_block_tab_uses_the_existing_pencil_components(): void
    {
        $html = $this->get('/editor')->getContent();

        // These are the 1:1-from-Pencil components in live/block/**, also showcased at
        // /editor/components. The Block tab must MOUNT them, not re-implement the UI around
        // them — an earlier pass rewrote them and lost the design fidelity. Their root classes
        // are the cheapest proof the real components are what render.
        $this->assertElementExists($html, '.hb-blockcontent');
        $this->assertElementExists($html, '.hb-blockstyle');
        $this->assertElementExists($html, '.hb-blockadvanced');
    }

    /** XPath for a `ui/panel-section` header's exact text — the one thing every section header shares. */
    private function sectionTitle(string $title): string
    {
        return "//span[@class='hb-section__title' and normalize-space(text())='" . $title . "']";
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
        // Both shipped contracts declare: color, typography, size, spacing, animation,
        // position, appearance, effects. Neither declares: align, layout, border.
        //
        // `border` was removed from both 2026-08-05 (TODO 7.2): text blocks do not support
        // borders or corner radius. That drops Stroke, and Appearance with it — radius was the
        // only thing keeping Appearance alive, since neither contract declares `appearance`.
        // Matched on ui/panel-section's own title span, not a bare '>Name<' — Stroke renders its
        // own "Position" label (inside/center/outside), which a loose match reads as the Position
        // SECTION still being mounted.
        //
        // Appearance is here for `appearance.opacity`, not corner radius — text has no border.
        //
        // Flex Layout joined this list 2026-08-06: it gates on `$isContainer && $has('layout')`,
        // and the container contracts (`group`/`columns`/`column`) are the first blocks to satisfy
        // both — innerBlocks.enabled true AND a declared `layout` group backed by
        // --hb-flex-direction/-justify/-align/-gap/-padding. The panel stack is pre-rendered once
        // per registered block type, so one container contract is enough to put the section on the
        // page; the text contracts still do not mount it.
        foreach (['State', 'Typography', 'Dimensions', 'Fill', 'Appearance', 'Position', 'Effects', 'Flex Layout'] as $section) {
            $this->assertElementExists($html, $this->sectionTitle($section));
        }
        // Alignment is block PLACEMENT (`supports.align`) — text contracts still don't declare
        // it (text alignment lives in Typography), but the containers DO (2026-08-06): a group
        // or columns places ITSELF via the same extracted Alignment section.
        $this->assertElementExists($html, $this->sectionTitle('Alignment'));
        // Stroke follows `border`, which the CONTAINER contracts declare as of 2026-08-07
        // (group/columns/column), so the section is on the page for their panels. Which panels
        // mount it is asserted per declaring contract in StylePanelGatingTest; here it is enough
        // that the extracted section renders at all.
        $this->assertElementExists($html, $this->sectionTitle('Stroke'));

        // Section-specific glyphs must resolve rather than falling back to generic arrows. Numeric
        // Style fields intentionally do not import arbitrary prefix/unit/caret chrome.
        $this->assertElementExists($html, '[data-icon-name="format_align_right"]');
        $this->assertElementExists($html, '[data-icon-name="gear-six"]');
        $this->assertElementExists($html, '[data-icon-name="style-padding-left"]');
        // The corner-radius glyphs belong to Appearance's corner fields, which follow `border`.
        // On the page since the containers declared it (2026-08-07) and SupportsStyle gained the
        // per-corner radius capability that backs them; text contracts still gate them away.
        $this->assertElementExists($html, '[data-icon-name="style-corner-radius-top-left"]');
        // The align-*-fill glyphs belong to the Alignment section — on the page since the
        // containers declared `align` (2026-08-06); text contracts still gate it away.
        $this->assertElementExists($html, '[data-icon-name="align-left-fill"]');
        $this->assertElementExists($html, '[data-hb-style-layer-template="fill"]');
        // Stroke's layer template goes with the Stroke section, which the container contracts
        // now mount (2026-08-07).
        $this->assertElementExists($html, '[data-hb-style-layer-template="stroke"]');
        // Fill still triggers the colour picker, so the popup stays mounted.
        $this->assertElementExists($html, '[data-hb-style-popup="color"]');
        $this->assertElementExists($html, '[data-cp-gradient-add]');
        $this->assertElementExists($html, '[data-cp-gradient-reverse]');
        $this->assertInlineScriptContains($html, "document.addEventListener('gradientchange'");
        $this->assertElementExists($html, '[data-hb-style-padding-mode="one"]');
        $this->assertElementExists($html, '[data-hb-style-padding-mode="two"]');
        $this->assertElementExists($html, '[data-hb-style-padding-mode="four"]');
        $this->assertInlineScriptContains($html, 'setPaddingAxisValue(root, paddingAxis.dataset.hbStylePaddingAxis, event.target.value)');
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
            $this->assertSame($icon, EditorIcon::resolveSlug($icon), "{$icon} must resolve to its vendored SVG");
            // Structural rather than a literal '<path d=""/>' substring: any empty `d` attribute
            // on any <path>, regardless of attribute order or how the tag is otherwise formatted,
            // means the icon resolved to a hollow placeholder rather than a real vendored glyph.
            $fragment = '<svg>' . EditorIcon::svg($icon) . '</svg>';
            $this->assertElementMissing($fragment, "path[d='']", "{$icon} must not render as an empty SVG");
        }
    }

    public function test_inspector_scrolls_per_sub_tab_not_as_one_block_tab(): void
    {
        $html = $this->get('/editor')->getContent();

        // Every other scrollable surface (left panels, canvas) uses ui/custom-scrollbar; the right
        // sidebar was the only one left on a native scrollbar, and its Post tab had no scroller at
        // all. Each of the three Block sub-tabs is its own scroll region — a single scroller on the
        // Block tab would carry the Style tab's scroll offset over to Content. XPath rather than a
        // CSS attribute selector because each value itself contains brackets.
        foreach (['[data-hb-subpanel-content]', '[data-hb-subpanel-style]', '[data-hb-subpanel-advanced]', '[data-hb-inspector-post-body]'] as $target) {
            $this->assertElementExists(
                $html,
                "xpath://*[@data-hb-scroll-container='{$target}']",
                "a custom scrollbar must target {$target}",
            );
        }
    }

    /** XPath for a `ui/disclosure-row` whose class list includes hb-disclosure--border and whose label text matches. */
    private function borderedDisclosureRow(string $label): string
    {
        return "//button[contains(concat(' ', normalize-space(@class), ' '), ' hb-disclosure--border ')"
            . " and .//span[@class='hb-disclosure__label' and normalize-space(text())='" . $label . "']]";
    }

    public function test_post_body_rows_cannot_shrink_when_a_disclosure_opens_or_closes(): void
    {
        $html = $this->get('/editor')->getContent();
        // 2026-08-15: ui/disclosure-row.blade.php stylesheet moved to editor.css bundle — see
        // resources/css/editor/36-components.css. The inline emission was being captured
        // inside <template> on first render (post-taxonomy-item-template uses the component).
        $css = $this->get('/heisenberg-assets/editor.css')->getContent();

        // The Post stack uses compact 32px rows, separated only by their top edge. Summary is a
        // disclosure too, so it needs the same top separator as the navigation rows.
        //
        // 2026-08-15: the rules for .hb-inspector__post-body live in inspector.blade.php's
        // inline <style>, NOT in the editor.css bundle (the 2026-08-15 component-CSS sweep
        // only moved ui/* component styles, not live/* page styles). The disclosure-row
        // rules DO live in the bundle. Split accordingly. Both are genuine CSS-rule-text checks
        // (not markup structure), so a tolerant regex against the stylesheet text is already the
        // right tool — kept, rather than routed through the DOM trait.
        $this->assertMatchesRegularExpression('/\.hb-inspector__post-body\s*>\s*\*\s*\{[^}]*flex:\s*none/', $html);
        $this->assertMatchesRegularExpression('/\.hb-disclosure--border\s*\{[^}]*border-top:\s*1px solid/', $css);

        // Scoped to the ACTUAL `.hb-disclosure--border { ... }` rule body rather than a literal
        // one-line '.hb-disclosure--border { border-bottom:' substring — the latter would silently
        // pass (prove nothing) the moment the rule reflows across multiple lines.
        preg_match('/\.hb-disclosure--border\s*\{([^}]*)\}/', $css, $ruleBody);
        $this->assertArrayHasKey(1, $ruleBody, 'the .hb-disclosure--border rule must exist to scope this check against');
        $this->assertStringNotContainsString('border-bottom', $ruleBody[1]);

        // The featured-image and summary rows are both bordered, height-32 disclosures — both
        // proven on the SAME element (class + inline style), which two independent whole-page
        // substring checks could not guarantee.
        $featuredLabel = __('heisenberg::editor.inspector.post_featured_image');
        $summaryLabel = __('heisenberg::editor.inspector.post_summary');

        foreach ([$featuredLabel, $summaryLabel] as $label) {
            $row = $this->borderedDisclosureRow($label);
            $this->assertElementExists($html, $row, "the '{$label}' row must be a bordered disclosure");
            $this->assertElementHasAttribute(
                $html,
                $row,
                'style',
                'height:32px;padding:0 var(--hb-space-3, 12px);',
                "the '{$label}' row must use the shared compact 32px row height",
            );
        }
    }

    public function test_fixed_height_chrome_rows_do_not_shrink(): void
    {
        // Component CSS moved to editor.css bundle on 2026-08-15 — see sibling test above.
        $css = $this->get('/heisenberg-assets/editor.css')->getContent();

        // Both tab strips declare a fixed height, so both must opt out of flex shrinking — inside a
        // clamped flex column the default flex-shrink: 1 squeezes them by however tall the panel
        // below happens to be, so the row's height changed as you switched sub-tabs.
        $this->assertMatchesRegularExpression('/\.hb-subtabs\s*\{[^}]*flex:\s*none/', $css);
        $this->assertMatchesRegularExpression('/\.hb-paneltabs\s*\{[^}]*flex:\s*none/', $css);
    }

    public function test_runtime_exposes_both_model_write_paths(): void
    {
        $html = $this->get('/editor')->getContent();

        // Attribute-keyed and supports-keyed writes each need a runtime setter that owns its own
        // re-render and events. If either disappears, the inspector starts mutating models directly.
        $this->assertInlineScriptContains($html, 'setAttribute: setAttribute');
        $this->assertInlineScriptContains($html, 'setSupport: setSupport');
    }

    public function test_editor_has_no_alpine_directives(): void
    {
        $html = $this->get('/editor')->getContent();

        // Alpine is not loaded on this page, so any x-data/x-show is dead on arrival.
        // panel-section shipped with them once; this stops that regressing. These are markup
        // attributes, so a real element-attribute check is the correct tool — kept as
        // string-absence (nothing to name structurally: the claim is that NO element anywhere
        // carries these), matched as attribute syntax rather than bare substrings.
        $this->assertElementMissing($html, '[x-data]');
        $this->assertElementMissing($html, '[x-show]');
        $this->assertElementMissing($html, "//*[@*[name()='x-on:click']]");
    }

    public function test_drag_and_drop_surfaces_are_wired(): void
    {
        $html = $this->get('/editor')->getContent();

        $this->assertInlineScriptContains($html, 'wireCanvasBlockDrag');
        $this->assertInlineScriptContains($html, 'wirePaletteDrag');
        // The dead event the canvas used to dispatch must be gone.
        $this->assertInlineScriptDoesNotMatch($html, '/hb:insert-block/');
    }

    public function test_components_tab_only_lists_cards_for_registered_blocks(): void
    {
        $html = $this->get('/editor')->getContent();

        // Cards are derived straight from the registry now (2026-08-02) — every
        // discovered contract gets one, heading and paragraph both included.
        $this->assertElementExists($html, '[data-hb-insert-block="heisenberg/heading"]');
        $this->assertElementExists($html, '[data-hb-insert-block="heisenberg/paragraph"]');

        // Design cards that map to NO existing block contract must not render at all, not merely
        // render inert. (2026-08-07: Image/Button/Quote/List/Separator left this list — their
        // contracts shipped in the essentials round, so the registry now derives real cards.)
        foreach (['Form', 'Input', 'Text Area', 'Select', 'Checkbox', 'Radio', 'Link', 'Video', 'Divider'] as $label) {
            $this->assertElementMissing(
                $html,
                "//*[contains(concat(' ', normalize-space(@class), ' '), ' hb-toolcard__label ') and normalize-space(text())='{$label}']",
                "'{$label}' looks like a leftover card for a block contract that does not exist",
            );
        }
    }

    public function test_components_base_category_head_is_wired_to_collapse_its_body(): void
    {
        $html = $this->get('/editor')->getContent();

        // ui/category-head only reports collapse intent by itself (a 'toggle' event); the caller
        // must mark its content sibling as the component's collapse target or "Base" never
        // actually hides the block grid.
        $this->assertElementExists($html, '[data-hb-category-head]');
        $this->assertElementExists($html, 'div.hb-panel-cb__body[data-hb-category-body]');
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
        $this->assertElementExists($html, '[data-hb-control="typography.fontFamily"][data-hb-control-kind="supports"][data-hb-control-type="combobox"]');
        $this->assertElementExists($html, '[data-hb-control="size.width"][data-hb-control-kind="supports"][data-hb-control-type="text"]');
        $this->assertElementExists($html, '[data-hb-control="spacing.padding.top"][data-hb-control-kind="supports"][data-hb-control-type="text"]');
        // NOT color.text: Fill is a layer STACK, and a per-row hook would make every layer
        // overwrite the same scalar so the last row always won. The stack composites and writes
        // once — see style/fill.blade.php.
        $this->assertElementExists($html, '[data-hb-style-layer-list="fill"]');
    }

    public function test_advanced_panel_controls_carry_attribute_binding_hooks(): void
    {
        $html = $this->get('/editor')->getContent();

        // live/block/advanced.blade.php's visibility toggles and animation controls are keyed by
        // real contract attributes (hideXs.../animate/animateDuration/animateDelay), not supports
        // paths, so they must route through setAttribute rather than setSupport.
        $this->assertElementExists($html, '[data-hb-control="hideXs"][data-hb-control-kind="attributes"][data-hb-control-type="toggle"]');
        // `animate` is a ui/combobox in static (self-filtering) mode since the Animate section went
        // catalog-driven — AnimationCatalog is ~40 presets, which is past what a select menu can be
        // scanned for. The hook contract is unchanged; only the declared control TYPE moved.
        $this->assertElementExists($html, '[data-hb-control="animate"][data-hb-control-kind="attributes"][data-hb-control-type="combobox"]');
        $this->assertElementExists($html, '[data-hb-control="animateDuration"][data-hb-control-kind="attributes"][data-hb-control-type="range"]');
    }

    public function test_block_toolbar_ships_the_align_and_color_popover_containers(): void
    {
        $html = $this->get('/editor')->getContent();

        // block-toolbar.blade.php resolves a popover trigger via
        // tb.querySelector('[data-tb-pop="' + name + '"]') — only "type" existed, so the Align and
        // Color triggers (groups/style.blade.php) opened nothing. Both containers must now render,
        // following the same .hb-tb__pop pattern the type switcher already used.
        $this->assertElementExists($html, '[data-tb-pop="align"]');
        $this->assertElementExists($html, '[data-tb-pop="color"]');
        $this->assertElementExists($html, '[data-hb-alignmenu]');
        $this->assertElementExists($html, '[data-hb-colormenu]');
    }

    public function test_block_toolbar_ships_a_real_listener_not_a_decorative_one(): void
    {
        // Rendered as a standalone component rather than fetched from the full /editor page:
        // libxml's HTML parser was found (while converting this test) to truncate THIS specific
        // ~19KB inline <script> to ~4.3KB when it sits several hundred KB into the ~3.7MB full
        // editor page — a positional libxml quirk unrelated to anything in this file's own
        // markup (confirmed by rendering the same component in isolation, where the same script
        // parses completely). Scoping to the component under test sidesteps a whole-page-parser
        // limitation that has nothing to do with the behaviour this test protects, and is also
        // the more precise unit to assert against.
        $html = (string) view('heisenberg::components.live.toolbar.block-toolbar', [
            'attributes' => new ComponentAttributeBag([]),
        ])->render();

        // The toolbar used to only toggle its own aria-pressed/hidden state and dispatch events
        // nothing listened for. It must now actually call into window.hbEditor: execCommand for
        // formatting, and setAttribute/setSupport for the type/align/color popovers.
        $this->assertInlineScriptContains($html, 'document.execCommand(');
        $this->assertInlineScriptContains($html, "window.hbEditor.setAttribute(ctx.id, 'level'");
        $this->assertInlineScriptContains($html, "window.hbEditor.setSupport(ctx.id, 'align'");
        $this->assertInlineScriptContains($html, "window.hbEditor.setSupport(ctx.id, 'color.text'");
        // The listener-less hb:format / hb:toolbar-action / hb:toolbar-popover broadcast
        // events were removed (2026-08-05 review) — real behaviour only, no dead dispatches.
        $this->assertInlineScriptDoesNotMatch($html, "/new CustomEvent\\('hb:format'/");
        $this->assertInlineScriptDoesNotMatch($html, "/new CustomEvent\\('hb:toolbar-action'/");
        $this->assertInlineScriptDoesNotMatch($html, "/new CustomEvent\\('hb:toolbar-popover'/");
    }

    public function test_type_menu_switches_heading_level_not_a_fake_type_list(): void
    {
        $html = $this->get('/editor')->getContent();

        // The old list (Text, Heading 1/2/3, Paragraph, List, Quote, Code) didn't match any real
        // contract — heisenberg/heading stores its level as a single integer attribute. The menu
        // must offer H1-H6 (wired to setAttribute('level', n) via the `blocktype` event) instead of
        // entries that look actionable but silently do nothing.
        $this->assertElementExists($html, '[data-hb-typemenu] [data-type-level="1"]');
        $this->assertElementExists($html, '[data-hb-typemenu] [data-type-level="6"]');

        // The type menu's own item list is exactly the "current type" button plus H1-H6 — never
        // the old fake convert-to list. Scoped to [data-hb-typemenu]'s own items: the PALETTE
        // legitimately shows "List"/"Quote" cards now that those contracts shipped (2026-08-07)
        // — only the TYPE MENU must not offer them as fake convert-to targets.
        $this->assertElementCount($html, '[data-hb-typemenu] .hb-typemenu__item', 7);
        foreach (['Heading 1', 'List', 'Quote'] as $label) {
            $this->assertElementMissing(
                $html,
                "//*[@data-hb-typemenu]//span[normalize-space(text())='{$label}']",
                "'{$label}' should not appear as a type-menu item label",
            );
        }
    }

    public function test_excerpt_section_was_removed(): void
    {
        // panel-seo-social's own meta-description field already covers this — see
        // inspector.blade.php's top docblock note on why the row was dropped 2026-08-03. The
        // translated label itself is gone (the lang key was removed too), and nothing else on
        // the page renders the word "Excerpt". No element to name structurally — this is a
        // leftover-content check, kept as a whole-page substring absence.
        $html = $this->get('/editor')->getContent();

        $this->assertStringNotContainsString('Excerpt', $html);
    }

    public function test_categories_and_tags_render_as_a_shared_checkbox_checklist(): void
    {
        $html = $this->get('/editor')->getContent();

        // Both fields share one widget (data-hb-post-taxonomy-field) and render real
        // ui/checkbox rows (a `<label class="hb-checkbox ...">`), not the earlier
        // button+aria-pressed checklist or chip-list markup.
        $this->assertElementExists($html, '[data-hb-post-taxonomy-field]');
        $this->assertElementExists($html, '.hb-post-taxonomy-item');
        $this->assertElementMissing($html, '[data-hb-post-category-item]');
        $this->assertElementMissing($html, '[data-hb-post-tags-chips]');
    }

    public function test_an_existing_posts_categories_and_tags_render_pre_checked(): void
    {
        // GET /editor/{post} runs PostPolicy::view (the anti-IDOR check). A GuestActor is
        // denied outside the local env no matter what the RoleGate says (LocalDevRoleGate
        // decides guests by environment alone), so act as a real user and grant it the
        // authors tier so the rendering assertions below are reachable.
        $this->actingAs(new GenericUser(['id' => 7]));
        $this->app->instance(RoleGate::class, new class implements RoleGate
        {
            public function is(Authenticatable $user, string $tier): bool
            {
                return true;
            }

            public function isAny(Authenticatable $user, array $tiers): bool
            {
                return true;
            }

            public function rolesOf(Authenticatable $user): array
            {
                return ['authors'];
            }

            public function systemActor(): ?Authenticatable
            {
                return null;
            }
        });

        $post = Post::create(['title_en' => 'X', 'status' => 'draft']);
        $category = Category::create(['name_en' => 'Field Notes']);
        $tag = Tag::create(['name_en' => 'Featured']);
        $post->categories()->attach($category->id);
        $post->tags()->attach($tag->id);

        $html = $this->get("/editor/{$post->id}")->getContent();

        // The checkbox input for this category/tag id must actually be checked — a real
        // attribute assertion rather than a whitespace-tolerant regex against raw HTML.
        $this->assertElementHasAttribute($html, 'input[value="' . $category->id . '"]', 'checked');
        $this->assertElementHasAttribute($html, 'input[value="' . $tag->id . '"]', 'checked');
    }

    /**
     * Refresh must not be a reset (2026-08-10). Autosave deliberately never
     * creates a post, so before this the blank /editor lost everything on
     * refresh until the first explicit Save. The blank page now mirrors the
     * document to localStorage and restores it; a post page must NOT ship that
     * script — its persistence is the DB via /editor/{id}.
     */
    public function test_the_blank_editor_mirrors_an_unsaved_draft_and_a_post_page_does_not(): void
    {
        $blank = $this->get('/editor')->getContent();
        $this->assertInlineScriptContains($blank, 'hb-editor:unsaved-draft');
        $this->assertInlineScriptContains($blank, "document.addEventListener('hb:post-id'");

        $this->actingAs(new GenericUser(['id' => 7]));
        $this->app->instance(RoleGate::class, new class implements RoleGate
        {
            public function is(Authenticatable $user, string $tier): bool
            {
                return true;
            }

            public function isAny(Authenticatable $user, array $tiers): bool
            {
                return true;
            }

            public function rolesOf(Authenticatable $user): array
            {
                return ['authors'];
            }

            public function systemActor(): ?Authenticatable
            {
                return null;
            }
        });
        $post = Post::create(['title_en' => 'Saved post', 'status' => 'draft']);
        $postHtml = $this->get("/editor/{$post->id}")->getContent();
        $this->assertInlineScriptDoesNotMatch($postHtml, '/hb-editor:unsaved-draft/');
    }

    /**
     * Clicking a rail item means "show me that panel": with the panel area
     * collapsed it must reopen instead of switching invisibly, and the chosen
     * panel survives a refresh via localStorage.
     */
    public function test_the_sidebar_reopens_a_collapsed_panel_and_remembers_the_active_one(): void
    {
        $html = $this->get('/editor')->getContent();

        $this->assertInlineScriptContains($html, "hbSetPanelState(shell, 'panel', true)");
        $this->assertInlineScriptContains($html, 'hb-editor:active-nav');
    }

    /**
     * Restores that need the parsed DOM run at DOMContentLoaded — without the
     * boot gate the browser paints the fresh-boot layout first and the restored
     * state lands as a visible "readjust" flash. The gate must be added
     * synchronously before paint and must carry the timeout failsafe.
     */
    public function test_the_boot_gate_hides_the_first_paint_until_state_is_restored(): void
    {
        $html = $this->get('/editor')->getContent();

        $this->assertInlineScriptContains($html, "classList.add('hb-editor--booting')");
        $this->assertInlineScriptContains($html, "classList.remove('hb-editor--booting')");
        // The failsafe: a throwing restore script must never leave the editor hidden.
        $this->assertInlineScriptContains($html, 'setTimeout(() => shell.classList.remove');
    }

    /** The List View walks innerBlocks — nested children render indented, foldable, not hidden. */
    public function test_the_navigator_list_view_walks_nested_blocks(): void
    {
        $html = $this->get('/editor')->getContent();

        // data-nav-depth/data-nav-twist are built at runtime inside a JS template-literal string
        // (panel-navigator.blade.php's row renderer), never server-rendered attributes — so these
        // are inline-script identifier checks, not element queries.
        $this->assertInlineScriptContains($html, 'data-nav-depth="');
        $this->assertInlineScriptContains($html, 'data-nav-twist="');
        $this->assertInlineScriptContains($html, 'walk(kids, depth + 1, id)');
    }

    /**
     * The extracted media-card's uploading/error states are LIVE (2026-08-10):
     * the dialog uploads per-file over XHR into an optimistic uploading card
     * (real progress ticks), failure swaps in the error card with Retry, and a
     * file over the server's EFFECTIVE limit (min of media.max_kb and PHP's
     * upload_max_filesize/post_max_size) is rejected client-side with the
     * limit spelled out — instead of a wasted request ending in the opaque
     * "failed to upload" default.
     */
    public function test_the_media_dialog_uploads_with_progress_cards_and_a_size_preflight(): void
    {
        $html = $this->get('/editor')->getContent();

        $this->assertElementExists($html, '[data-hb-mediacard-uploading-template]');
        $this->assertElementExists($html, '[data-hb-mediacard-error-template]');
        $this->assertInlineScriptContains($html, 'hbUploadCard');
        $this->assertInlineScriptContains($html, "xhr.upload.addEventListener('progress'");
        $this->assertElementExists($html, '[data-max-bytes]');
        $this->assertElementExists($html, '.hb-mediacard__retry');
    }

    public function test_editor_and_preview_pages_for_a_post_require_view_authorization(): void
    {
        $post = Post::create(['title_en' => 'Secret draft', 'status' => 'draft']);

        // An anonymous visitor must not be able to read a draft by enumerating IDs.
        $this->get("/editor/{$post->id}")->assertForbidden();
        $this->get("/editor/{$post->id}/preview")->assertForbidden();
    }

    public function test_discussion_and_page_layout_sections_are_no_longer_inert(): void
    {
        $html = $this->get('/editor')->getContent();

        // A real ui/toggle, not a bare navigation row.
        $this->assertElementExists($html, '[data-hb-post-allow-comments]');
        // The discussion JS resolves its toggle through this exact shape: the data attribute
        // lands on ui/toggle's wrapper <label>, and reading .checked off that label instead of
        // drilling into .hb-toggle__input sends {} and silently 422s every save. Descendant
        // (not just "exists somewhere on the page") relationship asserted directly.
        $this->assertElementExists($html, '[data-hb-post-allow-comments] input.hb-toggle__input');
        // Two real ui/slider controls, not a bare navigation row.
        $this->assertElementExists($html, '[data-hb-post-layout-x]');
        $this->assertElementExists($html, '[data-hb-post-layout-y]');
    }

    public function test_a_toolbar_click_never_reselects_whatever_it_floats_over(): void
    {
        $html = $this->get('/editor')->getContent();

        // The floating toolbar lives in the canvas layer (block-runtime's dockToolbar), so a press
        // on it lands inside .hb-canvas and must not fall through to the selection logic and
        // re-select whichever block the bar happens to be floating over, out from under the one it
        // is acting for. Both mousedown listeners carry the guard — the select-on-mousedown one as
        // belt and braces, the deselect-on-empty-canvas one because the event really does reach it.
        // See tests/js/toolbar-follow-matrix.mjs for real-browser coverage of where the bar lands.
        $this->assertInlineScriptContains($html, "if (e.target.closest('.hb-tb')) return;");
        $this->assertInlineScriptContains($html, "!e.target.closest('.hb-tb')");
    }

    public function test_the_columns_block_has_a_working_column_count_control(): void
    {
        $html = $this->get('/editor')->getContent();

        // The Content sub-tab's count field (contract attribute `columns`, number control) …
        $this->assertElementExists($html, '[data-hb-control="columns"][data-hb-control-kind="attributes"][data-hb-control-type="number"]');
        // … drives the runtime's innerBlocks reconciliation in both directions: a count write
        // adds/drops trailing column children, and structural changes write the true length back.
        $this->assertInlineScriptContains($html, 'function reconcileColumnsCount(model)');
        $this->assertInlineScriptContains($html, "if (key === 'columns') reconcileColumnsCount(model);");
        $this->assertInlineScriptContains($html, 'function syncColumnsCounts(list)');
        $this->assertInlineScriptContains($html, 'm.attributes.columns = m.innerBlocks.length;');
    }
}
