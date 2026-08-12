{{-- live/inspector — the right sidebar, 260px, border-left. Structure:
     Insp Tabs (Post|Block, reuses ui/panel-tabs — verified its existing active/inactive CSS already
     handles either tab being active, no changes needed), a block-preview header row (icon+name+
     description, synced from the selected block's contract — see the script below), then
     Content|Style|Advanced sub-tabs (reuses ui/sub-tabs as-is, same icon set already extracted).

     The Block-tab body renders from the selected block's contract (no longer an empty shell — see
     `hb:block-selected` below). Since Blade can't re-run from client JS, the trick is: this component
     receives the full `$registry` (the same clientBlocks()-shaped map block-runtime.blade.php gets,
     now also threaded onto <x-live.inspector> in editor/index.blade.php) and pre-renders EVERY
     registered block's Content/Style/Advanced panels once, up front, each wrapped in
     [data-hb-block-panel="<name>"] and hidden. Selecting a block is then just: show that one block's
     three panels (per the active sub-tab) and sync the real current attribute/supports values into
     their already-Blade-rendered inputs — no HTML is ever built from a JS string. `live/block/content`,
     `live/block/style-panel`, `live/block/advanced` (and the `live/block/style/*` sub-panels, plus the
     shared `live/block/control-row` + `live/block/control-list`) are what actually turn a control's
     derived shape (BlockRegistryService::deriveControls/deriveSupportControls/derivePanels) into a
     labelled ui/* primitive; this file only wires selection -> visibility + value sync, and the single
     delegated input/change listener that writes an edit back through window.hbEditor.

     Two write paths, one per branch, both owned by the runtime: Content/Advanced controls are
     attribute-keyed and go through `window.hbEditor.setAttribute(id, key, value)`; Style controls
     are supports-keyed (e.g. `supports.color.text`) and go through its counterpart
     `window.hbEditor.setSupport(id, path, value)`, which walks the dotted path. Both own their own
     re-render and fire `hb:block-updated` + `hb:blocks-changed`, so nothing here mutates a model
     directly and the two branches cannot drift. Style writes paint via the contract's
     `style.variables` plus the SupportsStyle capability stylesheet (gated on the `hb-supports`
     class from `style.className`, which the runtime applies to the block root).

     Post tab — title field, Featured image (collapsible, reuses
     ui/disclosure-row), Summary (collapsible: 6 label/value meta rows), Pending-review + Stick-to-top
     toggles (reuse ui/toggle — the source instances these in their OFF state, with a real fill/knob-x
     override that corrects/confirms ui/toggle's previously-inferred off-state styling), Move to trash,
     Categories/Tags (a shared multi-select ui/checkbox list — see wirePostTaxonomy() below), Discussion
     (an Allow-comments ui/toggle) and Page layout (X/Y page padding via two ui/slider controls). The
     Excerpt row from the original fixture-driven pass was removed 2026-08-03 — panel-seo-social's own
     meta-description field already covers the same "short summary" need, and keeping both was pure
     redundancy. Both Post and Block content toggle on the same panel-tabs 'change' event. --}}
@once
<style>
    .hb-inspector {
        display: flex;
        flex-direction: column;
        width: 260px;
        height: 100%;
        background: var(--hb-bg, #fff);
        border-left: 1px solid var(--hb-border, #E4E4E4);
        flex: none;
    }
    .hb-inspector__header {
        display: flex;
        flex-direction: column;
        gap: 6px;
        padding: 16px 14px 14px;
        flex: none;
    }
    .hb-inspector__title-row { display: flex; align-items: center; gap: 8px; }
    .hb-inspector__icon { display: inline-flex; width: 22px; height: 22px; color: var(--hb-text-primary, #0A0A0A); flex: none; }
    /* Author `display:inline-flex` beats the UA stylesheet's `[hidden]` at equal specificity —
       same fix as .hb-section__body[hidden] in ui/panel-section.blade.php; several per-block
       icons share this class and only one is ever unhidden at a time (see the script below). */
    .hb-inspector__icon[hidden] { display: none; }
    .hb-inspector__name {
        font-family: var(--hb-font-sans, Rubik, sans-serif);
        font-size: var(--hb-fs-md, 14px);
        font-weight: 500;
        color: var(--hb-text-primary, #0A0A0A);
    }
    .hb-inspector__desc {
        font-family: var(--hb-font-sans, Rubik, sans-serif);
        font-size: var(--hb-fs-sm, 12px);
        line-height: 1.4;
        color: var(--hb-text-muted, #9A9A9A);
    }
    .hb-inspector__block-content,
    .hb-inspector__post-content { display: flex; flex-direction: column; flex: 1 1 auto; min-height: 0; overflow: hidden; }
    /* The Post tab's own scroll region. It was `overflow: hidden` with no inner scroller, so
       everything past the fold (the meta rows, toggles, Move to trash, and all five navigation
       rows) was clipped and unreachable at normal viewport heights. position: relative anchors
       the custom scrollbar to it, matching the pattern every left panel uses. */
    .hb-inspector__post-content { position: relative; }
    .hb-inspector__post-body { display: flex; flex-direction: column; flex: 1 1 auto; min-height: 0; overflow: hidden; }
    /* Disclosure headers and navigation rows have an authored fixed height. They are direct flex
       children here, so they must not shrink when an opened Post disclosure changes the body height:
       the custom scrollbar should receive overflow instead. */
    .hb-inspector__post-body > * { flex: none; }
    .hb-inspector__block-content[hidden],
    .hb-inspector__post-content[hidden] { display: none; }
    /* The populated (sub-tabs + body) group continues the same flex column the block-content
       wrapper starts, so .hb-inspector__body's own flex/overflow below still works for scrolling
       one level deeper than before the empty-state placeholder was added. */
    .hb-inspector__populated { display: flex; flex-direction: column; flex: 1 1 auto; min-height: 0; }
    .hb-inspector__populated[hidden] { display: none; }
    /* The body itself does NOT scroll — it's just the flex column the three sub-panels live in.
       Each sub-panel is its own scroll region (below) so Content/Style/Advanced keep independent
       scroll offsets; one shared scroller would carry the Style tab's offset over to Content. */
    .hb-inspector__body { flex: 1 1 auto; min-height: 0; display: flex; flex-direction: column; overflow: hidden; position: relative; }
    /* Per-sub-tab scroll region. `overflow: hidden` + `position: relative` is the shape every
       other panel uses (.hb-panel-seo__content, .hb-panel-cb__body): ui/custom-scrollbar sets
       overflowY itself on boot and needs a positioned container to anchor its absolute track to. */
    .hb-inspector__body > [data-hb-subpanel] { flex: 1 1 auto; min-height: 0; overflow: hidden; position: relative; }
    .hb-inspector__empty {
        display: flex;
        align-items: center;
        justify-content: center;
        flex: 1 1 auto;
        min-height: 0;
        padding: var(--hb-space-3, 12px);
        text-align: center;
        font-family: var(--hb-font-sans, Rubik, sans-serif);
        font-size: var(--hb-fs-sm, 12px);
        color: var(--hb-text-muted, #9A9A9A);
    }
    .hb-inspector__empty[hidden] { display: none; }

    .hb-post-title {
        display: flex;
        flex-direction: column;
        gap: var(--hb-space-3, 12px);
        padding: var(--hb-space-3, 12px);
        border-bottom: 1px solid var(--hb-border, #E4E4E4);
        flex: none;
    }
    .hb-post-title__eyebrow {
        font-family: var(--hb-font-sans, Rubik, sans-serif);
        font-size: var(--hb-fs-xs, 11px);
        font-weight: 500;
        letter-spacing: .5px;
        color: var(--hb-text-muted, #9A9A9A);
    }
    .hb-post-title__row { display: flex; align-items: center; gap: 8px; }
    .hb-post-title__icon { display: inline-flex; width: 15px; height: 15px; color: var(--hb-text-secondary, #5A5A5A); flex: none; }
    .hb-post-title__value { font-family: var(--hb-font-sans, Rubik, sans-serif); font-size: var(--hb-fs-base, 13px); font-weight: 500; color: var(--hb-text-secondary, #5A5A5A); }
    .hb-post-title__input { flex: 1 1 auto; min-width: 0; border: 0; outline: 0; background: none; font-family: var(--hb-font-sans, Rubik, sans-serif); font-size: var(--hb-fs-base, 13px); font-weight: 500; color: var(--hb-text-primary, #0A0A0A); }
    .hb-post-title__input::placeholder { color: var(--hb-text-muted, #9A9A9A); font-weight: 500; }

    .hb-post-dropzone-wrap { padding: var(--hb-space-3, 12px); border-bottom: 1px solid var(--hb-border, #E4E4E4); flex: none; }
    .hb-post-dropzone {
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        gap: 8px;
        height: 94px;
        width: 100%;
        border: 1px solid var(--hb-border-strong, #C8C8C8);
        border-radius: var(--hb-radius-md, 5px);
        background: var(--hb-bg-subtle, #FAFAFA);
    }
    .hb-post-dropzone__icon { display: inline-flex; width: 28px; height: 28px; color: var(--hb-text-muted, #9A9A9A); }
    .hb-post-dropzone__label { font-family: var(--hb-font-sans, Rubik, sans-serif); font-size: var(--hb-fs-sm, 12px); color: var(--hb-text-muted, #9A9A9A); }

    .hb-post-meta { display: flex; flex-direction: column; padding: 6px 0; flex: none; }
    .hb-post-meta__row { display: flex; align-items: center; justify-content: space-between; height: 32px; padding: 0 14px; }
    .hb-post-meta__label { font-family: var(--hb-font-sans, Rubik, sans-serif); font-size: var(--hb-fs-sm, 12px); color: var(--hb-text-secondary, #5A5A5A); }
    .hb-post-meta__value { font-family: var(--hb-font-sans, Rubik, sans-serif); font-size: var(--hb-fs-xs, 11px); font-weight: 500; color: var(--hb-text-primary, #0A0A0A); }
    .hb-post-meta__value--btn {
        max-width: 175px;
        border: 0;
        background: none;
        padding: 0;
        margin: 0;
        font: inherit;
        text-align: right;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        cursor: pointer;
    }
    .hb-post-meta__value--btn:not(:disabled):hover { color: var(--hb-editing, #3D68F5); }
    .hb-post-meta__value--btn:disabled { opacity: .5; cursor: not-allowed; }

    .hb-post-popup { position: fixed; z-index: 1000; }
    .hb-post-popup[hidden] { display: none !important; }
    .hb-post-pop { width: 236px; box-shadow: 3px 4px 4px rgba(0, 0, 0, .1); }

    .hb-post-slugpop { display: flex; flex-direction: column; gap: 6px; padding: 12px; }
    .hb-post-slugpop__label { font-size: var(--hb-fs-xs, 11px); font-weight: 600; color: var(--hb-text-secondary, #5A5A5A); }
    .hb-post-slugpop__input {
        height: 30px;
        box-sizing: border-box;
        padding: 0 8px;
        border: 1px solid var(--hb-border, #E4E4E4);
        border-radius: var(--hb-radius-md, 5px);
        background: var(--hb-bg, #fff);
        font-family: inherit;
        font-size: var(--hb-fs-sm, 12px);
        color: var(--hb-text-primary, #0A0A0A);
    }
    .hb-post-slugpop__input:focus { outline: 0; border-color: var(--hb-border-focus, #000); }
    .hb-post-slugpop__input:disabled { opacity: .5; cursor: not-allowed; }

    .hb-post-toggles { display: flex; flex-direction: column; padding: 6px 0; flex: none; }
    .hb-post-toggle-row { display: flex; align-items: center; justify-content: space-between; gap: 12px; height: 34px; padding: 0 14px; }
    .hb-post-toggle-row__label { font-family: var(--hb-font-sans, Rubik, sans-serif); font-size: var(--hb-fs-sm, 12px); color: var(--hb-text-secondary, #5A5A5A); }

    .hb-post-divider { border: 0; border-top: 1px solid var(--hb-border, #E4E4E4); width: 100%; margin: 0; flex: none; }

    .hb-post-trash {
        display: flex;
        align-items: center;
        gap: 8px;
        width: 100%;
        height: 44px;
        padding: 0 var(--hb-space-3, 12px);
        border: 0;
        background: transparent;
        cursor: pointer;
        text-align: left;
        flex: none;
    }
    .hb-post-trash:hover { background: var(--hb-surface-hover, #F7F7F7); }
    .hb-post-trash__icon { display: inline-flex; width: 15px; height: 15px; color: var(--hb-danger, #D4191A); flex: none; }
    .hb-post-trash__label { font-family: var(--hb-font-sans, Rubik, sans-serif); font-size: var(--hb-fs-sm, 12px); font-weight: 500; color: var(--hb-danger, #D4191A); }
</style>
<script>
    (() => {
        const boot = () => {
            document.querySelectorAll('[data-hb-inspector]').forEach((root) => {
                if (root.__hbInspector) return;
                const tabs = root.querySelector('[data-hb-tablist]');
                const postContent = root.querySelector('[data-hb-inspector-post-content]');
                const blockContent = root.querySelector('[data-hb-inspector-block-content]');
                tabs?.addEventListener('change', (event) => {
                    if (postContent) postContent.hidden = event.detail.index !== 0;
                    if (blockContent) blockContent.hidden = event.detail.index !== 1;
                });

                // Content|Style|Advanced sub-tabs — same toggle pattern as Post|Block above.
                // Scoped to blockContent's own tablist (the sub-tabs), not the Post|Block one,
                // since blockContent only contains the former.
                const subTabs = blockContent ? blockContent.querySelector('[data-hb-tablist]') : null;
                const subPanels = blockContent ? blockContent.querySelectorAll('[data-hb-subpanel]') : [];
                const subNames = ['content', 'style', 'advanced'];
                subTabs?.addEventListener('change', (event) => {
                    const active = subNames[event.detail.index] || 'content';
                    subPanels.forEach((panel) => { panel.hidden = panel.getAttribute('data-hb-subpanel') !== active; });
                });

                root.__hbInspector = true;
            });
        };
        if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot, { once: true });
        else boot();
        document.addEventListener('hb:refresh', boot);
    })();
</script>
@endonce

@props([
    'panelTabs' => [
        ['label' => __('heisenberg::editor.inspector.tab_post')],
        ['label' => __('heisenberg::editor.inspector.tab_block')],
    ],
    'panelActiveIndex' => 0,
    // The full client registry (BlockViewData::clientBlocks() — same shape block-runtime.blade.php
    // gets as `$registry`), keyed by block name. Used only to pre-render every block type's
    // Content/Style/Advanced panels once (see the Block-tab body below); nothing here talks to it
    // beyond that loop.
    'registry' => [],
    'postTitle' => '',
    // Real rows come from EditorController::postMeta() (status/publish/url/blocks, each with
    // a `key` the live-update script below refreshes on save). This default only covers test
    // fixtures that mount the component bare.
    'postMeta' => [],
    // The FULL config('heisenberg.lifecycle.transitions') map + a translated status-name
    // lookup (EditorController::sharedViewData()) — the status select's client-side option
    // rebuild reads these after every save; never hardcoded here. postScheduledAt seeds the
    // schedule <input type="datetime-local"> ("Y-m-d\TH:i", already formatted server-side).
    'postStatusTransitions' => [],
    'postStatusLabels' => [],
    'postScheduledAt' => null,
    // Seeds the Summary's always-editable publish-date <input type="datetime-local">
    // (postPublishedAt) — see postScheduledAt's own docblock above for the format.
    'postPublishedAt' => null,
    'postPendingReview' => false,
    'postStickToTop' => false,
    // Post tab Categories/Tags (shared multi-select checklist; attach/detach URLs use the
    // __ID__/__ITEM_ID__ placeholder convention) plus Page layout/Discussion.
    'postId' => null,
    'postCategoryIds' => [],
    'postTagIds' => [],
    'categoryOptions' => [],
    'tagOptions' => [],
    'categoriesIndexUrl' => '',
    'tagsIndexUrl' => '',
    'postCategoryUrlTemplate' => '',
    'postTagsUrlTemplate' => '',
    'postPagePaddingX' => 56,
    'postPagePaddingY' => 56,
    'postLayoutUrlTemplate' => '',
    'postAllowComments' => true,
    'postDiscussionUrlTemplate' => '',
    'postRevisionsUrlTemplate' => '',
    // The Post tab's Featured image (set on Post::featuredImage BelongsTo). Seeded from
    // EditorController::show() for an existing post; null for /editor (blank document). The
    // inspector's script calls updateFeaturedImage() on every pick/remove so the change
    // persists across reloads.
    'postFeaturedImage' => null,
    'postFeaturedImageUrlTemplate' => '',
    // The Post tab's authored table of contents (Post::tocEntries(), {label, anchor} pairs only —
    // see EditorController::show()). Empty for /editor's blank document. The disclosure row below
    // just renders a summary + Edit trigger; live/toc-dialog.blade.php owns the whole editing
    // surface (add/reorder/remove/load-from-headings/save).
    'postTocEntries' => [],
    'postTocUrlTemplate' => '',
    // The Post tab's Translations section (docs/content-translation.md §5, Wave T2a) — one row
    // per configured locale, {locale, status, post_id} (TranslationStatusService::statuses()).
    // Null for /editor's blank document (nothing to translate yet — the section renders a muted
    // "save first" line instead of the row list); EditorController::show() seeds the real array.
    'postTranslations' => null,
    'postTranslationsUrlTemplate' => '',
    'postEditorUrlTemplate' => '',
    'blockIcon' => '',
    'blockName' => __('heisenberg::editor.common.no_block_selected_title'),
    'blockDescription' => __('heisenberg::editor.common.no_block_selected_desc'),
    'subTabs' => [
        ['value' => 'content', 'icon' => 'browsers-fill', 'label' => __('heisenberg::editor.inspector.subtab_content')],
        ['value' => 'style', 'icon' => 'paint-brush-fill', 'label' => __('heisenberg::editor.inspector.subtab_style')],
        ['value' => 'advanced', 'icon' => 'gear-fill', 'label' => __('heisenberg::editor.inspector.subtab_advanced')],
    ],
    'subActiveIndex' => 0,
    // GET /editor/fonts (FontController::search) — the Style tab's Typography font field is a
    // ui/combobox against the vendored Google Fonts catalog, same endpoint and paging contract
    // the left sidebar's Style tab uses. Empty string disables the search rather than 404ing.
    'fontsSearchUrl' => '',
    // The raw user theme (ThemeRepository::load()), feeding the Block.style theme-variable
    // pickers. Deliberately NOT tokens(): that map is keyed by CSS reference, which made every
    // menu row read as "var(--hb-t-accent-1)" rather than the name the user gave the token.
    'theme' => [],
    // docs/email-system.md §3/§7-E3: Featured image, Discussion and Table of contents are
    // meaningless for an email document and are not rendered at all when this is 'email' — see
    // the conditional guards around each below. Summary (status/schedule/slug) and Translations stay;
    // slug is still the document's identifier and emails translate like posts.
    'documentType' => 'post',
])
<aside data-hb-inspector data-hb-fonts-search-url="{{ $fontsSearchUrl }}" {{ $attributes->merge(['class' => 'hb-inspector']) }}>
    <x-ui.panel-tabs :items="$panelTabs" :active-index="$panelActiveIndex" />

    <div class="hb-inspector__post-content" data-hb-inspector-post-content @if ($panelActiveIndex !== 0) hidden @endif>
        @include('heisenberg::components.live.inspector.post-title-summary')
        @include('heisenberg::components.live.inspector.post-meta-live-script')
        @include('heisenberg::components.live.inspector.post-taxonomy-toc')
        @include('heisenberg::components.live.inspector.featured-image-behavior')

        @include('heisenberg::components.live.inspector.taxonomy-behavior')
    </div>
    @include('heisenberg::components.live.inspector.block-tab')
</aside>

{{-- Block-tab selection -> visibility + value sync, and the single delegated input/change
     listener that writes an edit back through window.hbEditor. See this file's docblock for
     the overall design (pre-rendered-per-block-type panels, synced not rebuilt) and the
     documented setAttribute gap this works around for Style (supports-keyed) controls. --}}
@once
<script>
(() => {
    if (document.__hbInspectorBinding) return;
    document.__hbInspectorBinding = true;

    @include('heisenberg::components.live.inspector.script-controls-sync')
    @include('heisenberg::components.live.inspector.script-spacing-controls')
    @include('heisenberg::components.live.inspector.script-color-layers')
    @include('heisenberg::components.live.inspector.script-fonts-and-style-events')
})();
</script>
@endonce
