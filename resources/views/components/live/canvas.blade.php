@props(['title' => '', 'pagePaddingX' => 56, 'pagePaddingY' => 56, 'documentType' => 'post', 'postLocale' => 'en', 'contentLocaleLabels' => [], 'contentLocales' => []])
<style nonce="{{ heisenberg_csp_nonce() }}">
    .hb-page__locale-badge {
        display: inline-block; margin-bottom: 6px; padding: 2px 8px; border-radius: var(--hb-radius-full, 999px);
        background: var(--hb-bg-muted); color: var(--hb-text-secondary);
        font-family: var(--hb-font-sans, Rubik, sans-serif); font-size: var(--hb-fs-xs, 11px); font-weight: 600;
        letter-spacing: .02em; text-transform: uppercase;
    }
    /* The badge doubles as the editing-language control. It reads as a label until you
       approach it, which is the intent: it states which language you are editing, and
       changing that is one click from the statement itself. */
    .hb-page__locale { position: relative; display: block; margin-bottom: 6px; }
    .hb-page__locale .hb-page__locale-badge {
        display: inline-flex; align-items: center; gap: 4px; margin-bottom: 0;
        border: 0; cursor: pointer; font: inherit; font-size: var(--hb-fs-xs, 11px); font-weight: 600;
        letter-spacing: .02em; text-transform: uppercase;
    }
    .hb-page__locale .hb-page__locale-badge:hover { background: var(--hb-surface-hover); color: var(--hb-text-primary); }
    .hb-page__locale .hb-page__locale-badge:focus-visible { outline: 2px solid var(--hb-border-focus); outline-offset: 1px; }
    .hb-page__locale-caret { display: inline-flex; opacity: .7; }
    .hb-page__locale-menu {
        position: absolute; top: calc(100% + 4px); left: 0; z-index: 20; min-width: 160px;
        padding: 4px; border: 1px solid var(--hb-border); border-radius: var(--hb-radius-md, 6px);
        background: var(--hb-bg-panel, var(--hb-bg-elevated, #fff));
        box-shadow: var(--hb-shadow-md, 0 6px 20px rgba(0, 0, 0, .12));
    }
    .hb-page__locale-menu[hidden] { display: none; }
    .hb-page__locale-opt {
        display: flex; align-items: center; justify-content: space-between; gap: 8px;
        width: 100%; padding: 6px 8px; border: 0; border-radius: var(--hb-radius-sm, 3px);
        background: transparent; color: var(--hb-text-secondary); cursor: pointer;
        font-family: var(--hb-font-sans, Rubik, sans-serif); font-size: var(--hb-fs-sm, 12px); text-align: left;
    }
    .hb-page__locale-opt:hover { background: var(--hb-surface-hover); color: var(--hb-text-primary); }
    .hb-page__locale-opt__check { display: inline-flex; opacity: 0; }
    .hb-page__locale-opt.is-on { color: var(--hb-text-primary); font-weight: 600; }
    .hb-page__locale-opt.is-on .hb-page__locale-opt__check { opacity: 1; }
</style>
<div class="hb-canvas @if ($documentType === 'email') hb-canvas--email @endif" data-hb-canvas data-hb-document-type="{{ $documentType }}"
    data-hb-locale-labels="{{ json_encode($contentLocaleLabels) }}">
    <div class="hb-page" style="--hb-page-padding-x: {{ (int) $pagePaddingX }}px; --hb-page-padding-y: {{ (int) $pagePaddingY }}px;">
        @php $hbCanvasLocales = array_values(array_filter((array) $contentLocales)); @endphp
        @if (count($hbCanvasLocales) > 1)
            {{-- The editing-language control, on the badge that already named the language.
                 It carries the same data attributes the topbar dropdown used, so the existing
                 handlers in live/topbar/script.blade.php (which query document-wide) drive it
                 unchanged — this moved the control, it did not fork the wiring. --}}
            <div class="hb-page__locale" data-hb-langsel>
                <button type="button" class="hb-page__locale-badge" data-hb-lang-toggle
                    aria-haspopup="listbox" aria-expanded="false"
                    aria-label="{{ __('heisenberg::editor.topbar.aria_post_language') }}">
                    <span data-hb-editing-locale-badge>{{ $contentLocaleLabels[$postLocale] ?? strtoupper($postLocale) }}</span>
                    <span class="hb-page__locale-caret" aria-hidden="true">
                        @include('heisenberg::components.ui.icon', ['name' => 'caret-down', 'size' => 10])
                    </span>
                </button>
                <div class="hb-page__locale-menu" role="listbox" data-hb-lang-menu hidden>
                    @foreach ($hbCanvasLocales as $hbLoc)
                        <button type="button" class="hb-page__locale-opt @if ($hbLoc === $postLocale) is-on @endif"
                            role="option" aria-selected="{{ $hbLoc === $postLocale ? 'true' : 'false' }}"
                            data-hb-lang-option data-locale="{{ $hbLoc }}">
                            <span>{{ $contentLocaleLabels[$hbLoc] ?? __('heisenberg::editor.locales.' . $hbLoc) }}</span>
                            <span class="hb-page__locale-opt__check" aria-hidden="true">
                                @include('heisenberg::components.ui.icon', ['name' => 'check', 'size' => 12])
                            </span>
                        </button>
                    @endforeach
                </div>
            </div>
        @else
            {{-- One content locale: there is nothing to switch to, so it stays a plain label. --}}
            <span class="hb-page__locale-badge" data-hb-editing-locale-badge>{{ $contentLocaleLabels[$postLocale] ?? strtoupper($postLocale) }}</span>
        @endif
        <h1 class="hb-page__title" contenteditable="true" spellcheck="false" data-ph="{{ __($documentType === 'email' ? 'heisenberg::editor.canvas.ph_untitled_email' : 'heisenberg::editor.canvas.ph_untitled_post') }}" data-hb-title>{{ $title }}</h1>
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
            const fallbackTitle = @json(__($documentType === 'email' ? 'heisenberg::editor.canvas.ph_untitled_email' : 'heisenberg::editor.canvas.ph_untitled_post'));
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
