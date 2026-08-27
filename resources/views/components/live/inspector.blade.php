@once
<style nonce="{{ heisenberg_csp_nonce() }}">
    .hb-inspector {
        display: flex;
        flex-direction: column;
        width: 260px;
        height: 100%;
        background: var(--hb-bg);
        border-left: 1px solid var(--hb-border);
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
    .hb-inspector__icon { display: inline-flex; width: 22px; height: 22px; color: var(--hb-text-primary); flex: none; }
    .hb-inspector__icon[hidden] { display: none; }
    .hb-inspector__name {
        font-family: var(--hb-font-sans, Rubik, sans-serif);
        font-size: var(--hb-fs-md, 14px);
        font-weight: 500;
        color: var(--hb-text-primary);
    }
    .hb-inspector__desc {
        font-family: var(--hb-font-sans, Rubik, sans-serif);
        font-size: var(--hb-fs-sm, 12px);
        line-height: 1.4;
        color: var(--hb-text-muted);
    }
    .hb-inspector__block-content,
    .hb-inspector__post-content { display: flex; flex-direction: column; flex: 1 1 auto; min-height: 0; overflow: hidden; }
    .hb-inspector__post-content { position: relative; }
    .hb-inspector__post-body { display: flex; flex-direction: column; flex: 1 1 auto; min-height: 0; overflow: hidden; }
    .hb-inspector__post-body > * { flex: none; }
    .hb-inspector__block-content[hidden],
    .hb-inspector__post-content[hidden] { display: none; }
    .hb-inspector__populated { display: flex; flex-direction: column; flex: 1 1 auto; min-height: 0; }
    .hb-inspector__populated[hidden] { display: none; }
    .hb-inspector__body { flex: 1 1 auto; min-height: 0; display: flex; flex-direction: column; overflow: hidden; position: relative; }
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
        color: var(--hb-text-muted);
    }
    .hb-inspector__empty[hidden] { display: none; }

    .hb-post-title {
        display: flex;
        flex-direction: column;
        gap: var(--hb-space-3, 12px);
        padding: var(--hb-space-3, 12px);
        flex: none;
    }
    .hb-post-title__eyebrow {
        font-family: var(--hb-font-sans, Rubik, sans-serif);
        font-size: var(--hb-fs-xs, 11px);
        font-weight: 500;
        letter-spacing: .5px;
        color: var(--hb-text-muted);
    }
    .hb-post-title__row { display: flex; align-items: center; gap: 8px; }
    .hb-post-title__icon { display: inline-flex; width: 15px; height: 15px; color: var(--hb-text-secondary); flex: none; }
    .hb-post-title__value { font-family: var(--hb-font-sans, Rubik, sans-serif); font-size: var(--hb-fs-base, 13px); font-weight: 500; color: var(--hb-text-secondary); }
    .hb-post-title__input { flex: 1 1 auto; min-width: 0; border: 0; outline: 0; background: none; font-family: var(--hb-font-sans, Rubik, sans-serif); font-size: var(--hb-fs-base, 13px); font-weight: 500; color: var(--hb-text-primary); }
    .hb-post-title__input::placeholder { color: var(--hb-text-muted); font-weight: 500; }

    .hb-post-dropzone-wrap { padding: var(--hb-space-3, 12px); border-bottom: 1px solid var(--hb-border); flex: none; }
    .hb-post-dropzone {
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        gap: 8px;
        height: 94px;
        width: 100%;
        border: 1px solid var(--hb-border-strong);
        border-radius: var(--hb-radius-md, 5px);
        background: var(--hb-bg-subtle);
    }
    .hb-post-dropzone__icon { display: inline-flex; width: 28px; height: 28px; color: var(--hb-text-muted); }
    .hb-post-dropzone__label { font-family: var(--hb-font-sans, Rubik, sans-serif); font-size: var(--hb-fs-sm, 12px); color: var(--hb-text-muted); }

    .hb-post-meta { display: flex; flex-direction: column; padding: 6px 0; flex: none; }
    .hb-post-meta__row { display: flex; align-items: center; justify-content: space-between; height: 32px; padding: 0 14px; }
    .hb-post-meta__label { font-family: var(--hb-font-sans, Rubik, sans-serif); font-size: var(--hb-fs-sm, 12px); color: var(--hb-text-secondary); }
    .hb-post-meta__value { font-family: var(--hb-font-sans, Rubik, sans-serif); font-size: 11px; font-weight: 500; color: var(--hb-text-primary); }
    .hb-post-meta__value--btn {
        max-width: 175px;
        border: 0;
        background: none;
        padding: 0;
        margin: 0;
        font-family: var(--hb-font-sans, Rubik, sans-serif);
        font-size: 11px;
        font-weight: 500;
        text-align: right;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        cursor: pointer;
    }
    .hb-post-meta__value--btn:not(:disabled):hover { color: var(--hb-editing); }
    .hb-post-meta__value--btn:disabled { opacity: .5; cursor: not-allowed; }
    .hb-post-meta__value--btn[data-hb-pending="true"] { color: var(--hb-warning); }
    .hb-post-meta__value--btn[data-hb-pending="true"]::before { content: '\2022'; margin-right: 4px; }

    .hb-post-popup { position: fixed; z-index: 1000; }
    .hb-post-popup[hidden] { display: none !important; }
    .hb-post-pop { width: 236px; box-shadow: 3px 4px 4px rgba(0, 0, 0, .1); }

    .hb-post-slugpop { display: flex; flex-direction: column; gap: 6px; padding: 12px; }
    .hb-post-slugpop__label { font-size: var(--hb-fs-xs, 11px); font-weight: 600; color: var(--hb-text-secondary); }
    .hb-post-slugpop__input {
        height: 30px;
        box-sizing: border-box;
        padding: 0 8px;
        border: 1px solid var(--hb-border);
        border-radius: var(--hb-radius-md, 5px);
        background: var(--hb-bg);
        font-family: inherit;
        font-size: var(--hb-fs-sm, 12px);
        color: var(--hb-text-primary);
    }
    .hb-post-slugpop__input:focus { outline: 0; border-color: var(--hb-border-focus); }
    .hb-post-slugpop__input:disabled { opacity: .5; cursor: not-allowed; }

    .hb-post-toggles { display: flex; flex-direction: column; padding: 6px 0; flex: none; }
    .hb-post-toggle-row { display: flex; align-items: center; justify-content: space-between; gap: 12px; height: 34px; padding: 0 14px; }
    .hb-post-toggle-row__label { font-family: var(--hb-font-sans, Rubik, sans-serif); font-size: var(--hb-fs-sm, 12px); color: var(--hb-text-secondary); }

    .hb-post-divider { border: 0; border-top: 1px solid var(--hb-border); width: 100%; margin: 0; flex: none; }

    .hb-post-trash-row { display: flex; align-items: center; gap: 6px; padding: 0 var(--hb-space-3, 12px); }
    .hb-post-trash {
        display: flex;
        align-items: center;
        gap: 8px;
        flex: 1 1 auto;
        height: 44px;
        padding: 0;
        border: 0;
        background: transparent;
        cursor: pointer;
        text-align: left;
    }
    .hb-post-trash:hover { background: var(--hb-surface-hover); }
    .hb-post-trash:disabled { opacity: .5; cursor: not-allowed; }
    .hb-post-trash:disabled:hover { background: transparent; }
    .hb-post-trash__icon { display: inline-flex; width: 15px; height: 15px; color: var(--hb-danger); flex: none; }
    .hb-post-trash__label { font-family: var(--hb-font-sans, Rubik, sans-serif); font-size: var(--hb-fs-sm, 12px); font-weight: 500; color: var(--hb-danger); }
    .hb-post-trash.is-armed .hb-post-trash__label { text-decoration: underline; }
    .hb-post-trash-cancel {
        flex: none;
        height: 28px;
        padding: 0 10px;
        border: 1px solid var(--hb-border);
        border-radius: var(--hb-radius-md, 5px);
        background: var(--hb-bg);
        color: var(--hb-text-secondary);
        font-family: var(--hb-font-sans, Rubik, sans-serif);
        font-size: var(--hb-fs-sm, 12px);
        cursor: pointer;
    }
    .hb-post-trash-cancel:hover { background: var(--hb-surface-hover); }
</style>
<script nonce="{{ heisenberg_csp_nonce() }}">
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
    'registry' => [],
    'postTitle' => '',
    'postMeta' => [],
    'postStatusTransitions' => [],
    'postStatusLabels' => [],
    'postScheduledAt' => null,
    'postPublishedAt' => null,
    'postPendingReview' => false,
    'postStickToTop' => false,
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
    'postTrashUrlTemplate' => '',
    'postFeaturedImage' => null,
    'postFeaturedImageUrlTemplate' => '',
    'postTocEntries' => [],
    'postTocUrlTemplate' => '',
    'postTranslations' => null,
    'contentLocales' => ['en', 'fr'],
    'blockIcon' => '',
    'blockName' => __('heisenberg::editor.common.no_block_selected_title'),
    'blockDescription' => __('heisenberg::editor.common.no_block_selected_desc'),
    'subTabs' => [
        ['value' => 'content', 'icon' => 'browsers-fill', 'label' => __('heisenberg::editor.inspector.subtab_content')],
        ['value' => 'style', 'icon' => 'paint-brush-fill', 'label' => __('heisenberg::editor.inspector.subtab_style')],
        ['value' => 'advanced', 'icon' => 'gear-fill', 'label' => __('heisenberg::editor.inspector.subtab_advanced')],
    ],
    'subActiveIndex' => 0,
    'fontsSearchUrl' => '',
    'theme' => [],
    'documentType' => 'post',
])
<aside data-hb-inspector data-hb-fonts-search-url="{{ $fontsSearchUrl }}" {{ $attributes->merge(['class' => 'hb-inspector']) }}>
    <x-heisenberg::ui.panel-tabs :items="$panelTabs" :active-index="$panelActiveIndex" />

    <div class="hb-inspector__post-content" data-hb-inspector-post-content @if ($panelActiveIndex !== 0) hidden @endif>
        @include('heisenberg::components.live.inspector.post-title-summary')
        @include('heisenberg::components.live.inspector.post-meta-live-script')
        @include('heisenberg::components.live.inspector.post-trash-script')
        @include('heisenberg::components.live.inspector.post-taxonomy-toc')
        @include('heisenberg::components.live.inspector.featured-image-behavior')

        @include('heisenberg::components.live.inspector.taxonomy-behavior')
    </div>
    @include('heisenberg::components.live.inspector.block-tab')
</aside>

@once
<script nonce="{{ heisenberg_csp_nonce() }}">
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
