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
    <x-live.topbar class="hb-editor__topbar" :post-id="$postId ?? null" :content-version="$contentVersion ?? 0" />
    <x-live.sidebar class="hb-editor__sidebar" />
    {{-- All 4 panel pairs live in the DOM simultaneously; only one is visible at a time.
         Switching is driven by sidebar nav clicks — see live/sidebar's script, which toggles
         [hidden] here and activates the matching internal panel-tabs tab. --}}
    <div class="hb-editor__panel">
        <x-live.panel-components-blocks :registry="$registry" />
        <x-live.panel-seo-social hidden />
        <x-live.panel-style-themes hidden :theme="$theme ?? []" :saved-themes="$savedThemes ?? []" :font-options="$fontOptions ?? []"
            :theme-update-url="route('heisenberg.editor.theme.update')" :fonts-search-url="route('heisenberg.editor.fonts.search')"
            :themes-store-url="route('heisenberg.editor.themes.store')" :themes-destroy-url="route('heisenberg.editor.themes.destroy')" />
        <x-live.panel-ai-tools hidden />
        {{-- Navigator (List View | Outline) — hidden until the topbar Layers button opens it. --}}
        <x-live.panel-navigator hidden :registry="$registry" />
    </div>
    <div class="hb-editor__canvas">
        <x-live.canvas :title="$postTitle ?? ''" :page-padding-x="$postPagePaddingX ?? 56" :page-padding-y="$postPagePaddingY ?? 56" />
        {{-- Code view (shortcode dialect of the block contracts) — hidden until the footer's
             Code Editor chip toggles it; occupies the same slot as the canvas. --}}
        <x-live.code-editor hidden />
        {{-- The quick inserter popup — hidden until an appender fires the runtime's cancelable
             hb:quick-insert, which this component claims (preventDefault) to offer every block
             instead of the runtime's paragraph fallback. --}}
        <x-live.quick-inserter :registry="$registry" />
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
        :post-id="$postId ?? null" :post-category-ids="$postCategoryIds ?? []" :post-tag-ids="$postTagIds ?? []"
        :category-options="$categoryOptions ?? []" :tag-options="$tagOptions ?? []"
        :categories-index-url="$categoriesIndexUrl" :tags-index-url="$tagsIndexUrl"
        :post-category-url-template="$postCategoryUrlTemplate" :post-tags-url-template="$postTagsUrlTemplate"
        :post-page-padding-x="$postPagePaddingX ?? 56" :post-page-padding-y="$postPagePaddingY ?? 56"
        :post-layout-url-template="$postLayoutUrlTemplate" :post-allow-comments="$postAllowComments ?? true"
        :post-discussion-url-template="$postDiscussionUrlTemplate"
        :post-revisions-url-template="$postRevisionsUrlTemplate ?? ''"
        :fonts-search-url="route('heisenberg.editor.fonts.search')"
        :theme="$theme ?? []" />
    <x-live.footer class="hb-editor__footer" />

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
@endsection
