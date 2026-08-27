@props(['title' => '', 'pagePaddingX' => 56, 'pagePaddingY' => 56, 'documentType' => 'post', 'postLocale' => 'en', 'contentLocaleLabels' => []])
<style nonce="{{ heisenberg_csp_nonce() }}">
    .hb-page__locale-badge {
        display: inline-block; margin-bottom: 6px; padding: 2px 8px; border-radius: var(--hb-radius-full, 999px);
        background: var(--hb-bg-muted); color: var(--hb-text-secondary);
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
            <button type="button" class="hb-appender" data-hb-insert aria-label="{{ __('heisenberg::editor.common.add_block') }}">
                @include('heisenberg::components.ui.icon', ['name' => 'plus', 'size' => 16])
            </button>
        </div>
    </div>
    @once
    <script nonce="{{ heisenberg_csp_nonce() }}">
        (() => {
            const val = (el) => (el.tagName === 'INPUT' ? el.value : el.textContent).trim();
            const setVal = (el, v) => { if (el.tagName === 'INPUT') el.value = v; else el.textContent = v; };
            const markEmpty = (el) => { if (el.isContentEditable) el.classList.toggle('is-empty', val(el) === ''); };
            let syncing = false;
            const fallbackTitle = @json(__('heisenberg::editor.canvas.ph_untitled_post'));
            const setDocTitle = (v) => { document.title = v.trim() !== '' ? v : fallbackTitle; };
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
