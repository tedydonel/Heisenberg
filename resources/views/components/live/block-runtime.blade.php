

@props(['registry' => [], 'blocksCss' => '', 'registryHash' => '', 'postId' => null, 'postLocale' => 'en', 'contentLocales' => ['en', 'fr'], 'emailVariables' => []])



<style id="hb-blocks-css" nonce="{{ heisenberg_csp_nonce() }}">{!! $blocksCss !!}</style>

<script nonce="{{ heisenberg_csp_nonce() }}">
    window.__hbEditor = Object.assign(window.__hbEditor || {}, {
        registry: @json($registry),

        registryHash: @json($registryHash),

        iconUrlTemplate: @json(\Illuminate\Support\Facades\Route::has('heisenberg.editor.asset.icon') ? route('heisenberg.editor.asset.icon', ['set' => '__SET__', 'slug' => '__SLUG__']) : ''),

        postId: @json($postId),
        postLocale: @json($postLocale),
        contentLocales: @json($contentLocales),
        emailVariables: @json($emailVariables),
    });
</script>

@once
<script nonce="{{ heisenberg_csp_nonce() }}">
(() => {
    {{--
        Table of contents. Each partial below is included, in this exact order, into this same
        <script> tag and this same IIFE — together they form one closure, exactly as if this were
        still one file; splitting only changes how the source is organized on disk. See each
        partial's own leading comment (resources/views/components/live/block-runtime/*.blade.php)
        for what it owns and which closure variables it depends on / defines. Do not reorder
        these: later partials rely on const/let bindings and function declarations from earlier
        ones. This comment is Blade syntax so it never reaches the rendered page (unlike a plain
        // JS comment, which would).

         01 bootstrap-and-email-variables  DATA/REGISTRY, EMAIL_VARIABLES, editing-locale bootstrap
         02 doc-model                      translatable-attribute keys, doc/blockSeq, tree lookups
         03 style-sanitizers               template substitution, inline-style value validators
         04 render-support                 preview states, icon injection, styleDeclarations, embeds
         05 render-tree                    renderNode()/renderBlockEl() — the DOM renderer
         06 selection-support              caret save/restore, insertBlock(), toolbar gating
         07 toolbar-and-selection          floating toolbar positioning, select()/deselect()
         08 tree-ops                       attribute/support writes, move/insert/remove/duplicate
         09 drag-drop                      canvas block drag-and-drop
         10 palette-and-boot               container resize, palette drag source, boot() wiring
         11 doc-replace-and-translation    normalizeModel/replaceDoc, AI translation folding
         12 history-and-locale-switch      undo/redo history, setEditingLocale()
         13 api                            the public window.hbEditor object
    --}}@include('heisenberg::components.live.block-runtime.01-bootstrap-and-email-variables')
    @include('heisenberg::components.live.block-runtime.02-doc-model')
    @include('heisenberg::components.live.block-runtime.03-style-sanitizers')
    @include('heisenberg::components.live.block-runtime.04-render-support')
    @include('heisenberg::components.live.block-runtime.05-render-tree')
    @include('heisenberg::components.live.block-runtime.06-selection-support')
    @include('heisenberg::components.live.block-runtime.07-toolbar-and-selection')
    @include('heisenberg::components.live.block-runtime.08-tree-ops')
    @include('heisenberg::components.live.block-runtime.09-drag-drop')
    @include('heisenberg::components.live.block-runtime.10-palette-and-boot')
    @include('heisenberg::components.live.block-runtime.11-doc-replace-and-translation')
    @include('heisenberg::components.live.block-runtime.12-history-and-locale-switch')
    @include('heisenberg::components.live.block-runtime.13-api')
})();
</script>
@endonce
