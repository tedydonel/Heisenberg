{{-- live/canvas — the document canvas. A white paper sheet
     with the editable post title and the blocks area (empty-state = a single add-block button).
     The title is the source of truth and stays in sync with the inspector's Post tab title (both
     carry data-hb-title); rendering inserted blocks is the backend step. The sheet is ALWAYS a
     light/white paper regardless of the editor theme — see 34-canvas.css. --}}
{{-- pagePaddingX/pagePaddingY (px) render straight into inline custom properties so the real
     per-post value (or the 56/56 default — see EditorController's own constants) paints on
     first load, instead of flashing 34-canvas.css's own var() fallback first and only correcting
     once the Page layout section's slider JS boots (see inspector.blade.php's wirePostLayout). --}}
@props(['title' => '', 'pagePaddingX' => 56, 'pagePaddingY' => 56, 'documentType' => 'post', 'postLocale' => 'en', 'contentLocaleLabels' => []])
{{-- documentType 'email' (docs/email-system.md §7-E3) constrains the page to the same 600px
     content width EmailRenderer builds its shell around (§5.3) — a hint, not an enforcement:
     the block engine renders it exactly the same either way, this only narrows the frame so the
     author sees roughly what an email client will show. See 34-canvas.css's .hb-canvas--email
     rule, declared BEFORE the device-diff (.hb-canvas--tablet/--mobile) rules so the device
     selector still wins when both classes land on the canvas together. --}}
<style>
    {{-- The editing-locale badge (docs/content-translation.md §0/Wave 2) — the canvas-side half
         of "make the current editing locale visible enough that nobody edits French text
         believing it is English" (the topbar dropdown label is the other half). --}}
    .hb-page__locale-badge {
        display: inline-block; margin-bottom: 6px; padding: 2px 8px; border-radius: var(--hb-radius-full, 999px);
        background: var(--hb-bg-muted, #F4F4F4); color: var(--hb-text-secondary, #5A5A5A);
        font-family: var(--hb-font-sans, Rubik, sans-serif); font-size: var(--hb-fs-xs, 11px); font-weight: 600;
        letter-spacing: .02em; text-transform: uppercase;
    }
</style>
<div class="hb-canvas @if ($documentType === 'email') hb-canvas--email @endif" data-hb-canvas data-hb-document-type="{{ $documentType }}"
    data-hb-locale-labels="{{ json_encode($contentLocaleLabels) }}">
    <div class="hb-page" style="--hb-page-padding-x: {{ (int) $pagePaddingX }}px; --hb-page-padding-y: {{ (int) $pagePaddingY }}px;">
        <span class="hb-page__locale-badge" data-hb-editing-locale-badge>{{ $contentLocaleLabels[$postLocale] ?? strtoupper($postLocale) }}</span>
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
            // Editing-locale badge — corrects the SSR-rendered (home-locale) label once at boot
            // for a persisted non-home choice (block-runtime.blade.php resolves it from
            // localStorage before this runs), then stays live on every switch.
            const localeLabels = () => { try { return JSON.parse(document.querySelector('[data-hb-canvas]')?.dataset.hbLocaleLabels || '{}') || {}; } catch (e) { return {}; } };
            const applyLocaleBadge = (locale) => {
                const label = localeLabels()[locale] || locale.toUpperCase();
                document.querySelectorAll('[data-hb-editing-locale-badge]').forEach((b) => { b.textContent = label; });
            };
            document.addEventListener('hb:editing-locale-change', (e) => applyLocaleBadge(e.detail.locale));

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
                if (window.hbEditor && window.hbEditor.getEditingLocale) applyLocaleBadge(window.hbEditor.getEditingLocale());
            };
            if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot, { once: true });
            else boot();
            document.addEventListener('hb:refresh', boot);
        })();
    </script>
    @endonce
</div>
