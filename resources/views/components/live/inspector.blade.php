{{-- live/inspector — from Pencil Block (BfaTx): the right sidebar, 260px, border-left. Structure:
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
     directly and the two branches cannot drift. KNOWN GAP: several
     Style panels (Position, Flex Layout, Appearance-opacity, Effects, per-side Border overrides) write
     to a `supports.*` path with no matching `contract.style.variable` and no bespoke handling in
     block-runtime's renderer (only `contract.style.variables` and the `align` support are read back
     into the live canvas preview) — those writes succeed but the canvas does not visibly update.
     Flagged per-panel in `live/block/style/*.blade.php`.

     Post tab (from Pencil Post, vcSGe) — title field, Featured image (collapsible, reuses
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
        font-size: 15px;
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
        font-size: 10px;
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
    .hb-post-meta__value { font-family: var(--hb-font-sans, Rubik, sans-serif); font-size: var(--hb-fs-sm, 12px); font-weight: 500; color: var(--hb-text-primary, #0A0A0A); }

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
    'postMeta' => [
        ['label' => 'Visibility', 'value' => 'Public'],
        ['label' => 'Publish', 'value' => 'Immediately'],
        ['label' => 'URL', 'value' => '/untitled'],
        ['label' => 'Author', 'value' => 'Ava Mercer'],
        ['label' => 'Template', 'value' => 'Single Post'],
        ['label' => 'Format', 'value' => 'Standard'],
    ],
    'postPendingReview' => false,
    'postStickToTop' => false,
    // Post tab Categories/Tags (Phase 3.1; reworked 2026-08-03 into a shared multi-select
    // checklist — see EditorController::sharedViewData()'s own docblock on the attach/detach URL
    // templates' __ID__/__ITEM_ID__ placeholder convention) plus Page layout/Discussion (also new
    // 2026-08-03 — see PostSettingsController).
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
])
<aside data-hb-inspector data-hb-fonts-search-url="{{ $fontsSearchUrl }}" {{ $attributes->merge(['class' => 'hb-inspector']) }}>
    <x-ui.panel-tabs :items="$panelTabs" :active-index="$panelActiveIndex" />

    <div class="hb-inspector__post-content" data-hb-inspector-post-content @if ($panelActiveIndex !== 0) hidden @endif>
      <div class="hb-inspector__post-body" data-hb-inspector-post-body>
        <div class="hb-post-title">
            <span class="hb-post-title__eyebrow">{{ __('heisenberg::editor.inspector.post_title_eyebrow') }}</span>
            <span class="hb-post-title__row">
                <span class="hb-post-title__icon" aria-hidden="true">
                    @include('heisenberg::components.ui.icon', ['name' => 'note', 'size' => 15])
                </span>
                <input type="text" class="hb-post-title__input" data-hb-title value="{{ $postTitle }}" placeholder="{{ __('heisenberg::editor.inspector.post_title_placeholder') }}" aria-label="{{ __('heisenberg::editor.inspector.post_title_label') }}">
            </span>
        </div>

        <x-ui.disclosure-row icon="image" :label="__('heisenberg::editor.inspector.post_featured_image')" chevron="down" />
        <div class="hb-post-dropzone-wrap" data-hb-disclosure-body data-hb-featured-field>
            {{-- A real focusable control (native <button>, not a bare div) — opens the media
                 dialog below. Hidden once an image is picked, swapping for the preview block. --}}
            <button type="button" class="hb-post-dropzone" data-hb-featured-trigger aria-haspopup="dialog" aria-label="{{ __('heisenberg::editor.inspector.post_featured_set') }}">
                <span class="hb-post-dropzone__icon" aria-hidden="true">
                    @include('heisenberg::components.ui.icon', ['name' => 'image', 'size' => 28])
                </span>
                <span class="hb-post-dropzone__label">{{ __('heisenberg::editor.inspector.post_featured_set') }}</span>
            </button>
            <div class="hb-post-dropzone-preview" data-hb-featured-preview hidden>
                <img class="hb-post-dropzone-preview__img" data-hb-featured-img alt="{{ __('heisenberg::editor.inspector.post_featured_image') }}">
                <div class="hb-post-dropzone-preview__actions">
                    <button type="button" class="hb-post-dropzone-preview__btn" data-hb-featured-replace aria-label="{{ __('heisenberg::editor.inspector.post_featured_replace') }}">
                        @include('heisenberg::components.ui.icon', ['name' => 'arrows-clockwise', 'size' => 14])
                    </button>
                    <button type="button" class="hb-post-dropzone-preview__btn hb-post-dropzone-preview__btn--danger" data-hb-featured-remove aria-label="{{ __('heisenberg::editor.inspector.post_featured_remove') }}">
                        @include('heisenberg::components.ui.icon', ['name' => 'trash', 'size' => 14])
                    </button>
                </div>
            </div>
            {{-- No post-persistence layer exists in this package yet (no save endpoint at all —
                 see the featured-image script below for the full explanation). These hidden
                 inputs are the documented client-side home for the pick, mirroring data-hb-title
                 above: readable by the rest of the app, but in-memory only — they do NOT survive
                 a reload. --}}
            <input type="hidden" data-hb-featured-image-id value="">
            <input type="hidden" data-hb-featured-image-url value="">
            @php
                $hbFeaturedSelectUrl = \Illuminate\Support\Facades\Route::has('media.select') ? route('media.select') : null;
                $hbFeaturedUploadUrl = \Illuminate\Support\Facades\Route::has('media.upload') ? route('media.upload') : null;
            @endphp
            <x-live.media.media-dialog
                data-hb-featured-dialog
                hidden
                :scrim="true"
                tab="library"
                accept="image/*"
                title="Select Featured Image"
                :select-url="$hbFeaturedSelectUrl"
                :upload-url="$hbFeaturedUploadUrl"
            />
        </div>

        <x-ui.disclosure-row icon="file-text" :label="__('heisenberg::editor.inspector.post_summary')" chevron="down" />
        <div data-hb-disclosure-body>
            <div class="hb-post-meta">
                @foreach ($postMeta as $row)
                    <div class="hb-post-meta__row">
                        <span class="hb-post-meta__label">{{ $row['label'] }}</span>
                        <span class="hb-post-meta__value">{{ $row['value'] }}</span>
                    </div>
                @endforeach
            </div>
            <hr class="hb-post-divider">
            <div class="hb-post-toggles">
                <div class="hb-post-toggle-row">
                    <span class="hb-post-toggle-row__label">{{ __('heisenberg::editor.inspector.post_pending_review') }}</span>
                    <x-ui.toggle :on="$postPendingReview" name="post-pending-review" />
                </div>
                <div class="hb-post-toggle-row">
                    <span class="hb-post-toggle-row__label">{{ __('heisenberg::editor.inspector.post_stick_top') }}</span>
                    <x-ui.toggle :on="$postStickToTop" name="post-stick-top" />
                </div>
            </div>
            <hr class="hb-post-divider">
            <button type="button" class="hb-post-trash">
                <span class="hb-post-trash__icon" aria-hidden="true">
                    @include('heisenberg::components.ui.icon', ['name' => 'trash', 'size' => 15])
                </span>
                <span class="hb-post-trash__label">{{ __('heisenberg::editor.inspector.post_move_trash') }}</span>
            </button>
        </div>

        {{-- Categories/Tags (Phase 3.1, 2026-08-04; reworked 2026-08-03 into ONE shared checklist —
             both Post::categories() and Post::tags() are now BelongsToMany via a real pivot, so
             there's no more single-vs-multi asymmetry to justify two different widgets (an earlier
             pass used a single-select checklist for Categories and a chip list for Tags; both are
             now the SAME multi-select ui/checkbox list, wired by the one wirePostTaxonomy()
             function below). Chevron is "down" (inline expansion, matching Featured Image/Summary
             above) rather than "right" (navigation to nowhere) now that there's real content.
             Checking a box POSTs an attach; unchecking DELETEs. The add-input above each list
             doubles as a filter over the already-loaded options (every category/tag is small in
             number and already server-rendered — nothing to remote-search, unlike Fonts) with
             Enter creating a new one when nothing matches. Both bodies render disabled until a
             real post id exists (data-hb-post-id, enabled by the script below on boot if $postId
             is already set, or on the hb:post-id event topbar fires after a new document's first
             save — PostCategoryController/PostTagController both require a persisted post). --}}
        <x-ui.disclosure-row icon="list-bullets" :label="__('heisenberg::editor.inspector.post_categories')" chevron="down" :expanded="false" persist-key="post-categories" />
        <div class="hb-post-taxonomy-body" data-hb-disclosure-body data-hb-post-taxonomy-field hidden
            data-hb-post-id="{{ $postId ?? '' }}"
            data-hb-attach-url-template="{{ $postCategoryUrlTemplate }}"
            data-hb-index-url="{{ $categoriesIndexUrl }}"
            data-hb-create-response-key="category">
            <div class="hb-post-taxonomy-list-wrap" data-hb-post-taxonomy-list-wrap>
                <div class="hb-post-taxonomy-list-scroll" data-hb-post-taxonomy-list-scroll>
                    <div class="hb-post-taxonomy-list" data-hb-post-taxonomy-list>
                        @foreach ($categoryOptions as $option)
                            <x-ui.checkbox class="hb-post-taxonomy-item" :value="$option['value']"
                                :checked="in_array($option['value'], $postCategoryIds, true)" :label="$option['label']" />
                        @endforeach
                        <span class="hb-post-taxonomy-empty" data-hb-post-taxonomy-empty @if (count($categoryOptions)) hidden @endif>{{ __('heisenberg::editor.inspector.post_category_empty') }}</span>
                    </div>
                </div>
                <x-ui.custom-scrollbar container="[data-hb-post-taxonomy-list-scroll]" />
            </div>
            {{-- Clone target for a JS-added item (a newly created category) — `label=" "` (a single
                 space, not empty) is load-bearing: ui/checkbox only renders its `.hb-checkbox__label`
                 span at all when `$label !== ''`, and addItem() below needs that span to exist so it
                 can overwrite its textContent — the space is replaced before the node is ever
                 inserted, so it's never actually visible. --}}
            <template data-hb-post-taxonomy-item-template>
                <x-ui.checkbox class="hb-post-taxonomy-item" value="" label=" " />
            </template>
            <input type="text" class="hb-post-taxonomy-add-input" data-hb-post-taxonomy-add-input
                placeholder="{{ __('heisenberg::editor.inspector.post_category_add_ph') }}"
                autocomplete="off" spellcheck="false" @if ($postId === null) disabled @endif>
            <span class="hb-post-taxonomy-hint" data-hb-post-taxonomy-hint @if ($postId !== null) hidden @endif>{{ __('heisenberg::editor.inspector.post_taxonomy_needs_save') }}</span>
        </div>

        <x-ui.disclosure-row icon="tag" :label="__('heisenberg::editor.inspector.post_tags')" chevron="down" :expanded="false" persist-key="post-tags" />
        <div class="hb-post-taxonomy-body" data-hb-disclosure-body data-hb-post-taxonomy-field hidden
            data-hb-post-id="{{ $postId ?? '' }}"
            data-hb-attach-url-template="{{ $postTagsUrlTemplate }}"
            data-hb-index-url="{{ $tagsIndexUrl }}"
            data-hb-create-response-key="tag">
            <div class="hb-post-taxonomy-list-wrap" data-hb-post-taxonomy-list-wrap>
                <div class="hb-post-taxonomy-list-scroll" data-hb-post-taxonomy-list-scroll>
                    <div class="hb-post-taxonomy-list" data-hb-post-taxonomy-list>
                        @foreach ($tagOptions as $option)
                            <x-ui.checkbox class="hb-post-taxonomy-item" :value="$option['value']"
                                :checked="in_array($option['value'], $postTagIds, true)" :label="$option['label']" />
                        @endforeach
                        <span class="hb-post-taxonomy-empty" data-hb-post-taxonomy-empty @if (count($tagOptions)) hidden @endif>{{ __('heisenberg::editor.inspector.post_tag_empty') }}</span>
                    </div>
                </div>
                <x-ui.custom-scrollbar container="[data-hb-post-taxonomy-list-scroll]" />
            </div>
            <template data-hb-post-taxonomy-item-template>
                <x-ui.checkbox class="hb-post-taxonomy-item" value="" label=" " />
            </template>
            <input type="text" class="hb-post-taxonomy-add-input" data-hb-post-taxonomy-add-input
                placeholder="{{ __('heisenberg::editor.inspector.post_tag_add_ph') }}"
                autocomplete="off" spellcheck="false" @if ($postId === null) disabled @endif>
            <span class="hb-post-taxonomy-hint" data-hb-post-taxonomy-hint @if ($postId !== null) hidden @endif>{{ __('heisenberg::editor.inspector.post_taxonomy_needs_save') }}</span>
        </div>

        {{-- Discussion (2026-08-03) — a single Allow-comments toggle. There is no per-post
             template assignment anywhere in the schema yet (post-templates are a registry/contract
             system, not yet linked to individual Post rows), so this can't read a "template
             default" the way its name might suggest — it's a plain per-post override, nullable in
             the DB (null = comments allowed), same disabled-until-saved posture as Categories/Tags
             above. See PostSettingsController::updateDiscussion(). --}}
        <x-ui.disclosure-row icon="chat-circle" :label="__('heisenberg::editor.inspector.post_discussion')" chevron="down" />
        <div class="hb-post-discussion-body" data-hb-disclosure-body data-hb-post-discussion-field
            data-hb-post-id="{{ $postId ?? '' }}"
            data-hb-discussion-url-template="{{ $postDiscussionUrlTemplate }}">
            <div class="hb-post-toggle-row">
                <span class="hb-post-toggle-row__label">{{ __('heisenberg::editor.inspector.post_allow_comments') }}</span>
                <x-ui.toggle data-hb-post-allow-comments :on="$postAllowComments" name="post-allow-comments" :disabled="$postId === null" />
            </div>
            <span class="hb-post-taxonomy-hint" data-hb-post-discussion-hint @if ($postId !== null) hidden @endif>{{ __('heisenberg::editor.inspector.post_taxonomy_needs_save') }}</span>
        </div>

        {{-- Page layout (2026-08-03) — the whole page's X/Y padding, nothing else (no per-side
             overrides): two ui/slider controls writing --hb-page-padding-x/-y (34-canvas.css) live
             as the sliders move, saved debounced via PostSettingsController::updateLayout(). See
             live/canvas.blade.php for the matching server-rendered initial value (avoids a
             flash-of-default-padding before this section's JS boots). --}}
        <x-ui.disclosure-row icon="layout" :label="__('heisenberg::editor.inspector.post_page_layout')" chevron="down" />
        <div class="hb-post-layout-body" data-hb-disclosure-body data-hb-post-layout-field
            data-hb-post-id="{{ $postId ?? '' }}"
            data-hb-layout-url-template="{{ $postLayoutUrlTemplate }}">
            <div class="hb-post-layout-row">
                <span class="hb-post-layout-row__label">{{ __('heisenberg::editor.inspector.post_layout_padding_x') }}</span>
                <x-ui.slider data-hb-post-layout-x :value="$postPagePaddingX" min="0" max="400" step="4" :disabled="$postId === null" />
                <span class="hb-post-layout-row__readout" data-hb-post-layout-x-readout>{{ $postPagePaddingX }}px</span>
            </div>
            <div class="hb-post-layout-row">
                <span class="hb-post-layout-row__label">{{ __('heisenberg::editor.inspector.post_layout_padding_y') }}</span>
                <x-ui.slider data-hb-post-layout-y :value="$postPagePaddingY" min="0" max="400" step="4" :disabled="$postId === null" />
                <span class="hb-post-layout-row__readout" data-hb-post-layout-y-readout>{{ $postPagePaddingY }}px</span>
            </div>
            <span class="hb-post-taxonomy-hint" data-hb-post-layout-hint @if ($postId !== null) hidden @endif>{{ __('heisenberg::editor.inspector.post_taxonomy_needs_save') }}</span>
        </div>
      </div>

      {{-- Sibling of the scroll body, not a child of it — see the Block tab's tracks below. --}}
      <x-ui.custom-scrollbar container="[data-hb-inspector-post-body]" />

        {{-- Featured-image field behavior: swap trigger/preview, open/close the media dialog via
             the hbOpen()/hbClose() it exposes on itself (see live/media/media-dialog.blade.php),
             and mirror a pick into the hidden id/url inputs above. Scoped to [data-hb-featured-field]
             so this never touches the Block-tab region below. --}}
        @once
        <style>
            .hb-post-dropzone { cursor: pointer; padding: 0; font: inherit; appearance: none; -webkit-appearance: none; }
            .hb-post-dropzone:focus-visible { outline: 2px solid var(--hb-border-focus, #000); outline-offset: 2px; }
            .hb-post-dropzone[hidden] { display: none; }
            .hb-post-dropzone-preview { position: relative; height: 94px; width: 100%; border-radius: var(--hb-radius-md, 5px); overflow: hidden; background: var(--hb-bg-subtle, #FAFAFA); border: 1px solid var(--hb-border-strong, #C8C8C8); }
            .hb-post-dropzone-preview[hidden] { display: none; }
            .hb-post-dropzone-preview__img { width: 100%; height: 100%; object-fit: cover; display: block; }
            .hb-post-dropzone-preview__actions { position: absolute; top: 6px; right: 6px; display: flex; gap: 4px; }
            .hb-post-dropzone-preview__btn { display: inline-flex; align-items: center; justify-content: center; width: 24px; height: 24px; border: 0; border-radius: 4px; background: rgba(10, 10, 10, .55); color: #fff; cursor: pointer; }
            .hb-post-dropzone-preview__btn:hover { background: rgba(10, 10, 10, .75); }
            .hb-post-dropzone-preview__btn--danger:hover { background: var(--hb-danger, #D4191A); }
        </style>
        <script>
            (() => {
                const boot = () => {
                    document.querySelectorAll('[data-hb-featured-field]').forEach((field) => {
                        if (field.__hbFeatured) return;
                        field.__hbFeatured = true;

                        const trigger = field.querySelector('[data-hb-featured-trigger]');
                        const preview = field.querySelector('[data-hb-featured-preview]');
                        const img = field.querySelector('[data-hb-featured-img]');
                        const replaceBtn = field.querySelector('[data-hb-featured-replace]');
                        const removeBtn = field.querySelector('[data-hb-featured-remove]');
                        const dialog = field.querySelector('[data-hb-featured-dialog]');
                        const idInput = field.querySelector('[data-hb-featured-image-id]');
                        const urlInput = field.querySelector('[data-hb-featured-image-url]');
                        if (!trigger || !preview || !img) return;

                        const applySelection = (file, opts) => {
                            opts = opts || {};
                            const url = file ? (file.thumbnail_url || file.url || '') : '';
                            if (file && url) {
                                img.src = url;
                                img.alt = file.original_name || 'Featured image';
                                preview.hidden = false;
                                trigger.hidden = true;
                                if (idInput) idInput.value = file.id != null ? String(file.id) : '';
                                if (urlInput) urlInput.value = url;
                            } else {
                                img.removeAttribute('src');
                                preview.hidden = true;
                                trigger.hidden = false;
                                if (idInput) idInput.value = '';
                                if (urlInput) urlInput.value = '';
                                if (opts.focusTrigger) trigger.focus();
                            }
                            // Rest-of-app hook: no post-persistence layer exists yet (no save
                            // endpoint in this package), so there is nothing to POST this to. This
                            // event plus the two hidden inputs above are the documented, in-memory
                            // client-side home for the pick — lost on reload, by design, rather
                            // than faking durability (e.g. via localStorage) this app doesn't have.
                            field.dispatchEvent(new CustomEvent('hb:featured-image-change', {
                                bubbles: true,
                                detail: file && url ? { id: file.id, url } : null,
                            }));
                        };

                        const open = (returnEl) => { if (dialog && typeof dialog.hbOpen === 'function') dialog.hbOpen(returnEl); };

                        trigger.addEventListener('click', () => open(trigger));
                        replaceBtn?.addEventListener('click', () => open(replaceBtn));
                        removeBtn?.addEventListener('click', () => applySelection(null, { focusTrigger: true }));
                        dialog?.addEventListener('hb:media-select', (event) => applySelection(event.detail));

                        applySelection(null);
                    });
                };
                if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot, { once: true });
                else boot();
                document.addEventListener('hb:refresh', boot);
            })();
        </script>
        @endonce

        {{-- Categories/Tags/Discussion/Page-layout field behavior (Phase 3.1, 2026-08-04;
             reworked/added 2026-08-03) — CategoryController/TagController/PostCategoryController/
             PostTagController/PostSettingsController. Scoped to [data-hb-post-taxonomy-field]/
             [data-hb-post-discussion-field]/[data-hb-post-layout-field] so this never touches the
             Block-tab region below, same convention as the featured-image block above. --}}
        @once
        <style>
            .hb-post-taxonomy-body,
            .hb-post-discussion-body,
            .hb-post-layout-body { display: flex; flex-direction: column; gap: 6px; padding: 0 var(--hb-space-3, 12px) var(--hb-space-3, 12px); }
            .hb-post-taxonomy-body[hidden],
            .hb-post-discussion-body[hidden],
            .hb-post-layout-body[hidden] { display: none; }
            .hb-post-taxonomy-hint { font-family: var(--hb-font-sans, Rubik, sans-serif); font-size: var(--hb-fs-xs, 11px); color: var(--hb-text-muted, #9A9A9A); }
            .hb-post-taxonomy-hint[hidden] { display: none; }

            {{-- Two-layer scroll shell — see live/panel-components-blocks.blade.php's own note on
                 why the scrollbar's `container` can't double as the bar's own direct parent. Shared
                 by BOTH Categories and Tags now that they're the same widget shape. `max-height`
                 (not a fixed `height`) on -scroll, and no explicit height at all on -wrap: a list
                 shorter than the cap shrink-wraps to its real content height (no dead space when a
                 post only has 2-3 categories) — the wrap simply matches whatever height -scroll
                 actually renders at, since it's -scroll's only child. custom-scrollbar's own
                 `bar.hidden = bounds <= 0` already auto-hides the bar when nothing needs scrolling. --}}
            .hb-post-taxonomy-list-wrap { position: relative; }
            .hb-post-taxonomy-list-scroll { max-height: 140px; overflow: hidden; }
            .hb-post-taxonomy-list { display: flex; flex-direction: column; gap: 2px; padding-right: 8px; }
            .hb-post-taxonomy-item { width: 100%; box-sizing: border-box; padding: 6px 8px; border-radius: var(--hb-radius-sm, 3px); flex: none; }
            .hb-post-taxonomy-item:hover { background: var(--hb-bg-muted, #F4F4F4); }
            {{-- [hidden] override: ui/checkbox's own .hb-checkbox rule sets display:inline-flex,
                 which otherwise beats the UA stylesheet's [hidden] at equal specificity — same fix
                 already applied elsewhere in this file (see .hb-inspector__icon[hidden] above). --}}
            .hb-post-taxonomy-item[hidden] { display: none; }
            .hb-post-taxonomy-empty { padding: 6px 8px; font-family: var(--hb-font-sans, Rubik, sans-serif); font-size: var(--hb-fs-xs, 11px); color: var(--hb-text-muted, #9A9A9A); }
            .hb-post-taxonomy-empty[hidden] { display: none; }
            {{-- outline:0 + a border-color shift on :focus, not a native browser focus ring — same
                 treatment ui/input gives its own text input (there via :focus-within on a wrapping
                 div, since that input has no border of its own; this one has no wrapper, so :focus
                 lands directly on it). Leaving the UA default outline in would show a much heavier
                 ring than every other text field in this app. --}}
            .hb-post-taxonomy-add-input { width: 100%; height: 30px; box-sizing: border-box; padding: 0 10px; border: 1px solid var(--hb-border, #E4E4E4); border-radius: var(--hb-radius-md, 5px); background: var(--hb-bg, #fff); font-family: var(--hb-font-sans, Rubik, sans-serif); font-size: var(--hb-fs-sm, 12px); color: var(--hb-text-primary, #0A0A0A); transition: border-color .12s ease; }
            .hb-post-taxonomy-add-input:hover { border-color: var(--hb-border-strong, #C8C8C8); }
            .hb-post-taxonomy-add-input:focus { outline: 0; border-color: var(--hb-border-focus, #000); }
            .hb-post-taxonomy-add-input:disabled { opacity: .5; cursor: not-allowed; }
            .hb-post-taxonomy-add-input::placeholder { color: var(--hb-text-muted, #9A9A9A); }

            .hb-post-layout-row { display: flex; align-items: center; gap: 10px; }
            .hb-post-layout-row__label { flex: 1 1 auto; min-width: 0; font-family: var(--hb-font-sans, Rubik, sans-serif); font-size: var(--hb-fs-sm, 12px); color: var(--hb-text-secondary, #5A5A5A); }
            .hb-post-layout-row__readout { flex: none; width: 34px; text-align: right; font-family: var(--hb-font-sans, Rubik, sans-serif); font-size: var(--hb-fs-xs, 11px); color: var(--hb-text-muted, #9A9A9A); }
        </style>
        <script>
            (() => {
                const csrfToken = () => {
                    const meta = document.querySelector('meta[name="csrf-token"]');
                    return meta ? meta.getAttribute('content') : '';
                };
                const jsonFetch = (url, options) => window.fetch(url, Object.assign({
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken(), 'X-Requested-With': 'XMLHttpRequest' },
                    credentials: 'same-origin',
                }, options)).then((res) => res.json().catch(() => ({})).then((body) => ({ ok: res.ok, body })));

                // ── Categories/Tags: ONE shared multi-select checklist ──
                // Both now attach via a real pivot (Category::posts()/Tag::posts() are both
                // BelongsToMany as of 2026-08-03 — see Post::categories()/tags()), so a single
                // generic widget serves both fields, differentiated only by their own data-*
                // attributes (attach URL template, the "create new" index URL + its response
                // envelope key) and whichever options Blade already rendered as real ui/checkbox
                // rows. Checking a box POSTs an attach; unchecking DELETEs.
                const wirePostTaxonomy = (field) => {
                    if (field.__hbPostTaxonomy) return;
                    field.__hbPostTaxonomy = true;

                    const list = field.querySelector('[data-hb-post-taxonomy-list]');
                    const empty = field.querySelector('[data-hb-post-taxonomy-empty]');
                    const addInput = field.querySelector('[data-hb-post-taxonomy-add-input]');
                    const hint = field.querySelector('[data-hb-post-taxonomy-hint]');
                    const itemTemplate = field.querySelector('template[data-hb-post-taxonomy-item-template]');
                    if (!list || !addInput || !itemTemplate) return;

                    let postId = field.dataset.hbPostId ? Number(field.dataset.hbPostId) : null;
                    const attachTemplate = field.dataset.hbAttachUrlTemplate || '';
                    const createUrl = field.dataset.hbIndexUrl || '';
                    const responseKey = field.dataset.hbCreateResponseKey || '';

                    const items = () => Array.from(list.querySelectorAll('.hb-post-taxonomy-item'));
                    const inputOf = (item) => item.querySelector('.hb-checkbox__input');
                    const labelOf = (item) => item.querySelector('.hb-checkbox__label')?.textContent || '';

                    const setEnabled = (enabled) => {
                        addInput.disabled = !enabled;
                        items().forEach((item) => {
                            const input = inputOf(item);
                            if (input) input.disabled = !enabled;
                            item.classList.toggle('hb-checkbox--disabled', !enabled);
                        });
                        if (hint) hint.hidden = enabled;
                    };

                    const attach = (itemValue, checked) => {
                        if (postId === null || !attachTemplate) return;
                        const url = attachTemplate.replace('__ID__', postId).replace('__ITEM_ID__', itemValue);
                        const options = checked ? { method: 'POST', body: '{}' } : { method: 'DELETE' };
                        jsonFetch(url, options).then(({ ok }) => {
                            if (!ok) console.error('[heisenberg] taxonomy ' + (checked ? 'attach' : 'detach') + ' failed');
                        });
                    };

                    const clearFilter = () => items().forEach((item) => { item.hidden = false; });

                    const addItem = (record) => {
                        const node = itemTemplate.content.firstElementChild.cloneNode(true);
                        const input = inputOf(node);
                        if (input) input.value = String(record.id);
                        const labelEl = node.querySelector('.hb-checkbox__label');
                        if (labelEl) labelEl.textContent = record.name_en || record.name || '';
                        list.insertBefore(node, empty || null);
                        if (empty) empty.hidden = true;
                        // The scrollbar's own ResizeObserver watches the FIXED-height wrap, not
                        // this list's scrollHeight growing inside it — nudge it directly so a
                        // freshly-added row is immediately reflected in the track/thumb math.
                        document.dispatchEvent(new CustomEvent('hb:refresh'));
                        return node;
                    };

                    list.addEventListener('change', (event) => {
                        const input = event.target.closest('.hb-checkbox__input');
                        const item = input ? input.closest('.hb-post-taxonomy-item') : null;
                        if (!item || !input) return;
                        attach(input.value, input.checked);
                    });

                    // The add-input doubles as a filter over the already-loaded list (every
                    // category/tag is server-rendered up front — nothing to fetch to search it,
                    // unlike the Fonts catalog) — Enter with no exact match creates a new one.
                    addInput.addEventListener('input', () => {
                        const query = addInput.value.trim().toLowerCase();
                        items().forEach((item) => { item.hidden = query !== '' && !labelOf(item).toLowerCase().includes(query); });
                    });

                    addInput.addEventListener('keydown', (event) => {
                        if (event.key !== 'Enter') return;
                        event.preventDefault();
                        const value = addInput.value.trim();
                        if (!value) return;
                        const exact = items().find((item) => labelOf(item).toLowerCase() === value.toLowerCase());
                        if (exact) {
                            const input = inputOf(exact);
                            if (input && !input.checked) { input.checked = true; attach(input.value, true); }
                            addInput.value = '';
                            clearFilter();
                            return;
                        }
                        if (!createUrl) return;
                        jsonFetch(createUrl, { method: 'POST', body: JSON.stringify({ name_en: value }) })
                            .then(({ ok, body }) => {
                                const record = responseKey ? body[responseKey] : null;
                                if (!ok || !record) { console.error('[heisenberg] taxonomy create failed'); return; }
                                const node = addItem(record);
                                addInput.value = '';
                                clearFilter();
                                const input = inputOf(node);
                                if (input) input.checked = true;
                                attach(String(record.id), true);
                            });
                    });

                    document.addEventListener('hb:post-id', (event) => {
                        postId = event.detail.id;
                        field.dataset.hbPostId = String(postId);
                        setEnabled(true);
                    });

                    if (postId !== null) setEnabled(true);
                };

                // ── Discussion: a single Allow-comments toggle ──
                const wirePostDiscussion = (field) => {
                    if (field.__hbPostDiscussion) return;
                    field.__hbPostDiscussion = true;

                    const toggle = field.querySelector('[data-hb-post-allow-comments]');
                    const hint = field.querySelector('[data-hb-post-discussion-hint]');
                    if (!toggle) return;

                    let postId = field.dataset.hbPostId ? Number(field.dataset.hbPostId) : null;
                    const urlTemplate = field.dataset.hbDiscussionUrlTemplate || '';

                    const setEnabled = (enabled) => {
                        toggle.disabled = !enabled;
                        toggle.closest('label')?.classList.toggle('hb-toggle--disabled', !enabled);
                        if (hint) hint.hidden = enabled;
                    };

                    toggle.addEventListener('change', () => {
                        if (postId === null || !urlTemplate) return;
                        const url = urlTemplate.replace('__ID__', postId);
                        jsonFetch(url, { method: 'PUT', body: JSON.stringify({ allow_comments: toggle.checked }) })
                            .then(({ ok }) => { if (!ok) console.error('[heisenberg] discussion save failed'); });
                    });

                    document.addEventListener('hb:post-id', (event) => {
                        postId = event.detail.id;
                        field.dataset.hbPostId = String(postId);
                        setEnabled(true);
                    });

                    if (postId !== null) setEnabled(true);
                };

                // ── Page layout: X/Y page padding sliders ──
                // Reaches past this component into the canvas (document.querySelector('.hb-page'))
                // the same direct way the featured-image field above reaches into the media dialog
                // — there's only ever one .hb-page on screen, and every other cross-component
                // channel in this app (hb:refresh, hb:post-id) is a broadcast, not a fit for "paint
                // this CSS var live as the slider moves."
                const wirePostLayout = (field) => {
                    if (field.__hbPostLayout) return;
                    field.__hbPostLayout = true;

                    const xSlider = field.querySelector('[data-hb-post-layout-x]');
                    const ySlider = field.querySelector('[data-hb-post-layout-y]');
                    const xReadout = field.querySelector('[data-hb-post-layout-x-readout]');
                    const yReadout = field.querySelector('[data-hb-post-layout-y-readout]');
                    const hint = field.querySelector('[data-hb-post-layout-hint]');
                    if (!xSlider || !ySlider) return;

                    let postId = field.dataset.hbPostId ? Number(field.dataset.hbPostId) : null;
                    const urlTemplate = field.dataset.hbLayoutUrlTemplate || '';
                    let saveTimer = null;

                    const setEnabled = (enabled) => {
                        xSlider.disabled = !enabled;
                        ySlider.disabled = !enabled;
                        if (hint) hint.hidden = enabled;
                    };

                    const apply = () => {
                        const page = document.querySelector('.hb-page');
                        if (!page) return;
                        page.style.setProperty('--hb-page-padding-x', xSlider.value + 'px');
                        page.style.setProperty('--hb-page-padding-y', ySlider.value + 'px');
                    };

                    const scheduleSave = () => {
                        if (postId === null || !urlTemplate) return;
                        clearTimeout(saveTimer);
                        saveTimer = setTimeout(() => {
                            const url = urlTemplate.replace('__ID__', postId);
                            jsonFetch(url, { method: 'PUT', body: JSON.stringify({
                                page_padding_x: Number(xSlider.value),
                                page_padding_y: Number(ySlider.value),
                            }) }).then(({ ok }) => { if (!ok) console.error('[heisenberg] page layout save failed'); });
                        }, 400);
                    };

                    const onInput = (slider, readout) => {
                        if (readout) readout.textContent = slider.value + 'px';
                        apply();
                        scheduleSave();
                    };

                    xSlider.addEventListener('input', () => onInput(xSlider, xReadout));
                    ySlider.addEventListener('input', () => onInput(ySlider, yReadout));

                    document.addEventListener('hb:post-id', (event) => {
                        postId = event.detail.id;
                        field.dataset.hbPostId = String(postId);
                        setEnabled(true);
                    });

                    if (postId !== null) setEnabled(true);
                };

                const boot = () => {
                    document.querySelectorAll('[data-hb-post-taxonomy-field]').forEach(wirePostTaxonomy);
                    document.querySelectorAll('[data-hb-post-discussion-field]').forEach(wirePostDiscussion);
                    document.querySelectorAll('[data-hb-post-layout-field]').forEach(wirePostLayout);
                };
                if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot, { once: true });
                else boot();
                document.addEventListener('hb:refresh', boot);
            })();
        </script>
        @endonce
    </div>
    <div class="hb-inspector__block-content" data-hb-inspector-block-content @if ($panelActiveIndex !== 1) hidden @endif>
        <div class="hb-inspector__header">
            <div class="hb-inspector__title-row">
                {{-- The frozen block-runtime.blade.php's own updateInspector() already syncs
                     .hb-inspector__name/.hb-inspector__desc by class selector on every selection
                     (see select() -> updateInspector()) — this file doesn't duplicate that. It
                     does NOT sync the icon, though (it only ever touches the two text nodes), and
                     the icon SVG can only be resolved server-side (@include(...icon...)), so —
                     same pattern as the Content/Style/Advanced panels below — every registered
                     block's icon is pre-rendered once here, hidden, and the script at the bottom
                     of this file shows the one matching the selection. --}}
                <span class="hb-inspector__icon" aria-hidden="true" data-hb-block-icon-default>
                    @include('heisenberg::components.ui.icon', ['name' => $blockIcon, 'size' => 22])
                </span>
                @foreach ($registry as $hbRegBlockName => $hbRegBlockContract)
                    <span class="hb-inspector__icon" aria-hidden="true" data-hb-block-icon="{{ $hbRegBlockName }}" hidden>
                        @include('heisenberg::components.ui.icon', ['name' => $hbRegBlockContract['icon'] ?? '', 'size' => 22])
                    </span>
                @endforeach
                <span class="hb-inspector__name">{{ $blockName }}</span>
            </div>
            <p class="hb-inspector__desc">{{ $blockDescription }}</p>
        </div>

        {{-- Nothing selected yet (initial load, or the Block tab opened by hand with no
             selection) — shown by default; hb:block-selected/hb:block-deselected below toggle
             this against .hb-inspector__populated. --}}
        <div class="hb-inspector__empty" data-hb-block-empty>
            <p>{{ __('heisenberg::editor.common.no_block_empty_panel') }}</p>
        </div>

        <div class="hb-inspector__populated" data-hb-block-populated hidden>
            <x-ui.sub-tabs :items="$subTabs" :active-index="$subActiveIndex" />
            <div class="hb-inspector__body" data-hb-inspector-body>
                {{-- One instance of each panel per registered block type, pre-rendered from its
                     real contract (controls/panels/attributeDefinitions — see
                     BlockViewData::clientBlocks()). All but the selected block's are hidden;
                     see this file's docblock for why the body is built this way instead of from
                     a JS-templated string. --}}
                <div data-hb-subpanel="content" data-hb-subpanel-content>
                    @foreach ($registry as $hbRegBlockName => $hbRegBlockContract)
                        @php
                            // live/block/content already takes the shape it needs — a list of
                            // {key,label,type,value,options} fields. Build it from the contract's
                            // own `settings`-section controls (BlockRegistryService::deriveControls)
                            // rather than re-implementing the component around a different shape.
                            $hbSettings = [];
                            foreach (($hbRegBlockContract['controls'] ?? []) as $hbCtl) {
                                if (($hbCtl['section'] ?? 'settings') !== 'settings') {
                                    continue;
                                }
                                $hbAttr = $hbCtl['attribute'] ?? ($hbCtl['id'] ?? '');
                                $hbSettings[] = [
                                    'key' => $hbAttr,
                                    'label' => $hbCtl['label'] ?? $hbAttr,
                                    'type' => $hbCtl['type'] ?? 'text',
                                    'value' => $hbRegBlockContract['attributes'][$hbAttr] ?? '',
                                    'options' => $hbCtl['options'] ?? [],
                                ];
                            }
                        @endphp
                        <div data-hb-block-panel="{{ $hbRegBlockName }}" hidden>
                            <x-live.block.content
                                :block-title="$hbRegBlockContract['title'] ?? 'Block'"
                                :settings="$hbSettings"
                            />
                        </div>
                    @endforeach
                </div>
                <div data-hb-subpanel="style" data-hb-subpanel-style hidden>
                    @foreach ($registry as $hbRegBlockName => $hbRegBlockContract)
                        {{-- live/block/style-panel already gates each section on the contract's
                             `supports` map, which is exactly what it was designed to do — it needs
                             the real supports passed in, nothing more. --}}
                        <div data-hb-block-panel="{{ $hbRegBlockName }}" hidden>
                            <x-live.block.style-panel :supports="$hbRegBlockContract['supports'] ?? []" />
                        </div>
                    @endforeach
                </div>
                <div data-hb-subpanel="advanced" data-hb-subpanel-advanced hidden>
                    @foreach ($registry as $hbRegBlockName => $hbRegBlockContract)
                        <div data-hb-block-panel="{{ $hbRegBlockName }}" hidden>
                            <x-live.block.advanced />
                        </div>
                    @endforeach
                </div>

                {{-- One track per sub-tab, mounted as SIBLINGS of the scroll regions rather than
                     inside them. An absolutely-positioned track inside its own scroll container is
                     anchored to the scrolling content, so `top: 0` scrolls up out of view the moment
                     you scroll — the track has to live in a positioned, non-scrolling parent
                     (.hb-inspector__body). Same arrangement panel-navigator uses. Only the active
                     sub-tab's container has a non-zero height, so only its track ever shows. --}}
                <x-ui.custom-scrollbar container="[data-hb-subpanel-content]" />
                <x-ui.custom-scrollbar container="[data-hb-subpanel-style]" />
                <x-ui.custom-scrollbar container="[data-hb-subpanel-advanced]" />
            </div>
        </div>
    </div>
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

    // ── tiny dotted-path helpers (mirrors block-runtime.blade.php's own dataGet — that copy is
    //    private to its closure and not part of window.hbEditor, so this is a deliberate, small,
    //    read-only-in-spirit reimplementation, not a divergent one) ─────────────────────────────
    function hbGet(obj, path) {
        const parts = String(path || '').split('.');
        let value = obj;
        for (let i = 0; i < parts.length; i++) {
            if (!value || typeof value !== 'object' || !(parts[i] in value)) return undefined;
            value = value[parts[i]];
        }
        return value;
    }

    // Coerce a raw (always-string-ish) form value back to the attribute's declared type before
    // writing it — without this, e.g. heading's integer `level` would be written as the string
    // "3", and block-runtime's own resolveTag() does a strict `allowed.indexOf(value)` against
    // the contract's (numeric) enum, silently falling back to the first option.
    function coerceAttrValue(contract, key, raw) {
        const def = contract && contract.attributeDefinitions && contract.attributeDefinitions[key];
        if (!def) return raw;
        if (def.type === 'integer' || def.type === 'number') {
            const n = Number(raw);
            return Number.isNaN(n) ? raw : n;
        }
        if (def.type === 'boolean') return !!raw;
        return raw;
    }

    // Mirrors block-runtime.blade.php's own predicateMatches() for `showWhen`/`disableWhen`
    // (the same {attribute, equals|in} shape BlockRegistryService::deriveControls emits) — that
    // function is likewise private to its closure, so this is the same kind of small, necessary
    // reimplementation as hbGet above.
    function evalPredicate(predicate, model) {
        if (!predicate || !predicate.attribute) return true;
        const value = model && model.attributes ? model.attributes[predicate.attribute] : undefined;
        if (Object.prototype.hasOwnProperty.call(predicate, 'equals')) return value === predicate.equals;
        if (Array.isArray(predicate.in)) return predicate.in.indexOf(value) !== -1;
        return true;
    }
    function refreshConditionals(panelRoot, model) {
        panelRoot.querySelectorAll('[data-hb-showwhen]').forEach((row) => {
            try { row.hidden = !evalPredicate(JSON.parse(row.getAttribute('data-hb-showwhen')), model); } catch (e) { /* malformed — never hide */ }
        });
        panelRoot.querySelectorAll('[data-hb-disablewhen]').forEach((row) => {
            let disabled = false;
            try { disabled = !evalPredicate(JSON.parse(row.getAttribute('data-hb-disablewhen')), model); } catch (e) { /* malformed — never disable */ }
            row.querySelectorAll('input, select, textarea, button').forEach((el) => { el.disabled = disabled; });
        });
    }

    // Set a select-type control's DISPLAYED value from the model without going through its own
    // select()/close() (ui/select's boot(), select.blade.php) — that path fires the same
    // 'change' event a user pick does, which our own write-back listener below would then treat
    // as an edit and write straight back (harmless — same value — but pointless and noisy).
    function syncSelect(root, rawValue) {
        const value = rawValue == null ? '' : String(rawValue);
        const option = root.querySelector('[data-hb-select-option="' + CSS.escape(value) + '"]');
        const valueEl = root.querySelector('[data-hb-select-value]');
        root.querySelectorAll('[data-hb-select-option]').forEach((o) => o.setAttribute('aria-selected', o === option ? 'true' : 'false'));
        root.dataset.value = value;
        if (valueEl) {
            if (option) {
                valueEl.textContent = option.textContent.trim();
                valueEl.classList.remove('hb-select__value--placeholder');
            } else {
                valueEl.textContent = valueEl.textContent; // leave the placeholder text as-is
                valueEl.classList.add('hb-select__value--placeholder');
            }
        }
    }

    function syncColorPreview(selectRoot, rawValue) {
        const preview = selectRoot.parentElement ? selectRoot.parentElement.querySelector('[data-hb-color-preview] .hb-swatch__color') : null;
        if (preview) preview.style.background = rawValue || 'transparent';
    }

    // Populate every [data-hb-control] under `root` (one shown block-panel) with the block's
    // real current value — called right before that panel is unhidden, so nothing visible ever
    // shows a stale/default value (the "re-selecting a block shows its real values" requirement).
    function syncControls(root, model) {
        root.querySelectorAll('[data-hb-control]').forEach((el) => {
            const key = el.getAttribute('data-hb-control');
            const kind = el.getAttribute('data-hb-control-kind');
            const type = el.getAttribute('data-hb-control-type');
            const source = kind === 'supports' ? (model.supports || {}) : (model.attributes || {});
            const value = hbGet(source, key);

            // Block.style is a complete Pencil composition, including fields not represented on
            // every selected model. An absent source value must not erase the component's extracted
            // visual default on mount; that was what left the mounted sidebar looking half-empty.
            // Explicit null/empty values still sync normally — only a genuinely missing path keeps
            // the rendered source value.
            if (root.querySelector('.hb-blockstyle') && value === undefined) return;

            if (type === 'toggle') {
                const input = el.querySelector('.hb-toggle__input');
                if (input) input.checked = !!value;
                return;
            }
            if (type === 'select') {
                syncSelect(el, value);
                syncColorPreview(el, value == null ? '' : String(value));
                return;
            }
            // ui/combobox owns its own display state (the input doubles as the search field), so
            // writing input.value directly would be overwritten the next time it re-renders.
            // Font families are their own label, hence setValue(v, v).
            if (type === 'combobox') {
                const text = value == null ? '' : String(value);
                if (el.__hbCombobox?.setValue) el.__hbCombobox.setValue(text, text);
                else {
                    const field = el.querySelector('[data-hb-combobox-input]');
                    if (field) field.value = text;
                    el.dataset.value = text;
                }
                return;
            }
            // Must precede the generic input fallback below — a chips host CONTAINS an
            // <input> (its add-class field), which would otherwise be filled with the whole
            // space-separated class string.
            if (type === 'chips') {
                renderChips(el, String(value == null ? '' : value).split(/\s+/).filter(Boolean));
                return;
            }
            const input = el.matches('input, textarea') ? el : el.querySelector('input, textarea');
            if (input) input.value = value == null ? '' : value;
            if (type === 'range') {
                const readout = el.closest('.hb-icol')?.querySelector('[data-hb-range-readout]');
                if (readout) readout.textContent = value == null ? '' : value;
            }
        });
        syncSpacingAggregates(root);
        refreshConditionals(root, model);
    }

    function showBlockPanels(inspector, name, model) {
        inspector.querySelectorAll('[data-hb-subpanel]').forEach((subpanel) => {
            subpanel.querySelectorAll('[data-hb-block-panel]').forEach((panel) => {
                const match = panel.getAttribute('data-hb-block-panel') === name;
                if (match) syncControls(panel, model);
                panel.hidden = !match;
            });
        });
    }

    // Show the pre-rendered icon matching `name` (or the default placeholder, for the
    // empty/deselected state) — see the header markup's comment for why the icon can't be
    // synced by just setting a text node the way the frozen runtime's updateInspector() does
    // for the name/description.
    function showBlockIcon(inspector, name) {
        const defaultIcon = inspector.querySelector('[data-hb-block-icon-default]');
        inspector.querySelectorAll('[data-hb-block-icon]').forEach((icon) => {
            icon.hidden = icon.getAttribute('data-hb-block-icon') !== name;
        });
        if (defaultIcon) defaultIcon.hidden = !!name;
    }

    document.addEventListener('hb:block-selected', (event) => {
        const inspector = document.querySelector('[data-hb-inspector]');
        if (!inspector) return;
        const { name, model } = event.detail || {};
        showBlockIcon(inspector, name || '');
        const empty = inspector.querySelector('[data-hb-block-empty]');
        const populated = inspector.querySelector('[data-hb-block-populated]');
        if (empty) empty.hidden = true;
        if (populated) populated.hidden = false;
        if (name && model) showBlockPanels(inspector, name, model);
    });

    document.addEventListener('hb:block-deselected', () => {
        const inspector = document.querySelector('[data-hb-inspector]');
        if (!inspector) return;
        showBlockIcon(inspector, '');
        const empty = inspector.querySelector('[data-hb-block-empty]');
        const populated = inspector.querySelector('[data-hb-block-populated]');
        if (empty) empty.hidden = false;
        if (populated) populated.hidden = true;
    });

    // Something else changed the selected block (e.g. typing in the canvas, or the floating
    // toolbar) — re-sync the currently-visible panel so it never drifts from the real model,
    // but leave alone whichever control the user is actively focused in (don't fight their typing).
    document.addEventListener('hb:block-updated', (event) => {
        const inspector = document.querySelector('[data-hb-inspector]');
        const populated = inspector ? inspector.querySelector('[data-hb-block-populated]') : null;
        if (!inspector || !populated || populated.hidden) return;
        const { id, model } = event.detail || {};
        if (!model || !window.hbEditor || window.hbEditor.getSelectedId() !== id) return;
        const active = document.activeElement;
        populated.querySelectorAll('[data-hb-block-panel]').forEach((panel) => {
            if (panel.hidden) return;
            if (active && panel.contains(active)) { refreshConditionals(panel, model); return; }
            syncControls(panel, model);
        });
    });

    // One delegated listener for every control, present or future — the panels above are only
    // ever shown/hidden and value-synced, never rebuilt, so a single document-level listener
    // never needs re-wiring (see this file's docblock).
    function handleControlEvent(event, isChange) {
        const el = event.target.closest('[data-hb-control]');
        if (!el) return;
        const panel = el.closest('[data-hb-block-panel]');
        if (!panel || panel.hidden) return;
        if (!window.hbEditor) return;
        const id = window.hbEditor.getSelectedId();
        if (!id) return;
        const model = window.hbEditor.getModel(id);
        if (!model) return;
        const contract = window.hbEditor.getContract(model.name);

        const key = el.getAttribute('data-hb-control');
        const kind = el.getAttribute('data-hb-control-kind');
        const type = el.getAttribute('data-hb-control-type');
        if (!key) return;
        // A chips host contains its own add-class <input>, which is a staging field, not the
        // value — letting the generic branch below read it would write every keystroke into
        // `extraClasses`. Chips commit through writeChips() on Enter/remove instead.
        if (type === 'chips') return;

        let raw;
        if (type === 'toggle') {
            if (!isChange) return; // avoid double-processing the checkbox's paired input+change
            const input = el.querySelector('.hb-toggle__input');
            raw = !!(input && input.checked);
        } else if (type === 'select') {
            if (event.target !== el) return; // the select's own custom 'change', dispatched on its root
            raw = el.dataset.value;
            syncColorPreview(el, raw);
        } else if (type === 'combobox') {
            // Same shape as select: ui/combobox dispatches its own `change` on its root carrying
            // the committed value. Reading its inner <input> instead would catch every keystroke
            // of a search that may never be committed.
            if (event.target !== el) return;
            raw = el.dataset.value;
        } else {
            const input = el.matches('input, textarea') ? el : el.querySelector('input, textarea');
            if (!input) return;
            raw = input.value;
            if (type === 'range') {
                const readout = el.closest('.hb-icol')?.querySelector('[data-hb-range-readout]');
                if (readout) readout.textContent = raw;
            }
            if (type === 'number' || type === 'range') raw = raw === '' ? '' : Number(raw);
        }

        if (kind === 'supports') {
            // Style controls are keyed by dotted paths into `supports` (e.g. "color.text"), which
            // setAttribute cannot address — setSupport is its counterpart, and like setAttribute it
            // owns the re-render and both events, so the model has exactly one write path per branch.
            window.hbEditor.setSupport(id, key, raw);
            return;
        }

        // setAttribute() itself dispatches hb:block-updated (see block-runtime.blade.php), which
        // the listener above already re-syncs/refreshes conditionals from — no need to duplicate
        // that here.
        window.hbEditor.setAttribute(id, key, coerceAttrValue(contract, key, raw));
    }
    document.addEventListener('input', (event) => handleControlEvent(event, false));
    document.addEventListener('change', (event) => handleControlEvent(event, true));

    // Pencil-specific Style compositions have local visual states that sit above the reusable
    // inputs: a 3x3 flex target, gap radios, linked padding, expandable side grids, and layer
    // stacks. Keep those transitions inside the mounted sidebar so selecting another block never
    // leaves the visible controls frozen in the previous presentation.
    function mountedStyleRoot(target) {
        const root = target.closest('.hb-blockstyle');
        return root && !root.closest('[hidden]') ? root : null;
    }

    function styleFieldInput(field) {
        return field?.matches('input') ? field : field?.querySelector('input');
    }

    function styleFields(root, selector) {
        return Array.from(root.querySelectorAll(selector));
    }

    function visibleStyleFields(root, group) {
        return styleFields(root, `[data-hb-style-side-value="${group}"]`)
            .filter((field) => !field.closest('[hidden]'));
    }

    function syncStyleLinkedValue(root, group) {
        const all = root.querySelector(`[data-hb-style-all-value="${group}"]`);
        const allInput = styleFieldInput(all);
        const sides = visibleStyleFields(root, group).map(styleFieldInput).filter(Boolean);
        if (!all || !allInput || !sides.length) return;

        const values = sides.map((input) => input.value);
        const mixed = values.some((value) => value !== values[0]);
        all.dataset.hbStyleMixed = mixed ? 'true' : 'false';
        allInput.classList.toggle('hb-field__value--mixed', mixed);
        allInput.setAttribute('aria-label', mixed ? 'Mixed values' : 'All values');
        allInput.value = mixed ? 'Mixed' : values[0];
    }

    function setStyleLinkedValue(root, group, value) {
        styleFields(root, `[data-hb-style-side-value="${group}"]`).forEach((field) => {
            const input = styleFieldInput(field);
            if (input) input.value = value;
        });
        const all = root.querySelector(`[data-hb-style-all-value="${group}"]`);
        const allInput = styleFieldInput(all);
        if (!all || !allInput) return;
        all.dataset.hbStyleMixed = 'false';
        allInput.classList.remove('hb-field__value--mixed');
        allInput.setAttribute('aria-label', 'All values');
        allInput.value = value;
    }

    function closeStylePopups(root, except = null) {
        root?.querySelectorAll('[data-hb-style-popup]').forEach((popup) => {
            if (popup !== except) popup.hidden = true;
        });
        root?.querySelectorAll('[data-hb-style-color-trigger], [data-hb-style-popup-trigger], [data-hb-style-effect-trigger]').forEach((trigger) => {
            if (!except || !except.contains(trigger)) trigger.setAttribute('aria-expanded', 'false');
        });
    }

    function showStylePopup(root, name, trigger) {
        const popup = root.querySelector(`[data-hb-style-popup="${name}"]`);
        if (!popup) return;
        const wasOpen = !popup.hidden;
        closeStylePopups(root, popup);
        popup.hidden = wasOpen;
        trigger.setAttribute('aria-expanded', wasOpen ? 'false' : 'true');
        if (wasOpen) return;

        const rect = trigger.getBoundingClientRect();
        const width = popup.offsetWidth;
        const height = popup.offsetHeight;
        const left = Math.max(8, Math.min(window.innerWidth - width - 8, rect.right - width));
        const below = rect.bottom + 8;
        const top = below + height <= window.innerHeight - 8
            ? below
            : Math.max(8, rect.top - height - 8);
        popup.style.left = `${left}px`;
        popup.style.top = `${top}px`;
    }

    function setStyleFieldPresentation(field, values, label) {
        const input = styleFieldInput(field);
        if (!field || !input || !values.length) return;
        const mixed = values.some((value) => value !== values[0]);
        field.dataset.hbStyleMixed = mixed ? 'true' : 'false';
        input.classList.toggle('hb-field__value--mixed', mixed);
        input.setAttribute('aria-label', mixed ? `Mixed ${label}` : label);
        input.value = mixed ? 'Mixed' : values[0];
    }

    function paddingSideInputs(root) {
        const names = ['left', 'right', 'top', 'bottom'];
        return Object.fromEntries(names.map((name) => [
            name,
            styleFieldInput(root.querySelector(`[data-hb-style-padding-side="${name}"]`)),
        ]));
    }

    function syncPaddingControls(root) {
        const sides = paddingSideInputs(root);
        if (Object.values(sides).some((input) => !input)) return;
        setStyleFieldPresentation(
            root.querySelector('[data-hb-style-all-value="padding"]'),
            [sides.left.value, sides.right.value, sides.top.value, sides.bottom.value],
            'all padding values',
        );
        setStyleFieldPresentation(
            root.querySelector('[data-hb-style-padding-axis="horizontal"]'),
            [sides.left.value, sides.right.value],
            'horizontal padding values',
        );
        setStyleFieldPresentation(
            root.querySelector('[data-hb-style-padding-axis="vertical"]'),
            [sides.top.value, sides.bottom.value],
            'vertical padding values',
        );
    }

    function setPaddingAxisValue(root, axis, value) {
        const sides = paddingSideInputs(root);
        const names = axis === 'horizontal' ? ['left', 'right'] : ['top', 'bottom'];
        names.forEach((name) => { if (sides[name]) sides[name].value = value; });
        syncPaddingControls(root);
    }

    function setPaddingMode(root, index) {
        const mode = ['one', 'two', 'four'][index] || 'four';
        root.dataset.hbStylePaddingMode = mode;
        root.querySelectorAll('[data-hb-style-padding-mode]').forEach((row) => {
            row.hidden = row.dataset.hbStylePaddingMode !== mode;
        });
        syncPaddingControls(root);
    }

    // ── Margin: mirrors the four Padding functions above exactly (own attribute vocabulary —
    // data-hb-style-margin-*, not a `group` parameter on the padding ones — so Margin is
    // additive here and Padding's own code path is untouched). ──
    function marginSideInputs(root) {
        const names = ['left', 'right', 'top', 'bottom'];
        return Object.fromEntries(names.map((name) => [
            name,
            styleFieldInput(root.querySelector(`[data-hb-style-margin-side="${name}"]`)),
        ]));
    }

    function syncMarginControls(root) {
        const sides = marginSideInputs(root);
        if (Object.values(sides).some((input) => !input)) return;
        setStyleFieldPresentation(
            root.querySelector('[data-hb-style-all-value="margin"]'),
            [sides.left.value, sides.right.value, sides.top.value, sides.bottom.value],
            'all margin values',
        );
        setStyleFieldPresentation(
            root.querySelector('[data-hb-style-margin-axis="horizontal"]'),
            [sides.left.value, sides.right.value],
            'horizontal margin values',
        );
        setStyleFieldPresentation(
            root.querySelector('[data-hb-style-margin-axis="vertical"]'),
            [sides.top.value, sides.bottom.value],
            'vertical margin values',
        );
    }

    function setMarginAxisValue(root, axis, value) {
        const sides = marginSideInputs(root);
        const names = axis === 'horizontal' ? ['left', 'right'] : ['top', 'bottom'];
        names.forEach((name) => { if (sides[name]) sides[name].value = value; });
        syncMarginControls(root);
    }

    function setMarginMode(root, index) {
        const mode = ['one', 'two', 'four'][index] || 'four';
        root.dataset.hbStyleMarginMode = mode;
        root.querySelectorAll('[data-hb-style-margin-mode]').forEach((row) => {
            row.hidden = row.dataset.hbStyleMarginMode !== mode;
        });
        syncMarginControls(root);
    }

    // ── Spacing → model (2026-08-04) ──────────────────────────────────────────────────
    // The four per-side fields carry their own data-hb-control and write through the shared
    // delegated listener like every other Style control. The "one value" / "horizontal-vertical"
    // fields cannot: one input owning two or four model paths is not something a single
    // data-hb-control can express. Both aggregate modes already fan their value out into the four
    // side INPUTS (setPaddingAxisValue/setStyleLinkedValue, above) — so by the time this runs the
    // side inputs are the source of truth for all three modes, and one write of the whole
    // `spacing.{group}` object covers every mode identically.
    //
    // Writing the object rather than four separate paths is deliberate: setSupport re-renders the
    // block on every call, so four calls per keystroke would rebuild the block's DOM four times
    // while the user types. setSupport's path walker assigns whatever value it is handed, and
    // BlockRenderer resolves `supports.spacing.padding.top` through dataGet, so an object written
    // at `spacing.padding` reads back identically to four scalar writes.
    function commitSpacingGroup(root, group) {
        if (!window.hbEditor) return;
        const id = window.hbEditor.getSelectedId();
        if (!id) return;
        const sides = group === 'padding' ? paddingSideInputs(root) : marginSideInputs(root);
        if (Object.values(sides).some((input) => !input)) return;
        window.hbEditor.setSupport(id, 'spacing.' + group, {
            top: sides.top.value,
            right: sides.right.value,
            bottom: sides.bottom.value,
            left: sides.left.value,
        });
    }

    // Aggregate fields hold no model path of their own, so syncControls (which walks
    // [data-hb-control]) leaves them untouched on selection. Re-derive them from the freshly
    // synced side inputs, or re-selecting a block would show its real per-side values under a
    // stale "all sides" summary.
    function syncSpacingAggregates(root) {
        if (!root.querySelector('[data-hb-style-padding-side]')) return;
        syncPaddingControls(root);
        syncMarginControls(root);
    }

    // ── Typography font family: paged search against the vendored catalog (TODO 7.5) ──
    // The field is ui/combobox; this only answers the `search`/`loadmore` events it dispatches
    // and hands results back through its own replaceOptions()/appendOptions(). Deliberately the
    // same contract panel-style-themes.blade.php uses for the left sidebar's Fonts rows — one
    // endpoint, one paging shape, no second dropdown implementation to keep in step.
    //
    // Page state lives on the combobox element itself, not a shared variable: the Style panel is
    // pre-rendered once per registered block type, so several font comboboxes exist at once and
    // each needs its own offset/hasMore.
    const HB_FONT_PAGE_LIMIT = 40;
    let hbFontTimer = null;

    function hbFontsUrl() {
        return document.querySelector('[data-hb-inspector]')?.dataset.hbFontsSearchUrl || '';
    }

    function hbFetchFonts(url) {
        return window.fetch(url, { headers: { 'Accept': 'application/json' } })
            .then((res) => (res.ok ? res.json() : { fonts: [], has_more: false }))
            .then((body) => ({
                list: (body.fonts || []).map((f) => ({ value: f.family, label: f.family })),
                hasMore: !!body.has_more,
            }));
    }

    function hbSearchFonts(combobox, query) {
        const url = hbFontsUrl();
        if (!url) return;
        const page = { query, offset: 0, hasMore: true, loading: true };
        combobox.__hbFontPage = page;
        hbFetchFonts(url + '?q=' + encodeURIComponent(query) + '&limit=' + HB_FONT_PAGE_LIMIT)
            .then(({ list, hasMore }) => {
                combobox.__hbCombobox?.replaceOptions(list);
                if (combobox.__hbFontPage !== page) return; // a newer search superseded this one
                page.offset = list.length;
                page.hasMore = hasMore;
                page.loading = false;
            });
    }

    function hbLoadMoreFonts(combobox, query) {
        const url = hbFontsUrl();
        const page = combobox.__hbFontPage;
        if (!url || !page || page.loading || !page.hasMore || page.query !== query) return;
        page.loading = true;
        hbFetchFonts(url + '?q=' + encodeURIComponent(query) + '&limit=' + HB_FONT_PAGE_LIMIT + '&offset=' + page.offset)
            .then(({ list, hasMore }) => {
                combobox.__hbCombobox?.appendOptions(list);
                page.offset += list.length;
                page.hasMore = hasMore;
                page.loading = false;
            });
    }

    document.addEventListener('search', (event) => {
        const combobox = event.target.closest('[data-hb-style-font-family]');
        if (!combobox) return;
        clearTimeout(hbFontTimer);
        hbFontTimer = setTimeout(() => hbSearchFonts(combobox, event.detail?.query || ''), 250);
    });

    document.addEventListener('loadmore', (event) => {
        const combobox = event.target.closest('[data-hb-style-font-family]');
        if (!combobox) return;
        hbLoadMoreFonts(combobox, event.detail?.query || '');
    });

    // ── extraClasses chips (Content → General, 2026-08-04) ────────────────────────────
    // The model holds ONE space-separated string (contract type "string"); the panel presents it
    // as chips plus an add-input (contract control type "chips"). Read from the DOM rather than a
    // cached string so a server-rendered chip and a cloned one are treated identically.
    function chipValues(host) {
        return Array.from(host.querySelectorAll('[data-hb-chip]'))
            .map((chip) => chip.dataset.hbChipValue || chip.querySelector('span')?.textContent.trim() || '')
            .filter(Boolean);
    }

    function renderChips(host, classes) {
        const list = host.querySelector('[data-hb-chip-list]');
        // A hidden real ui/chip rather than a <template> — see content.blade.php for why.
        // Scoped to the host's own parent, since the Content panel is pre-rendered once per
        // registered block type and each copy carries its own prototype.
        const prototype = host.parentElement?.querySelector('[data-hb-chip-prototype] [data-hb-chip]');
        if (!list || !prototype) return;
        list.textContent = '';
        classes.forEach((name) => {
            const chip = prototype.cloneNode(true);
            chip.removeAttribute('hidden');
            const label = chip.querySelector('span');
            if (label) label.textContent = name;
            chip.dataset.hbChipValue = name;
            list.appendChild(chip);
        });
    }

    function writeChips(host, classes) {
        renderChips(host, classes);
        if (!window.hbEditor) return;
        const id = window.hbEditor.getSelectedId();
        if (!id) return;
        window.hbEditor.setAttribute(id, host.getAttribute('data-hb-control'), classes.join(' '));
    }

    function updateColorLayer(layer, hex) {
        if (!layer || !/^#[0-9a-f]{6}$/i.test(hex)) return;
        const normalised = hex.toUpperCase();
        const input = layer.querySelector('.hb-colorlayer__hex');
        const swatch = layer.querySelector('.hb-colorlayer__swatch');
        if (input) input.value = normalised;
        if (swatch) swatch.style.background = normalised;
    }

    function activateStyleRadio(radio) {
        const group = radio.parentElement;
        if (!group) return;
        group.querySelectorAll('[data-hb-style-radio]').forEach((item) => {
            const active = item === radio;
            item.classList.toggle('hb-iradio--on', active);
            item.classList.toggle('is-active', active);
            item.setAttribute('aria-checked', active ? 'true' : 'false');
            item.querySelector('.hb-iradio__dot')?.classList.toggle('hb-iradio__dot--on', active);
        });
    }

    document.addEventListener('click', (event) => {
        const root = mountedStyleRoot(event.target);
        if (!root) return;

        if (!event.target.closest('[data-hb-style-popup], [data-hb-style-color-trigger], [data-hb-style-popup-trigger], [data-hb-style-effect-trigger]')) {
            closeStylePopups(root);
        }

        const paddingOption = event.target.closest('[data-hb-style-padding-option]');
        if (paddingOption) {
            const index = Number(paddingOption.dataset.hbStylePaddingOption);
            const menu = paddingOption.closest('.hb-padmenu');
            menu?.querySelectorAll('[data-hb-style-padding-option]').forEach((option) => {
                const selected = option === paddingOption;
                option.classList.toggle('hb-padmenu__opt--on', selected);
                option.setAttribute('aria-checked', selected ? 'true' : 'false');
                const radio = option.querySelector('.hb-radio__input');
                if (radio) radio.checked = selected;
            });
            setPaddingMode(root, index);
            closeStylePopups(root);
            return;
        }

        const marginOption = event.target.closest('[data-hb-style-margin-option]');
        if (marginOption) {
            const index = Number(marginOption.dataset.hbStyleMarginOption);
            const menu = marginOption.closest('.hb-padmenu');
            menu?.querySelectorAll('[data-hb-style-margin-option]').forEach((option) => {
                const selected = option === marginOption;
                option.classList.toggle('hb-padmenu__opt--on', selected);
                option.setAttribute('aria-checked', selected ? 'true' : 'false');
                const radio = option.querySelector('.hb-radio__input');
                if (radio) radio.checked = selected;
            });
            setMarginMode(root, index);
            closeStylePopups(root);
            return;
        }

        const colorTrigger = event.target.closest('[data-hb-style-color-trigger]');
        if (colorTrigger) {
            root.__hbStyleActiveColorLayer = colorTrigger.closest('.hb-colorlayer');
            const picker = root.querySelector('[data-hb-style-popup="color"] [data-hb-colorpicker]');
            const value = root.__hbStyleActiveColorLayer?.querySelector('.hb-colorlayer__hex')?.value || '#000000';
            picker?.__hbCp?.setHex(value);
            showStylePopup(root, 'color', colorTrigger);
            return;
        }

        const paddingTrigger = event.target.closest('[data-hb-style-popup-trigger="padding"]');
        if (paddingTrigger) {
            showStylePopup(root, 'padding', paddingTrigger);
            return;
        }

        const marginTrigger = event.target.closest('[data-hb-style-popup-trigger="margin"]');
        if (marginTrigger) {
            showStylePopup(root, 'margin', marginTrigger);
            return;
        }

        const effectTrigger = event.target.closest('[data-hb-style-effect-trigger]');
        if (effectTrigger) {
            showStylePopup(root, 'effect', effectTrigger);
            return;
        }

        const effectVisibility = event.target.closest('[data-hb-style-effect-visibility]');
        if (effectVisibility) {
            const visible = effectVisibility.getAttribute('aria-pressed') !== 'true';
            effectVisibility.setAttribute('aria-pressed', visible ? 'true' : 'false');
            effectVisibility.closest('.hb-fxlayer')?.setAttribute('data-hb-style-effect-hidden', visible ? 'false' : 'true');
            return;
        }

        const alignment = event.target.closest('[data-hb-style-alignment]');
        if (alignment) {
            const grid = alignment.closest('[data-hb-style-alignment-grid]');
            grid?.querySelectorAll('[data-hb-style-alignment]').forEach((item) => {
                const active = item === alignment;
                item.classList.toggle('hb-agrid__dot--on', active);
                item.classList.toggle('is-active', active);
                item.setAttribute('aria-pressed', active ? 'true' : 'false');
            });
            return;
        }

        const radio = event.target.closest('[data-hb-style-radio]');
        if (radio) {
            activateStyleRadio(radio);
            return;
        }

        const link = event.target.closest('[data-hb-style-link]');
        if (link) {
            const active = link.getAttribute('aria-pressed') !== 'true';
            link.classList.toggle('is-active', active);
            link.setAttribute('aria-pressed', active ? 'true' : 'false');
            return;
        }

        const expand = event.target.closest('[data-hb-style-expand]');
        if (expand) {
            const section = expand.closest('.hb-section');
            const expanded = expand.getAttribute('aria-expanded') !== 'true';
            section?.querySelectorAll('[data-hb-style-expandable]').forEach((item) => { item.hidden = !expanded; });
            expand.setAttribute('aria-expanded', expanded ? 'true' : 'false');
            expand.classList.toggle('is-active', expanded);
            if (expanded) syncStyleLinkedValue(root, expand.dataset.hbStyleExpand);
            return;
        }

        const remove = event.target.closest('[data-hb-style-remove]');
        if (remove) {
            event.preventDefault();
            const removed = remove.closest('.hb-colorlayer, .hb-effectrow');
            if (removed && root.__hbStyleActiveColorLayer === removed) {
                root.__hbStyleActiveColorLayer = null;
                closeStylePopups(root);
            }
            removed?.remove();
            return;
        }

        const add = event.target.closest('[data-hb-style-add]');
        if (add) {
            const section = add.closest('.hb-section');
            const list = section?.querySelector(`[data-hb-style-layer-list="${add.dataset.hbStyleAdd}"]`);
            const template = section?.querySelector(`template[data-hb-style-layer-template="${add.dataset.hbStyleAdd}"]`);
            if (list && template?.content) {
                list.append(template.content.cloneNode(true));
                document.dispatchEvent(new Event('hb:refresh'));
            }
            return;
        }

        // Clear font — the field became a ui/combobox against the live catalog (TODO 7.5), so
        // there is no static "Default" option to re-select any more. Clearing means emptying the
        // value, which setValue('', '') does, and that dispatches the combobox's own `change` so
        // the model write goes through the one delegated handler like any other edit.
        const clearFont = event.target.closest('[data-hb-style-clear-font]');
        if (clearFont) {
            const combobox = clearFont.closest('.hb-style-typography__font-row')?.querySelector('[data-hb-combobox]');
            combobox?.__hbCombobox?.setValue('', '');
        }
    });

    document.addEventListener('input', (event) => {
        const root = mountedStyleRoot(event.target);
        if (!root) return;

        const paddingAxis = event.target.closest('[data-hb-style-padding-axis]');
        if (paddingAxis) {
            setPaddingAxisValue(root, paddingAxis.dataset.hbStylePaddingAxis, event.target.value);
            commitSpacingGroup(root, 'padding');
            return;
        }

        const marginAxis = event.target.closest('[data-hb-style-margin-axis]');
        if (marginAxis) {
            setMarginAxisValue(root, marginAxis.dataset.hbStyleMarginAxis, event.target.value);
            commitSpacingGroup(root, 'margin');
            return;
        }

        const all = event.target.closest('[data-hb-style-all-value]');
        if (all) {
            setStyleLinkedValue(root, all.dataset.hbStyleAllValue, event.target.value);
            // stroke-sides / appearance-corners also use data-hb-style-all-value; only the two
            // spacing groups have a `spacing.*` model path to commit.
            if (all.dataset.hbStyleAllValue === 'padding') { syncPaddingControls(root); commitSpacingGroup(root, 'padding'); }
            else if (all.dataset.hbStyleAllValue === 'margin') { syncMarginControls(root); commitSpacingGroup(root, 'margin'); }
            return;
        }

        const side = event.target.closest('[data-hb-style-side-value]');
        if (side) {
            syncStyleLinkedValue(root, side.dataset.hbStyleSideValue);
            if (side.dataset.hbStyleSideValue === 'padding') syncPaddingControls(root);
            else if (side.dataset.hbStyleSideValue === 'margin') syncMarginControls(root);
        }

        if (event.target.matches('.hb-colorlayer__hex')) updateColorLayer(event.target.closest('.hb-colorlayer'), event.target.value);
    });

    // extraClasses chips — Enter appends, a chip's close button removes. Both commit through
    // writeChips(); nothing else in the inspector writes this attribute (the generic
    // [data-hb-control] handler bails out on type="chips", see its own comment).
    document.addEventListener('keydown', (event) => {
        if (event.key !== 'Enter') return;
        const input = event.target.closest('[data-hb-chip-input]');
        const host = input?.closest('[data-hb-control-type="chips"]');
        if (!host) return;
        event.preventDefault();
        // Space is the model's separator, so one paste of "a b c" becomes three chips rather
        // than one chip whose label would silently re-split on the next read.
        const added = input.value.trim().split(/\s+/).filter(Boolean);
        if (added.length === 0) return;
        const classes = chipValues(host);
        added.forEach((name) => { if (!classes.includes(name)) classes.push(name); });
        writeChips(host, classes);
        input.value = '';
    });

    document.addEventListener('click', (event) => {
        const close = event.target.closest('[data-hb-chip-close]');
        const host = close?.closest('[data-hb-control-type="chips"]');
        if (!host) return;
        const name = close.closest('[data-hb-chip]')?.dataset.hbChipValue;
        if (!name) return;
        writeChips(host, chipValues(host).filter((cls) => cls !== name));
    });

    document.addEventListener('focusin', (event) => {
        const all = event.target.closest('[data-hb-style-all-value], [data-hb-style-padding-axis], [data-hb-style-margin-axis]');
        const root = mountedStyleRoot(event.target);
        if (!root || !all || all.dataset.hbStyleMixed !== 'true') return;
        event.target.select();
    });

    document.addEventListener('colorchange', (event) => {
        const popup = event.target.closest('[data-hb-style-popup="color"]');
        const root = popup ? mountedStyleRoot(popup) : null;
        if (!root || !root.__hbStyleActiveColorLayer) return;
        if (event.detail?.gradientStop !== null && event.detail?.gradientStop !== undefined) return;
        const { r, g, b } = event.detail || {};
        const toHex = (value) => Number(value).toString(16).padStart(2, '0');
        if ([r, g, b].some((value) => !Number.isFinite(value))) return;
        updateColorLayer(root.__hbStyleActiveColorLayer, `#${toHex(r)}${toHex(g)}${toHex(b)}`);
    });

    document.addEventListener('gradientchange', (event) => {
        const popup = event.target.closest('[data-hb-style-popup="color"]');
        const root = popup ? mountedStyleRoot(popup) : null;
        const css = event.detail?.css;
        if (!root || !root.__hbStyleActiveColorLayer || typeof css !== 'string') return;
        const swatch = root.__hbStyleActiveColorLayer.querySelector('.hb-colorlayer__swatch');
        if (swatch) swatch.style.background = css;
        root.__hbStyleActiveColorLayer.dataset.hbStyleGradient = css;
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            const root = mountedStyleRoot(event.target);
            if (root) closeStylePopups(root);
        }
        const radio = event.target.closest('[data-hb-style-radio]');
        if (!radio || !mountedStyleRoot(radio) || !['Enter', ' '].includes(event.key)) return;
        event.preventDefault();
        activateStyleRadio(radio);
    });
})();
</script>
@endonce
