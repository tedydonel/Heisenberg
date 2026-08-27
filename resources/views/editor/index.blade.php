@extends('heisenberg::editor.layouts.app')

@section('content')
    <style id="hb-theme-vars" nonce="{{ heisenberg_csp_nonce() }}">{!! $themeCss ?? '' !!}</style>
    @php
        $hbDocumentType = $documentType ?? 'post';
    @endphp
    <x-heisenberg::live.topbar class="hb-editor__topbar" :post-id="$postId ?? null" :content-version="$contentVersion ?? 0"
        :home-locale="$postLocale ?? 'en'"
        :content-locales="$contentLocales ?? ['en', 'fr']"
        :content-locale-labels="$contentLocaleLabels ?? []"
        :post-title-by-locale="$postTitleByLocale ?? []"
        :document-type="$hbDocumentType"
        :email-preview-url-template="$emailPreviewUrlTemplate ?? ''"
        :email-export-url-template="$emailExportUrlTemplate ?? ''" />
    <x-heisenberg::live.sidebar class="hb-editor__sidebar" :document-type="$hbDocumentType" />
    <div class="hb-editor__panel">
        <x-heisenberg::live.panel-components-blocks :registry="$paletteBlocks ?? $registry" />
        @if ($hbDocumentType !== 'email')
        <x-heisenberg::live.panel-seo-social hidden
            :post-id="$postId ?? null"
            :post-title="$postTitle ?? ''"
            :post-slug="$postSlug ?? ''"
            :post-seo="$postSeo ?? null"
            :seo-analyze-url-template="$postSeoAnalyzeUrlTemplate ?? ''" />
        @endif
        <x-heisenberg::live.panel-style-themes hidden :theme="$theme ?? []" :saved-themes="$savedThemes ?? []" :font-options="$fontOptions ?? []"
            :theme-update-url="route('heisenberg.editor.theme.update')" :fonts-search-url="route('heisenberg.editor.fonts.search')"
            :themes-store-url="route('heisenberg.editor.themes.store')" :themes-destroy-url="route('heisenberg.editor.themes.destroy')" />
        <x-heisenberg::live.panel-ai hidden :stream-url="route('heisenberg.editor.ai.stream')"
            :conversations-url="route('heisenberg.editor.ai.conversations.index')"
            :suggest-url="route('heisenberg.editor.ai.suggest')"
            :model-options="$aiModelOptions ?? []"
            :active-model="$aiActiveModel ?? null"
            :locale="app()->getLocale()"
            :post-id="$postId ?? null" />
        <x-heisenberg::live.panel-navigator hidden :registry="$registry" />
    </div>
    <div class="hb-editor__canvas">
        <x-heisenberg::live.canvas :title="$postTitle ?? ''" :page-padding-x="$postPagePaddingX ?? 56" :page-padding-y="$postPagePaddingY ?? 56"
            :document-type="$hbDocumentType"
            :post-locale="$postLocale ?? 'en'" :content-locale-labels="$contentLocaleLabels ?? []" />
        <x-heisenberg::live.code-editor hidden />
        <x-heisenberg::live.quick-inserter :registry="$paletteBlocks ?? $registry" />
        @php
            $hbBlockImageSelectUrl = \Illuminate\Support\Facades\Route::has('media.select') ? route('media.select') : null;
            $hbBlockImageUploadUrl = \Illuminate\Support\Facades\Route::has('media.upload') ? route('media.upload') : null;
        @endphp
        <x-heisenberg::live.media.media-dialog
            data-hb-block-image-dialog
            hidden
            :scrim="true"
            tab="library"
            accept="image/*"
            :title="__('heisenberg::editor.media.select_image')"
            :select-url="$hbBlockImageSelectUrl"
            :upload-url="$hbBlockImageUploadUrl"
        />
        <script nonce="{{ heisenberg_csp_nonce() }}">
            (() => {
                let hbImageTargetId = null;
                const hbImageDialog = () => document.querySelector('[data-hb-block-image-dialog]');
                if (!document.__hbBlockImagePicker) {
                    document.__hbBlockImagePicker = true;
                    document.addEventListener('hb:pick-image', (e) => {
                        const dialog = hbImageDialog();
                        if (!dialog || typeof dialog.hbOpen !== 'function') return;
                        e.preventDefault();
                        hbImageTargetId = e.detail && e.detail.id ? e.detail.id : null;
                        const blk = hbImageTargetId ? document.querySelector('.hb-blk[data-block="' + hbImageTargetId + '"]') : null;
                        dialog.hbOpen(blk ? blk.querySelector('.hb-img-empty') : null);
                    });
                }
                const boot = () => {
                    const dialog = hbImageDialog();
                    if (!dialog || dialog.__hbBlockImage) return;
                    dialog.__hbBlockImage = true;
                    dialog.addEventListener('hb:media-select', (event) => {
                        const file = event.detail;
                        if (!hbImageTargetId || !file || !file.url || !window.hbEditor) return;
                        window.hbEditor.setAttribute(hbImageTargetId, 'url', file.url);
                        const alt = file.alt_text_en || file.alt_text_fr || '';
                        if (alt) window.hbEditor.setAttribute(hbImageTargetId, 'alt', alt);
                        hbImageTargetId = null;
                    });
                };
                if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot, { once: true });
                else boot();
                document.addEventListener('hb:refresh', boot);
            })();
        </script>
        <x-heisenberg::live.icon-picker-dialog
            :search-url="\Illuminate\Support\Facades\Route::has('heisenberg.editor.icons.search') ? route('heisenberg.editor.icons.search') : null"
            :sets="app(\Heisenberg\Services\IconLibraryService::class)->sets()" />
        @if (! empty($emailVariablePicker))
            <x-heisenberg::live.pickers.email-variable-menu :entries="$emailVariablePicker['entries']" :all-targets="$emailVariablePicker['allTargets']" />
        @endif
        <x-heisenberg::ui.custom-scrollbar container=".hb-canvas" />
        <div class="hb-blk-toolbar-holder" hidden>
            <x-heisenberg::live.toolbar.block-toolbar data-hb-block-toolbar :rich-text="true" :block-type="'Text'" :active-formats="[]"
                :theme-tokens="$themeTokens['color'] ?? []"
                :supports="['color' => ['text' => true, 'background' => true], 'align' => ['left', 'center', 'right']]" />
        </div>
    </div>
    <x-heisenberg::live.inspector class="hb-editor__inspector" :registry="$registry" :post-title="$postTitle ?? ''"
        :post-meta="$postMeta ?? []"
        :post-status-transitions="$postStatusTransitions ?? []" :post-status-labels="$postStatusLabels ?? []"
        :post-scheduled-at="$postScheduledAt ?? null"
        :post-published-at="$postPublishedAt ?? null"
        :post-id="$postId ?? null" :post-category-ids="$postCategoryIds ?? []" :post-tag-ids="$postTagIds ?? []"
        :category-options="$categoryOptions ?? []" :tag-options="$tagOptions ?? []"
        :categories-index-url="$categoriesIndexUrl" :tags-index-url="$tagsIndexUrl"
        :post-category-url-template="$postCategoryUrlTemplate" :post-tags-url-template="$postTagsUrlTemplate"
        :post-page-padding-x="$postPagePaddingX ?? 56" :post-page-padding-y="$postPagePaddingY ?? 56"
        :post-layout-url-template="$postLayoutUrlTemplate" :post-allow-comments="$postAllowComments ?? true"
        :post-discussion-url-template="$postDiscussionUrlTemplate"
        :post-revisions-url-template="$postRevisionsUrlTemplate ?? ''"
        :post-trash-url-template="$postTrashUrlTemplate ?? ''"
        :post-featured-image="$postFeaturedImage ?? null"
        :post-featured-image-url-template="$postFeaturedImageUrlTemplate ?? ''"
        :post-toc-entries="$postTocEntries ?? []"
        :post-toc-url-template="$postTocUrlTemplate ?? ''"
        :post-translations="$postTranslations ?? null"
        :content-locales="$contentLocales ?? ['en', 'fr']"
        :fonts-search-url="route('heisenberg.editor.fonts.search')"
        :theme="$theme ?? []"
        :document-type="$hbDocumentType" />
    <x-heisenberg::live.footer class="hb-editor__footer" :document-type="$hbDocumentType" :post-id="$postId ?? null"
        :email-size-url-template="$emailSizeUrlTemplate ?? ''" />

    <x-heisenberg::live.ai.ai-settings-dialog :payload="$aiPayload ?? []"
        :settings-url="$aiSettingsUrl ?? null"
        :key-url-template="$aiKeyUrlTemplate ?? null"
        :discover-url-template="$aiDiscoverUrlTemplate ?? null"
        :mcp-test-url="$aiMcpTestUrl ?? null" />

    <x-heisenberg::live.ai.ai-history-dialog />

    <x-heisenberg::live.block-runtime :registry="$registry" :blocks-css="$blocksCss" :registry-hash="$registryHash ?? ''"
        :post-id="$postId ?? null" :post-locale="$postLocale ?? 'en'" :content-locales="$contentLocales ?? ['en', 'fr']" />

    @if (! empty($initialBlocks))
        <script nonce="{{ heisenberg_csp_nonce() }}">
            (() => {
                const blocks = @json($initialBlocks);
                const hydrate = () => window.hbEditor.replaceDoc(blocks, { baseline: true });
                if (window.hbEditor && typeof window.hbEditor.replaceDoc === 'function') hydrate();
                else document.addEventListener('DOMContentLoaded', hydrate, { once: true });
            })();
        </script>
    @endif

    @if (($postId ?? null) === null)
        <script nonce="{{ heisenberg_csp_nonce() }}">
            (() => {
                const KEY = 'hb-editor:unsaved-draft';
                let adopted = false;
                let timer = null;

                const readDraft = () => { try { return JSON.parse(localStorage.getItem(KEY) || 'null'); } catch (e) { return null; } };
                const clearDraft = () => { try { localStorage.removeItem(KEY); } catch (e) { } };

                const titleEl = () => document.querySelector('[data-hb-title]');
                const readTitle = () => {
                    const el = titleEl();
                    if (!el) return '';
                    return (el.tagName === 'INPUT' ? el.value : el.textContent).trim();
                };
                const writeTitle = (value) => {
                    const el = titleEl();
                    if (!el || !value) return;
                    if (el.tagName === 'INPUT') el.value = value; else el.textContent = value;
                    el.dispatchEvent(new Event('input', { bubbles: true }));
                };

                const mirror = () => {
                    if (adopted) return;
                    clearTimeout(timer);
                    timer = setTimeout(() => {
                        if (adopted || !window.hbEditor) return;
                        const blocks = window.hbEditor.getDoc().blocks || [];
                        const title = readTitle();
                        if (!blocks.length && !title) { clearDraft(); return; }
                        try {
                            localStorage.setItem(KEY, JSON.stringify({ blocks: blocks, title: title, at: Date.now() }));
                        } catch (e) { }
                    }, 400);
                };

                const start = () => {
                    const draft = readDraft();
                    if (draft) {
                        if (draft.title) writeTitle(draft.title);
                        if (Array.isArray(draft.blocks) && draft.blocks.length) {
                            window.hbEditor.replaceDoc(draft.blocks, { baseline: true });
                        }
                    }
                    document.addEventListener('hb:blocks-changed', mirror);
                    document.addEventListener('hb:doc-title', mirror);
                    document.addEventListener('hb:post-id', () => { adopted = true; clearTimeout(timer); clearDraft(); });
                };
                if (window.hbEditor && typeof window.hbEditor.replaceDoc === 'function') start();
                else document.addEventListener('DOMContentLoaded', start, { once: true });
            })();
        </script>
    @endif
@endsection
