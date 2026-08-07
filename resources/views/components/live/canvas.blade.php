{{-- live/canvas — the document canvas. A white paper sheet
     with the editable post title and the blocks area (empty-state = a single add-block button).
     The title is the source of truth and stays in sync with the inspector's Post tab title (both
     carry data-hb-title); rendering inserted blocks is the backend step. The sheet is ALWAYS a
     light/white paper regardless of the editor theme — see 34-canvas.css. --}}
{{-- pagePaddingX/pagePaddingY (px) render straight into inline custom properties so the real
     per-post value (or the 56/56 default — see EditorController's own constants) paints on
     first load, instead of flashing 34-canvas.css's own var() fallback first and only correcting
     once the Page layout section's slider JS boots (see inspector.blade.php's wirePostLayout). --}}
@props(['title' => '', 'pagePaddingX' => 56, 'pagePaddingY' => 56])
<div class="hb-canvas" data-hb-canvas>
    <div class="hb-page" style="--hb-page-padding-x: {{ (int) $pagePaddingX }}px; --hb-page-padding-y: {{ (int) $pagePaddingY }}px;">
        <h1 class="hb-page__title" contenteditable="true" spellcheck="false" data-ph="{{ __('heisenberg::editor.canvas.ph_untitled_post') }}" data-hb-title>{{ $title }}</h1>
        <div class="hb-page__blocks" data-hb-add-label="{{ __('heisenberg::editor.common.add_block') }}">
            {{-- Boots empty; on insert the runtime renders contract blocks here (backend wiring). --}}
            <button type="button" class="hb-appender" data-hb-insert aria-label="{{ __('heisenberg::editor.common.add_block') }}">
                @include('heisenberg::components.ui.icon', ['name' => 'plus', 'size' => 16])
            </button>
        </div>
    </div>
    @once
    <script>
        (() => {
            const val = (el) => (el.tagName === 'INPUT' ? el.value : el.textContent).trim();
            const setVal = (el, v) => { if (el.tagName === 'INPUT') el.value = v; else el.textContent = v; };
            const markEmpty = (el) => { if (el.isContentEditable) el.classList.toggle('is-empty', val(el) === ''); };
            let syncing = false;
            // The browser tab carries the post's own name (see editor/layouts/app.blade.php's
            // <title>), so keep it current as the title is typed rather than only at page load.
            const fallbackTitle = @json(__('heisenberg::editor.canvas.ph_untitled_post'));
            const setDocTitle = (v) => { document.title = v.trim() !== '' ? v : fallbackTitle; };
            const boot = () => {
                document.querySelectorAll('[data-hb-title]').forEach((el) => {
                    markEmpty(el);
                    if (el.__hbTitle) return; el.__hbTitle = true;
                    el.addEventListener('input', () => {
                        if (syncing) return; syncing = true;
                        const v = val(el);
                        document.querySelectorAll('[data-hb-title]').forEach((other) => { if (other !== el) { setVal(other, v); markEmpty(other); } });
                        markEmpty(el);
                        setDocTitle(v);
                        document.dispatchEvent(new CustomEvent('hb:doc-title', { detail: { title: v } }));
                        syncing = false;
                    });
                });
            };
            if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot, { once: true });
            else boot();
            document.addEventListener('hb:refresh', boot);
        })();
    </script>
    @endonce
</div>
