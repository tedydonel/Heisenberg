@extends('heisenberg::editor.layouts.app')

@section('content')
    {{-- The user theme's own `--hb-t-*` custom properties. Only preview.blade.php emitted these,
         so in the editor every token reference resolved to nothing: the Style/Themes panel could
         save tokens the canvas then could not display, and binding a block style to one was
         pointless. Emitted first so everything below can reference them.

         This is the SAVED theme at render time. Live edits in the Style tab still only debounce a
         PUT; nothing rewrites these properties in place, so an unsaved edit is not previewed
         until reload. --}}
    <style id="hb-theme-vars">{!! $themeCss ?? '' !!}</style>
    @php
        $hbDocumentType = $documentType ?? 'post';
    @endphp
    <x-live.topbar class="hb-editor__topbar" :post-id="$postId ?? null" :content-version="$contentVersion ?? 0"
        :post-translations="$postTranslations ?? null"
        :post-translations-url-template="$postTranslationsUrlTemplate ?? ''"
        :post-editor-url-template="$postEditorUrlTemplate ?? ''"
        :locale-default="$localeDefault ?? 'en'"
        :document-type="$hbDocumentType"
        :email-preview-url-template="$emailPreviewUrlTemplate ?? ''"
        :email-export-url-template="$emailExportUrlTemplate ?? ''" />
    <x-live.sidebar class="hb-editor__sidebar" :document-type="$hbDocumentType" />
    {{-- All 4 panel pairs live in the DOM simultaneously; only one is visible at a time.
         Switching is driven by sidebar nav clicks — see live/sidebar's script, which toggles
         [hidden] here and activates the matching internal panel-tabs tab. --}}
    <div class="hb-editor__panel">
        {{-- Components tab: `paletteBlocks` (EditorController) instead of the full `$registry` —
             already filtered server-side to the email surface for an email document (docs/
             email-system.md §7-E3). The quick-inserter below reads the same seed, so it follows
             automatically with no extra wiring. --}}
        <x-live.panel-components-blocks :registry="$paletteBlocks ?? $registry" />
        @if ($hbDocumentType !== 'email')
        <x-live.panel-seo-social hidden
            :post-id="$postId ?? null"
            :post-title="$postTitle ?? ''"
            :post-slug="$postSlug ?? ''"
            :post-seo="$postSeo ?? null"
            :seo-analyze-url-template="$postSeoAnalyzeUrlTemplate ?? ''" />
        @endif
        <x-live.panel-style-themes hidden :theme="$theme ?? []" :saved-themes="$savedThemes ?? []" :font-options="$fontOptions ?? []"
            :theme-update-url="route('heisenberg.editor.theme.update')" :fonts-search-url="route('heisenberg.editor.fonts.search')"
            :themes-store-url="route('heisenberg.editor.themes.store')" :themes-destroy-url="route('heisenberg.editor.themes.destroy')" />
        <x-live.panel-ai hidden :stream-url="route('heisenberg.editor.ai.stream')"
            :conversations-url="route('heisenberg.editor.ai.conversations.index')"
            :suggest-url="route('heisenberg.editor.ai.suggest')"
            :model-options="$aiModelOptions ?? []"
            :active-model="$aiActiveModel ?? null"
            :locale="app()->getLocale()"
            :post-id="$postId ?? null" />
        {{-- Navigator (List View | Outline) — hidden until the topbar Layers button opens it. --}}
        <x-live.panel-navigator hidden :registry="$registry" />
    </div>
    <div class="hb-editor__canvas">
        <x-live.canvas :title="$postTitle ?? ''" :page-padding-x="$postPagePaddingX ?? 56" :page-padding-y="$postPagePaddingY ?? 56"
            :document-type="$hbDocumentType" />
        {{-- Code view (shortcode dialect of the block contracts) — hidden until the footer's
             Code Editor chip toggles it; occupies the same slot as the canvas. --}}
        <x-live.code-editor hidden />
        {{-- The quick inserter popup — hidden until an appender fires the runtime's cancelable
             hb:quick-insert, which this component claims (preventDefault) to offer every block
             instead of the runtime's paragraph fallback. Same filtered seed as the Components tab. --}}
        <x-live.quick-inserter :registry="$paletteBlocks ?? $registry" />
        {{-- Block image picker — an empty image block's placeholder (decorateImageBlock,
             block-runtime) dispatches the cancelable hb:pick-image with the block id; this
             dialog claims it, and a Library/Upload pick writes url + alt back through the
             public runtime API. Separate instance from the Post tab's featured-image dialog,
             whose hb:media-select listener writes to the featured-image inputs instead. --}}
        @php
            $hbBlockImageSelectUrl = \Illuminate\Support\Facades\Route::has('media.select') ? route('media.select') : null;
            $hbBlockImageUploadUrl = \Illuminate\Support\Facades\Route::has('media.upload') ? route('media.upload') : null;
        @endphp
        <x-live.media.media-dialog
            data-hb-block-image-dialog
            hidden
            :scrim="true"
            tab="library"
            accept="image/*"
            :title="__('heisenberg::editor.media.select_image')"
            :select-url="$hbBlockImageSelectUrl"
            :upload-url="$hbBlockImageUploadUrl"
        />
        <script>
            (() => {
                let hbImageTargetId = null;
                const hbImageDialog = () => document.querySelector('[data-hb-block-image-dialog]');
                if (!document.__hbBlockImagePicker) {
                    document.__hbBlockImagePicker = true;
                    document.addEventListener('hb:pick-image', (e) => {
                        const dialog = hbImageDialog();
                        if (!dialog || typeof dialog.hbOpen !== 'function') return;
                        e.preventDefault(); // claimed — the intent is handled here
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
        {{-- The icon block's library picker — opened by the runtime's cancelable hb:pick-icon
             (an empty icon block's placeholder); a pick writes the "<set>/<slug>" reference
             through hbEditor.setAttribute. --}}
        <x-live.icon-picker-dialog
            :search-url="\Illuminate\Support\Facades\Route::has('heisenberg.editor.icons.search') ? route('heisenberg.editor.icons.search') : null"
            :sets="app(\Heisenberg\Services\IconLibraryService::class)->sets()" />
        <x-ui.custom-scrollbar container=".hb-canvas" />
        {{-- The floating block toolbar lives here (hidden) until a block is selected; the
             block runtime moves it above the selected block and gates it by that block's supports. --}}
        <div class="hb-blk-toolbar-holder" hidden>
            <x-live.toolbar.block-toolbar data-hb-block-toolbar :rich-text="true" :block-type="'Text'" :active-formats="[]"
                :theme-tokens="$themeTokens['color'] ?? []"
                :supports="['color' => ['text' => true, 'background' => true], 'align' => ['left', 'center', 'right']]" />
        </div>
    </div>
    <x-live.inspector class="hb-editor__inspector" :registry="$registry" :post-title="$postTitle ?? ''"
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
        :post-featured-image="$postFeaturedImage ?? null"
        :post-featured-image-url-template="$postFeaturedImageUrlTemplate ?? ''"
        :post-toc-entries="$postTocEntries ?? []"
        :post-toc-url-template="$postTocUrlTemplate ?? ''"
        :post-translations="$postTranslations ?? null"
        :post-translations-url-template="$postTranslationsUrlTemplate ?? ''"
        :post-editor-url-template="$postEditorUrlTemplate ?? ''"
        :fonts-search-url="route('heisenberg.editor.fonts.search')"
        :theme="$theme ?? []"
        :document-type="$hbDocumentType" />
    <x-live.footer class="hb-editor__footer" :document-type="$hbDocumentType" :post-id="$postId ?? null"
        :email-size-url-template="$emailSizeUrlTemplate ?? ''" />

    {{-- AI settings, opened by the AI panel's header button. Mounted at page level rather than
         inside the panel (which the sidebar hides when another panel is active) so the dialog is
         reachable from anywhere that dispatches [data-hb-ai-settings-open]. Its scrim is
         position:fixed, so where it sits in the tree has no visual effect. --}}
    <x-live.ai.ai-settings-dialog :payload="$aiPayload ?? []"
        :settings-url="$aiSettingsUrl ?? null"
        :key-url-template="$aiKeyUrlTemplate ?? null"
        :discover-url-template="$aiDiscoverUrlTemplate ?? null"
        :mcp-test-url="$aiMcpTestUrl ?? null" />

    {{-- AI chat history, opened by the AI panel header's notepad button. Page level for the
         same reason as the settings dialog above; it reads its URLs off the panel root's
         dataset and hands a chosen conversation back via hb:ai-open-conversation. --}}
    <x-live.ai.ai-history-dialog />

    {{-- The block model: registry + render/insert/select runtime. Kept last so the canvas,
         panels, inspector and toolbar it wires all exist in the DOM before it boots. --}}
    <x-live.block-runtime :registry="$registry" :blocks-css="$blocksCss" :registry-hash="$registryHash ?? ''" />

    @if (! empty($initialBlocks))
        {{-- Hydrates an existing post's block tree through window.hbEditor.replaceDoc(): models
             land in the save shape, get fresh ids, defaults merged, and nested innerBlocks
             reconstructed. A block whose `name` is no longer a registered/enabled contract is
             dropped (same rule as insertBlock returning null) and would vanish on the next save. --}}
        <script>
            (() => {
                const blocks = @json($initialBlocks);
                const hydrate = () => window.hbEditor.replaceDoc(blocks, { baseline: true });
                if (window.hbEditor && typeof window.hbEditor.replaceDoc === 'function') hydrate();
                else document.addEventListener('DOMContentLoaded', hydrate, { once: true });
            })();
        </script>
    @endif

    @if (($postId ?? null) === null)
        {{-- Unsaved-draft survival for the blank /editor. Autosave deliberately never CREATES a
             post (an abandoned keystroke session must not spawn a stray draft row), which meant a
             refresh before the first explicit Save silently discarded everything on the canvas.
             The document is mirrored to localStorage instead (blocks + title, debounced on the
             same hb:blocks-changed / hb:doc-title signals autosave keys off) and restored here on
             the next blank-editor load. The moment the first Save gives the post an id
             (hb:post-id — the URL adopts /editor/{id} at the same time), the DB owns persistence
             and the local draft is cleared. Saved posts never touch this path: this whole block
             only renders when the server passed no post. --}}
        <script>
            (() => {
                const KEY = 'hb-editor:unsaved-draft';
                let adopted = false;
                let timer = null;

                const readDraft = () => { try { return JSON.parse(localStorage.getItem(KEY) || 'null'); } catch (e) { return null; } };
                const clearDraft = () => { try { localStorage.removeItem(KEY); } catch (e) { /* private mode */ } };

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
                        } catch (e) { /* quota/private mode — persistence is best-effort */ }
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
