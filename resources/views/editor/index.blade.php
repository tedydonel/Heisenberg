@extends('heisenberg::editor.layouts.app')

@section('content')
    {{-- The user theme's own `--hb-t-*` custom properties. Only preview.blade.php emitted these,
         so in the editor every token reference resolved to nothing: the Style/Themes panel could
         save tokens the canvas then could not display, and binding a block style to one was
         pointless. Emitted first so everything below can reference them. (TODO 7.6)

         This is the SAVED theme at render time. Live edits in the Style tab still only debounce a
         PUT; nothing rewrites these properties in place, so an unsaved edit is not previewed until
         reload — see TODO 7.6. --}}
    <style id="hb-theme-vars">{!! $themeCss ?? '' !!}</style>
    <x-live.topbar class="hb-editor__topbar" :post-id="$postId ?? null" :content-version="$contentVersion ?? 0" />
    <x-live.sidebar class="hb-editor__sidebar" />
    {{-- All 4 panel pairs live in the DOM simultaneously; only one is visible at a time.
         Switching is driven by sidebar nav clicks — see live/sidebar's script, which toggles
         [hidden] here and activates the matching internal panel-tabs tab. Not sourced from
         Pencil (the design shows static state per screen, not a live switcher). --}}
    <div class="hb-editor__panel">
        <x-live.panel-components-blocks :registry="$registry" />
        <x-live.panel-seo-social hidden />
        <x-live.panel-style-themes hidden :theme="$theme ?? []" :saved-themes="$savedThemes ?? []" :font-options="$fontOptions ?? []"
            :theme-update-url="route('heisenberg.editor.theme.update')" :fonts-search-url="route('heisenberg.editor.fonts.search')"
            :themes-store-url="route('heisenberg.editor.themes.store')" :themes-destroy-url="route('heisenberg.editor.themes.destroy')" />
        <x-live.panel-ai-tools hidden />
        {{-- Navigator (List View | Outline) — hidden until the topbar Layers button opens it. --}}
        <x-live.panel-navigator hidden />
    </div>
    <div class="hb-editor__canvas">
        <x-live.canvas :title="$postTitle ?? ''" :page-padding-x="$postPagePaddingX ?? 56" :page-padding-y="$postPagePaddingY ?? 56" />
        <x-ui.custom-scrollbar container=".hb-canvas" />
        {{-- The floating block toolbar lives here (hidden) until a block is selected; the
             block runtime moves it above the selected block and gates it by that block's supports. --}}
        <div class="hb-blk-toolbar-holder" hidden>
            <x-live.toolbar.block-toolbar data-hb-block-toolbar :rich-text="true" :block-type="'Text'" :active-formats="[]"
                :supports="['color' => ['text' => true, 'background' => true], 'align' => ['left', 'center', 'right']]" />
        </div>
    </div>
    <x-live.inspector class="hb-editor__inspector" :registry="$registry" :post-title="$postTitle ?? ''"
        :post-id="$postId ?? null" :post-category-ids="$postCategoryIds ?? []" :post-tag-ids="$postTagIds ?? []"
        :category-options="$categoryOptions ?? []" :tag-options="$tagOptions ?? []"
        :categories-index-url="$categoriesIndexUrl" :tags-index-url="$tagsIndexUrl"
        :post-category-url-template="$postCategoryUrlTemplate" :post-tags-url-template="$postTagsUrlTemplate"
        :post-page-padding-x="$postPagePaddingX ?? 56" :post-page-padding-y="$postPagePaddingY ?? 56"
        :post-layout-url-template="$postLayoutUrlTemplate" :post-allow-comments="$postAllowComments ?? true"
        :post-discussion-url-template="$postDiscussionUrlTemplate"
        :fonts-search-url="route('heisenberg.editor.fonts.search')"
        :theme="$theme ?? []" />
    <x-live.footer class="hb-editor__footer" />

    {{-- The block model: registry + render/insert/select runtime. Kept last so the canvas,
         panels, inspector and toolbar it wires all exist in the DOM before it boots. --}}
    <x-live.block-runtime :registry="$registry" :blocks-css="$blocksCss" :registry-hash="$registryHash ?? ''" />

    @if (! empty($initialBlocks))
        {{-- Hydrates an existing post's block tree into the (frozen) block-runtime's document
             model, entirely through its documented public window.hbEditor API — insertBlock()
             for each top-level block, then setAttribute()/setSupport() to overwrite that block's
             defaults with its saved values. block-runtime.blade.php is not edited to do this.
             Known gaps (the runtime has no other public hook for them today): nested
             `innerBlocks` are not reconstructed (the runtime doesn't render inner-blocks at all
             yet — see its own file header); a block whose `name` is no longer a registered/
             enabled contract is silently dropped by insertBlock() returning null, which would
             then vanish for real on the next save. --}}
        <script>
            (() => {
                const blocks = @json($initialBlocks);

                // supports can nest (e.g. {color: {text: '...'}}) — setSupport() takes a single
                // dotted path per leaf value, so walk the tree and flatten it into those calls.
                const applySupports = (id, node, prefix) => {
                    Object.keys(node || {}).forEach((key) => {
                        const path = prefix ? prefix + '.' + key : key;
                        const value = node[key];
                        if (value !== null && typeof value === 'object' && !Array.isArray(value)) {
                            applySupports(id, value, path);
                        } else {
                            window.hbEditor.setSupport(id, path, value);
                        }
                    });
                };

                const hydrate = () => {
                    blocks.forEach((block) => {
                        if (!block || typeof block !== 'object' || !block.name) return;
                        const el = window.hbEditor.insertBlock(block.name);
                        if (!el) return; // contract no longer registered/enabled — see note above
                        const id = el.getAttribute('data-block');
                        const attributes = block.attributes || {};
                        Object.keys(attributes).forEach((key) => window.hbEditor.setAttribute(id, key, attributes[key]));
                        applySupports(id, block.supports || {}, '');
                    });
                };

                if (window.hbEditor && typeof window.hbEditor.insertBlock === 'function') hydrate();
                else document.addEventListener('DOMContentLoaded', hydrate, { once: true });
            })();
        </script>
    @endif
@endsection
