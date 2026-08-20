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

        // The Block tab pre-renders one panel PER REGISTERED BLOCK TYPE and hides all but the
        // selected one (see inspector.blade.php's docblock) — and since 2026-08-06 the spacing
        // section gates per GROUP (column declares only padding, embed only margin), so the
        // expected count is 4 side fields per declared group per contract, derived from the
        // registry rather than hardcoded.
        $expected = 0;
        foreach (app(BlockRegistryService::class)->registry()['blocks'] as $block) {
            foreach (['padding', 'margin'] as $group) {
                if (! empty($block['supports']['spacing'][$group] ?? null)) {
                    $expected += 4;
                }
            }
        }
        $this->assertGreaterThan(0, $expected);
        $this->assertSame(
            $expected,
            substr_count($html, 'data-hb-control="spacing.'),
            'Expected 4 per-side spacing controls per declared group per block type',
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
        // keystroke while the user types. Routed through hbStatePath like the per-side
        // controls' own writes, so aggregate padding authored on a Hover/Active/Focus tab
        // retargets states.<state>.spacing.* instead of silently writing the base style.
        $this->assertStringContainsString("window.hbEditor.setSupport(id, hbStatePath(root, 'spacing.' + group), {", $html);
    }

    public function test_aggregate_spacing_fields_are_re_derived_when_a_block_is_selected(): void
    {
        $html = $this->editorHtml();

        // They hold no model path, so syncControls (which walks [data-hb-control]) skips them —
        // without this a re-selected block shows real per-side values under a stale summary.
        $this->assertStringContainsString('function syncSpacingAggregates(root)', $html);

        // Must be called from inside syncControls — asserted by position rather than adjacency,
        // since other per-selection passes (the theme-variable trigger sync, TODO 7.7) legitimately
        // sit between it and refreshConditionals.
        $body = substr($html, (int) strpos($html, 'function syncControls(root, model)'));
        $body = substr($body, 0, (int) strpos($body, 'function showBlockPanels'));
        $this->assertStringContainsString('syncSpacingAggregates(root);', $body);
        $this->assertStringContainsString('refreshConditionals(root, model);', $body);
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

    public function test_the_anchor_field_is_labelled_anchor_with_a_hint_and_a_duplicate_warning_slot(): void
    {
        // "Id" read as internal/technical and the field went undiscovered (it IS the HTML id,
        // wired to render.template on every contract) — renamed to "Anchor" with a hint tying it
        // to the behaviour authors actually look for: links and the table of contents.
        $html = $this->editorHtml();

        $anchorSection = substr($html, (int) strpos($html, 'hb-section__title">General<'));
        $anchorSection = substr($anchorSection, 0, (int) strpos($anchorSection, 'hb-classchips'));

        $this->assertStringNotContainsString('>Id<', $anchorSection, 'the field must no longer read "Id"');
        $this->assertStringContainsString('>Anchor<', $anchorSection);
        $this->assertStringContainsString('placeholder="section-anchor"', $anchorSection);
        $this->assertStringContainsString('jump to this anchor', $anchorSection);

        // Presentation-only duplicate-id slot: toggled by inspector.blade.php's anchor-specific
        // input listener (anchorIsDuplicate), never a second model write path.
        $this->assertStringContainsString('data-hb-anchor-warning', $anchorSection);
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
        // 2026-08-15: ui/chip's stylesheet now lives in the editor.css bundle (resources/css/
        // editor/36-components.css), loaded via /heisenberg-assets/editor.css. The @once
        // emission was being captured inside a <template> on first render — the fix is the
        // same one applied to all 35 affected components.
        $css = $this->get('/heisenberg-assets/editor.css')->getContent();

        // ui/chip carries @once <style>/<script>, and its only other render on /editor is a
        // loop over a prop nothing passes — so a <template> prototype would be the FIRST chip
        // render on the page, putting those blocks inside template.content where browsers
        // neither apply the CSS nor run the script. Every chip would render unstyled. Same trap
        // ui/theme-preset-card hit (TODO 6.7).
        $this->assertStringNotContainsString('data-hb-chip-template', $html);

        // The component's own stylesheet must reach the page. The "inside a <template>" trap
        // is gone (the rules now live in editor.css, never inline), so the only thing this
        // test still asserts is that the rules shipped at all.
        $this->assertStringContainsString('.hb-chip__close', $css);
    }

    public function test_a_pasted_space_separated_class_string_becomes_separate_chips(): void
    {
        $html = $this->editorHtml();

        // Space is the model's separator, so one chip labelled "a b c" would silently re-split
        // into three on the next read — the chip list and the model would disagree.
        $this->assertStringContainsString('input.value.trim().split(/\s+/).filter(Boolean)', $html);
    }

    public function test_an_absent_style_value_restores_the_controls_pristine_default(): void
    {
        $html = $this->editorHtml();

        // The old early-return kept whatever the PREVIOUSLY selected block left in the shared
        // per-type panel, so block B appeared to carry block A's padding/width — and the
        // spacing group's object commit then wrote those stale values into block B's model.
        $this->assertStringContainsString('function controlPristine(el, type)', $html);
        $this->assertStringContainsString('const pristine = controlPristine(el, type);', $html);
        $this->assertStringContainsString('if (value === undefined) value = pristine;', $html);
        $this->assertStringNotContainsString(
            "if (root.querySelector('.hb-blockstyle') && value === undefined) return;",
            $html,
        );
    }

    public function test_a_trailing_change_after_a_selection_switch_never_writes_to_the_new_block(): void
    {
        $html = $this->editorHtml();

        // A focused field keeps focus while the user clicks the next block; its pending native
        // 'change' fires only after the selection already moved, so the delegated write would
        // land block A's value on block B. The focus-time stamp drops that stale event.
        $this->assertStringContainsString('el.__hbEditsBlock = window.hbEditor.getSelectedId();', $html);
        $this->assertStringContainsString('if (el) delete el.__hbEditsBlock;', $html);
        $this->assertStringContainsString(
            'if (el.__hbEditsBlock !== undefined && el.__hbEditsBlock !== id) return;',
            $html,
        );
    }

    // ── Post → Summary status popup (2026-08-11 — plain text row + anchored popup) ──
    // The Status row is a plain-text value that opens a popup of legal transitions — see
    // EditorController::postMeta()/statusLabels() and topbar.blade.php's
    // hb:post-status-change wiring. These pins cover the seam most at risk of silently
    // regressing: the server-seeded payload the client needs to offer legal edges and
    // rebuild them after every save, without ever hardcoding
    // config('heisenberg.lifecycle.transitions') client-side.

    public function test_summary_status_control_ships_the_seeded_transitions_payload(): void
    {
        $html = $this->editorHtml();

        $this->assertStringContainsString('data-hb-post-popup-trigger="status"', $html);
        // A blank /editor document is a draft that has never been saved.
        $this->assertStringContainsString('data-hb-current-status="draft"', $html);

        $transitions = (array) config('heisenberg.lifecycle.transitions', []);
        $this->assertNotEmpty($transitions, 'nothing to pin against — config(heisenberg.lifecycle.transitions) is empty');
        $this->assertStringContainsString(
            'data-hb-transitions="' . e(json_encode($transitions)) . '"',
            $html,
            'the FULL transitions map must ship, never a hardcoded client-side copy',
        );

        // draft's own legal targets only — not the whole graph. Since 2026-08-12 that set
        // includes published/scheduled (an editor publishes straight from a draft; the tier
        // gate in lifecycle.role_permissions, not the graph, is what stops an author).
        foreach (array_merge(['draft'], $transitions['draft'] ?? []) as $status) {
            $this->assertStringContainsString('data-hb-post-status-option="' . $status . '"', $html);
        }
        $this->assertContains('published', $transitions['draft'] ?? [], 'an editor must be able to publish a draft directly');
        // A target that is NOT reachable from draft still must not be offered — this is the
        // assertion that keeps the options list tied to the graph rather than listing everything.
        $this->assertStringNotContainsString(
            'data-hb-post-status-option="unpublished"',
            $html,
            'only config\'s own draft targets may be offered',
        );

        // The schedule row + its own date-picker popup ship (hidden — draft isn't
        // `scheduled`), ready for the client to reveal once `scheduled` is picked or
        // confirmed by the server.
        $this->assertStringContainsString('data-hb-post-schedule-row', $html);
        $this->assertStringContainsString('data-hb-post-schedule-input', $html);
    }

    public function test_summary_status_control_is_disabled_with_a_save_first_hint_before_the_first_save(): void
    {
        $html = $this->editorHtml();

        $statusTag = substr($html, (int) strpos($html, 'data-hb-post-status '));
        $statusTag = substr($statusTag, 0, (int) strpos($statusTag, '>') + 1);
        $this->assertNotSame('', $statusTag, 'the status trigger never rendered');

        $this->assertStringContainsString('disabled', $statusTag);
        $this->assertStringContainsString(
            'title="' . e(__('heisenberg::editor.inspector.summary_status_save_first')) . '"',
            $statusTag,
        );

        // hb:post-id (fired on the FIRST save — topbar.blade.php) is what lifts the disabled
        // state; without this the control would stay locked forever on a saved post.
        $this->assertStringContainsString("document.addEventListener('hb:post-id', () => {", $html);
    }

    // ── Post → Summary Blocks row removed / slug + publish-date rows added (2026-08-11) ────

    public function test_the_blocks_row_no_longer_renders_in_the_summary(): void
    {
        $html = $this->editorHtml();

        $this->assertStringNotContainsString('data-hb-post-meta-value="blocks"', $html);
        $this->assertStringNotContainsString("value('blocks')", $html, 'the row\'s own live-count script must be gone too');
    }

    public function test_the_url_rows_slug_input_is_present_and_seeded(): void
    {
        $html = $this->editorHtml();

        $this->assertStringContainsString('data-hb-post-slug-input', $html);
        $this->assertStringContainsString('data-hb-current-slug=""', $html, 'a blank /editor document has no slug yet');
        $this->assertStringContainsString('data-hb-post-popup-trigger="slug"', $html);

        // 2026-08-11 (docs/seo-system.md §3) — the SEO/Social panel's own URL Slug field
        // shares this SAME marker (a second mirrored instance, not a second write path — see
        // panel-seo-social.blade.php's own docblock). This pins the SUMMARY popup's own
        // wrapper shape specifically, then the real <input> nested inside it.
        $marker = 'class="hb-pop hb-post-pop hb-post-slugpop" data-hb-post-slug-input';
        $this->assertStringContainsString($marker, $html);
        $slugPopup = substr($html, (int) strpos($html, $marker));
        $slugInputTag = substr($slugPopup, (int) strpos($slugPopup, '<input'));
        $slugInputTag = substr($slugInputTag, 0, (int) strpos($slugInputTag, '>'));
        $this->assertStringContainsString('hb-post-slugpop__input', $slugInputTag);
        $this->assertStringContainsString('disabled', $slugInputTag);
    }

    public function test_the_publish_rows_date_input_is_present_and_hidden_only_while_scheduled(): void
    {
        $html = $this->editorHtml();

        $this->assertStringContainsString('data-hb-post-published-input', $html);
        $this->assertStringContainsString('data-hb-post-publish-row', $html);
        $this->assertStringContainsString('data-hb-post-popup-trigger="publish"', $html);

        // A blank /editor document is a draft (never `scheduled`), so the publish-date row must
        // render VISIBLE, not hidden — only the schedule row (draft's own default) is hidden.
        $publishRow = substr($html, (int) strpos($html, 'data-hb-post-publish-row'));
        $publishRow = substr($publishRow, 0, (int) strpos($publishRow, '>'));
        $this->assertStringNotContainsString('hidden', $publishRow);
    }

    // ── Post → Summary rows are plain text + anchored popups (2026-08-11) ────────────

    public function test_summary_rows_render_as_plain_text_with_popup_triggers(): void
    {
        $html = $this->editorHtml();

        foreach (['status', 'slug', 'publish', 'schedule'] as $name) {
            $this->assertStringContainsString('data-hb-post-popup-trigger="' . $name . '"', $html);
            $this->assertStringContainsString('data-hb-post-popup="' . $name . '"', $html);
        }

        // A never-saved document has no publish date yet — the row falls back to the plain-
        // text design's own "Immediately" empty state.
        $this->assertStringContainsString(__('heisenberg::editor.inspector.summary_immediately'), $html);
    }

    public function test_the_date_picker_component_renders_its_grid_time_and_footer_hooks(): void
    {
        $html = $this->editorHtml();

        foreach ([
            'data-hb-datepicker', 'data-hb-dtp-value', 'data-hb-dtp-label', 'data-hb-dtp-grid',
            'data-hb-dtp-hour', 'data-hb-dtp-minute', 'data-hb-dtp-today', 'data-hb-dtp-clear',
            'data-hb-dtp-nav="prev-year"', 'data-hb-dtp-nav="prev-month"',
            'data-hb-dtp-nav="next-month"', 'data-hb-dtp-nav="next-year"',
        ] as $hook) {
            $this->assertStringContainsString($hook, $html, "date-picker is missing its {$hook} hook");
        }

        // Two independent instances (Publish date + Schedule), each with its own hidden value.
        $this->assertSame(
            2,
            substr_count($html, 'data-hb-dtp-value value="'),
            'expected two date-picker instances (publish + schedule)',
        );
    }

    // ── Post → Featured image placeholder/preview are mutually exclusive (2026-08-12) ──
    // The empty-state trigger only ever hid itself client-side, on a pick/replace/remove — the
    // initial server render never checked postFeaturedImage, so a post that already had one
    // rendered BOTH the "Set featured image" placeholder and the real preview card stacked on
    // top of each other. See post-title-summary.blade.php's data-hb-featured-trigger button.

    public function test_a_post_with_a_featured_image_renders_the_preview_card_and_hides_the_placeholder(): void
    {
        $this->app['env'] = 'local';
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);

        $post = \Heisenberg\Models\Post::create(['title_en' => 'X', 'status' => 'draft']);
        $file = \Heisenberg\Models\PublicFile::create([
            'type' => 'jpg',
            'disk' => 'uploads',
            'stored_path' => 'media/2026/08/featured-' . uniqid('', true) . '.jpg',
            'original_name' => 'featured.jpg',
            'stored_name' => 'featured.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 1024,
        ]);
        $post->featured_image_id = $file->id;
        $post->save();

        $html = $this->get("/editor/{$post->id}")->getContent();

        $triggerTag = substr($html, (int) strpos($html, 'data-hb-featured-trigger'));
        $triggerTag = substr($triggerTag, 0, (int) strpos($triggerTag, '>') + 1);
        $this->assertNotSame('', $triggerTag, 'the featured-image trigger never rendered');
        $this->assertStringContainsString('hidden', $triggerTag, 'a post with a featured image must not also render the empty-state placeholder');

        $previewTag = substr($html, (int) strpos($html, 'data-hb-featured-preview'));
        $previewTag = substr($previewTag, 0, (int) strpos($previewTag, '>') + 1);
        $this->assertNotSame('', $previewTag, 'the featured-image preview never rendered');
        $this->assertStringNotContainsString('hidden', $previewTag, 'a post with a featured image must show the preview card');

        $this->assertStringContainsString('src="' . $file->url . '"', $html);
    }

    public function test_a_post_without_a_featured_image_renders_the_placeholder_and_hides_the_preview_card(): void
    {
        $this->app['env'] = 'local';
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);

        $post = \Heisenberg\Models\Post::create(['title_en' => 'X', 'status' => 'draft']);

        $html = $this->get("/editor/{$post->id}")->getContent();

        $triggerTag = substr($html, (int) strpos($html, 'data-hb-featured-trigger'));
        $triggerTag = substr($triggerTag, 0, (int) strpos($triggerTag, '>') + 1);
        $this->assertNotSame('', $triggerTag, 'the featured-image trigger never rendered');
        $this->assertStringNotContainsString('hidden', $triggerTag, 'a post with no featured image must show the empty-state placeholder');

        $previewTag = substr($html, (int) strpos($html, 'data-hb-featured-preview'));
        $previewTag = substr($previewTag, 0, (int) strpos($previewTag, '>') + 1);
        $this->assertNotSame('', $previewTag, 'the featured-image preview never rendered');
        $this->assertStringContainsString('hidden', $previewTag, 'a post with no featured image must not also render the preview card');
    }

    // ── Post → Summary value styling (2026-08-12) ────────────────────────────────
    // Smaller font (owner-reported: the Publish date truncated mid-string), no underline on
    // hover, and hover recolors to the SAME --hb-editing token every other interactive/active
    // surface in the editor already uses (block selection outline, toolbar chrome, empty-state
    // hover borders) — never a new hardcoded hex.

    public function test_summary_value_buttons_use_the_editing_token_on_hover_with_no_underline(): void
    {
        $html = $this->editorHtml();

        $this->assertStringContainsString(
            ".hb-post-meta__value--btn:not(:disabled):hover { color: var(--hb-editing); }",
            $html,
        );
        $this->assertStringNotContainsString('.hb-post-meta__value--btn:hover { text-decoration: underline; }', $html);
    }

    public function test_summary_value_buttons_set_their_own_font_size_rather_than_inheriting_it(): void
    {
        $html = $this->editorHtml();

        // Both the span and the button carry the size explicitly. The button MUST NOT use the
        // `font` shorthand with `inherit`: it comes after the base class at equal specificity, so
        // it silently resets font-size back to the panel's inherited size and every edit to the
        // base rule becomes a no-op (the bug this pins).
        $this->assertMatchesRegularExpression('/\.hb-post-meta__value \{[^}]*font-size: 11px;/', $html);
        $this->assertMatchesRegularExpression('/\.hb-post-meta__value--btn \{[^}]*font-size: 11px;/s', $html);
        $this->assertDoesNotMatchRegularExpression('/\.hb-post-meta__value--btn \{[^}]*font: inherit;/s', $html);
    }

    // ── Post → Move to trash (2026-08-14) ─────────────────────────────────────────
    // The button used to be pure decoration: no data attribute, no listener, no endpoint. This
    // pins that it now actually carries its endpoint/confirm hooks and the same
    // disabled-before-first-save posture as every other post-scoped control.

    /** The button's own opening tag — `data-hb-post-trash` NOT followed by `-` (the wrapping
     *  `-row` div and the label/cancel elements share the same prefix). */
    private function trashButtonTag(string $html): string
    {
        preg_match('/<button[^>]*\sdata-hb-post-trash(?!-)[^>]*>/', $html, $m);

        return $m[0] ?? '';
    }

    public function test_the_trash_button_carries_its_endpoint_and_is_disabled_before_the_first_save(): void
    {
        $html = $this->editorHtml();

        $buttonTag = $this->trashButtonTag($html);
        $this->assertNotSame('', $buttonTag, 'the trash button never rendered');

        $this->assertStringContainsString('data-hb-trash-url-template="', $buttonTag);
        $this->assertStringContainsString('__ID__', $buttonTag, 'the trash endpoint must be an __ID__ template, like every other post-scoped url here');
        $this->assertStringContainsString('data-hb-editor-index-url="', $buttonTag, 'no post-navigate-away target on success');
        $this->assertStringContainsString('data-hb-confirm-label="', $buttonTag);

        // A blank /editor document has no id yet — same disabled-until-hb:post-id posture the
        // Summary status control's own pin (above) asserts.
        $this->assertStringContainsString('disabled', $buttonTag);
        $this->assertStringContainsString(
            'title="' . e(__('heisenberg::editor.inspector.post_move_trash_save_first')) . '"',
            $buttonTag,
        );
    }

    public function test_the_trash_button_wires_a_two_step_confirm_never_window_confirm(): void
    {
        $html = $this->editorHtml();

        // The Cancel affordance ships hidden, revealed only once the button arms.
        $this->assertStringContainsString('data-hb-post-trash-cancel', $html);
        $cancelTag = substr($html, (int) strpos($html, 'data-hb-post-trash-cancel'));
        $cancelTag = substr($cancelTag, 0, (int) strpos($cancelTag, '>') + 1);
        $this->assertStringContainsString('hidden', $cancelTag);

        // 2026-08-15: scoped to the trash button's own script so a delete-confirm in an unrelated
        // component (e.g. saved-blocks delete in block-runtime) doesn't trip this. The trash
        // affordance specifically uses a two-step reveal, not a browser dialog. Slicing on
        // </script> keeps the check inside this one script block — the page has many more
        // <script>s after it.
        $trashMarker = strpos($html, 'data-hb-post-trash]');
        $this->assertIsInt($trashMarker, 'the trash button script never rendered');
        $scriptEnd = strpos($html, '</script>', $trashMarker);
        $this->assertIsInt($scriptEnd, 'the trash button script tag never closed');
        $trashScript = substr($html, $trashMarker, $scriptEnd - $trashMarker);
        $this->assertStringNotContainsString('window.confirm', $trashScript);

        // hb:post-id (fired on the first save) is what lifts the disabled state and learns the
        // real post id — same contract revisions-dialog.blade.php's own row uses.
        $this->assertStringContainsString('__hbPostTrashPostId', $html);
    }

    public function test_the_trash_button_dispatches_the_shared_save_state_channel_on_failure(): void
    {
        $html = $this->editorHtml();

        // Scoped to the trash-button's own script (its unique marker) so this pins THAT script's
        // behaviour, not just that the hb:save-state string exists somewhere else on the page
        // (topbar.blade.php's save wiring already emits it too).
        $marker = strpos($html, 'data-hb-post-trash]');
        $this->assertIsInt($marker, 'the trash button script never rendered');
        $script = substr($html, $marker);

        // Failures must surface through the SAME channel every other save/tool failure uses
        // (footer.blade.php's save-status pill), not a bespoke one only this button understands.
        $this->assertStringContainsString("CustomEvent('hb:save-state'", $script);
        $this->assertStringContainsString("state: 'error'", $script);
        $this->assertStringContainsString("state: 'saving'", $script);
    }
}
