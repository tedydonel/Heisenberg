@once
<style>
    .hb-footer {
        display: flex;
        align-items: center;
        justify-content: space-between;
        width: 100%;
        height: 32px;
        background: var(--hb-bg);
        border-top: 1px solid var(--hb-border);
        overflow: hidden;
    }
    .hb-footer__zone { display: flex; align-items: center; gap: 2px; height: 100%; padding: 2px var(--hb-space-3, 12px); }
    .hb-footer__pill {
        display: inline-flex;
        align-items: center;
        gap: var(--hb-space-2, 8px);
        height: 100%;
        padding: var(--hb-space-1, 4px) var(--hb-space-3, 12px);
        border-radius: var(--hb-radius-sm, 3px);
        font-family: var(--hb-font-sans, Rubik, sans-serif);
        font-size: 12px;
    }
    .hb-footer__pill--muted { background: var(--hb-bg-inset); color: var(--hb-text-secondary); }
    .hb-footer__pill--muted .hb-footer__icon { color: var(--hb-text-muted); }
    .hb-footer__icon { display: inline-flex; width: 13px; height: 13px; flex: none; }

    .hb-footer__pill--status .hb-footer__icon { display: none; }
    .hb-footer__pill--status[data-state="saved"] .hb-footer__icon[data-hb-status-icon="saved"],
    .hb-footer__pill--status[data-state="saving"] .hb-footer__icon[data-hb-status-icon="saving"],
    .hb-footer__pill--status[data-state="dirty"] .hb-footer__icon[data-hb-status-icon="dirty"],
    .hb-footer__pill--status[data-state="offline"] .hb-footer__icon[data-hb-status-icon="offline"],
    .hb-footer__pill--status[data-state="conflict"] .hb-footer__icon[data-hb-status-icon="conflict"],
    .hb-footer__pill--status[data-state="error"] .hb-footer__icon[data-hb-status-icon="error"] { display: inline-flex; }
    .hb-footer__pill--status { background: transparent; }
    .hb-footer__pill--status[data-state="saved"] { color: var(--hb-success); }
    .hb-footer__pill--status[data-state="saved"] .hb-footer__icon { color: var(--hb-success); }
    .hb-footer__pill--status[data-state="saving"],
    .hb-footer__pill--status[data-state="dirty"] { color: var(--hb-text-secondary); }
    .hb-footer__pill--status[data-state="saving"] .hb-footer__icon,
    .hb-footer__pill--status[data-state="dirty"] .hb-footer__icon { color: var(--hb-text-muted); }
    .hb-footer__pill--status[data-state="offline"],
    .hb-footer__pill--status[data-state="conflict"],
    .hb-footer__pill--status[data-state="error"] { color: var(--hb-danger); }
    .hb-footer__pill--status[data-state="offline"] .hb-footer__icon,
    .hb-footer__pill--status[data-state="conflict"] .hb-footer__icon,
    .hb-footer__pill--status[data-state="error"] .hb-footer__icon { color: var(--hb-danger); }
    .hb-footer__pill--status[data-state="saving"] .hb-footer__icon[data-hb-status-icon="saving"] { animation: hb-status-spin 1s linear infinite; }
    @keyframes hb-status-spin { to { transform: rotate(360deg); } }

    .hb-footer__pill--email-size { color: var(--hb-text-secondary); }
    .hb-footer__pill--email-size .hb-footer__icon { color: var(--hb-text-muted); }
    .hb-footer__pill--email-size[data-warn="true"] { color: var(--hb-warning); }
    .hb-footer__pill--email-size[data-warn="true"] .hb-footer__icon { color: var(--hb-warning); }

    .hb-foot-chip {
        display: inline-flex; align-items: center;
        height: 100%; padding: 0 var(--hb-space-2, 8px);
        border: 0; background: transparent; border-radius: 0;
        font-family: var(--hb-font-sans, Rubik, sans-serif); font-size: 12px;
        color: var(--hb-text-secondary); cursor: pointer;
    }
    .hb-foot-chip:hover { color: var(--hb-text-primary); }
    .hb-foot-chip[aria-pressed="true"] { color: var(--hb-accent); font-weight: 600; }

    .hb-locale { display: inline-flex; }
    .hb-locale__menu {
        position: fixed; z-index: 60;
        min-width: 140px; padding: 4px;
        background: var(--hb-bg); border: 1px solid var(--hb-border);
        border-radius: var(--hb-radius-md, 5px);
        box-shadow: 3px 4px 4px rgba(0, 0, 0, .1);
        display: flex; flex-direction: column; gap: 2px;
    }
    .hb-locale__menu[hidden] { display: none; }
    .hb-locale__opt {
        display: inline-flex; align-items: center; justify-content: space-between; gap: 8px;
        height: 28px; padding: 0 8px;
        border: 0; background: none; border-radius: var(--hb-radius-sm, 3px);
        font-family: var(--hb-font-sans, Rubik, sans-serif); font-size: 12px; font-weight: 400;
        color: var(--hb-text-secondary); text-align: left; cursor: pointer;
        white-space: nowrap; width: 100%;
    }
    .hb-locale__opt:hover { background: var(--hb-surface-hover); color: var(--hb-text-primary); }
    .hb-locale__opt.is-on { background: var(--hb-surface-hover); color: var(--hb-text-primary); font-weight: 500; }
    .hb-locale__opt__check { width: 12px; height: 12px; flex: none; color: var(--hb-accent); display: none; }
    .hb-locale__opt.is-on .hb-locale__opt__check { display: inline-flex; }
</style>
<script>
    (() => {
        const placeMenu = (menu, toggle) => {
            if (!menu || !toggle) return;
            const r = toggle.getBoundingClientRect();
            menu.style.top = (r.top - menu.offsetHeight - 5) + 'px';
            menu.style.left = (r.right - menu.offsetWidth) + 'px';
        };
        const closeAll = () => {
            document.querySelectorAll('.hb-locale__menu').forEach((m) => { m.hidden = true; });
        };
        const boot = () => {
            document.querySelectorAll('[data-hb-locale]').forEach((root) => {
                if (root.__hbLocale) return; root.__hbLocale = true;
                const menu = root.querySelector('.hb-locale__menu');
                const toggle = root.querySelector('[data-hb-locale-toggle]');
                if (!menu || !toggle) return;
                toggle.addEventListener('click', (e) => {
                    e.stopPropagation();
                    const willOpen = menu.hidden;
                    closeAll();
                    if (willOpen) {
                        menu.hidden = false;
                        placeMenu(menu, toggle);
                    }
                    toggle.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
                });
            });
            if (!document.__hbLocaleOutside) {
                document.__hbLocaleOutside = true;
                document.addEventListener('click', (e) => {
                    if (!e.target.closest('[data-hb-locale]')) {
                        closeAll();
                        document.querySelectorAll('[data-hb-locale-toggle]').forEach((t) => t.setAttribute('aria-expanded', 'false'));
                    }
                });
                window.addEventListener('resize', () => {
                    document.querySelectorAll('.hb-locale__menu:not([hidden])').forEach((m) => {
                        const root = m.closest('[data-hb-locale]');
                        const t = root ? root.querySelector('[data-hb-locale-toggle]') : null;
                        if (t) placeMenu(m, t);
                    });
                });
            }
        };
        if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot, { once: true });
        else boot();
        document.addEventListener('hb:refresh', boot);
    })();
</script>
@endonce

@props([
    'documentType' => 'post',
    'postId' => null,
    'emailSizeUrlTemplate' => '',
])
@php
    $locales = config('heisenberg.editor.locales', ['en', 'fr']);
    $current = app()->getLocale();
    $localeNames = (array) __('heisenberg::editor.locales');
@endphp
<div {{ $attributes->merge(['class' => 'hb-footer']) }}>
    <div class="hb-footer__zone">
        <span class="hb-footer__pill hb-footer__pill--status" data-hb-save-status data-state="saved" role="status" aria-live="polite" aria-label="{{ __('heisenberg::editor.footer.aria_status') }}">
            <span class="hb-footer__icon" data-hb-status-icon="saved" aria-hidden="true">
                @include('heisenberg::components.ui.icon', ['name' => 'cloud-check', 'size' => 13])
            </span>
            <span class="hb-footer__icon" data-hb-status-icon="saving" aria-hidden="true">
                @include('heisenberg::components.ui.icon', ['name' => 'arrows-counter-clockwise-bold', 'size' => 13])
            </span>
            <span class="hb-footer__icon" data-hb-status-icon="dirty" aria-hidden="true">
                @include('heisenberg::components.ui.icon', ['name' => 'pencil-simple', 'size' => 13])
            </span>
            <span class="hb-footer__icon" data-hb-status-icon="offline" aria-hidden="true">
                @include('heisenberg::components.ui.icon', ['name' => 'wifi-slash', 'size' => 13])
            </span>
            <span class="hb-footer__icon" data-hb-status-icon="conflict" aria-hidden="true">
                @include('heisenberg::components.ui.icon', ['name' => 'warning-circle', 'size' => 13])
            </span>
            <span class="hb-footer__icon" data-hb-status-icon="error" aria-hidden="true">
                @include('heisenberg::components.ui.icon', ['name' => 'x-circle', 'size' => 13])
            </span>
            <span data-hb-save-status-text>{{ __('heisenberg::editor.common.saved') }}</span>
        </span>
        @if (($documentType ?? 'post') === 'email')
        <span class="hb-footer__pill hb-footer__pill--email-size" data-hb-email-size
            data-hb-post-id="{{ $postId ?? '' }}"
            data-hb-email-size-url-template="{{ $emailSizeUrlTemplate }}"
            data-hb-email-size-warning="{{ __('heisenberg::editor.footer.email_size_warning') }}"
            role="status" aria-live="polite" aria-label="{{ __('heisenberg::editor.footer.aria_email_size') }}">
            <span class="hb-footer__icon" aria-hidden="true">
                @include('heisenberg::components.ui.icon', ['name' => 'envelope-simple', 'size' => 13])
            </span>
            <span data-hb-email-size-text>{{ __('heisenberg::editor.footer.email_size_unsaved') }}</span>
        </span>
        @endif
    </div>
    <div class="hb-footer__zone">
        <div class="hb-locale" data-hb-locale>
            <button type="button" class="hb-foot-chip" data-hb="lang-toggle" aria-haspopup="menu" title="{{ __('heisenberg::editor.footer.aria_lang') }}" data-hb-locale-toggle>
                <span>{{ $localeNames[$current] ?? $current }}</span>
            </button>
            <div class="hb-locale__menu" role="menu" hidden>
                @foreach ($locales as $code)
                    <button type="button" class="hb-locale__opt {{ $code === $current ? 'is-on' : '' }}" role="menuitemradio"
                        aria-checked="{{ $code === $current ? 'true' : 'false' }}"
                        data-hb-locale-switch="{{ $code }}">
                        <span>{{ $localeNames[$code] ?? $code }}</span>
                        <span class="hb-locale__opt__check" aria-hidden="true">@include('heisenberg::components.ui.icon', ['name' => 'check', 'size' => 12])</span>
                    </button>
                @endforeach
            </div>
        </div>
        <span class="bar-sep"></span>
        <button type="button" class="hb-foot-chip" data-hb="code-editor" aria-pressed="false"
            title="{{ __('heisenberg::editor.footer.aria_code_editor') }}"
            data-label-code="{{ __('heisenberg::editor.footer.code_editor_label') }}"
            data-label-visual="{{ __('heisenberg::editor.footer.visual_editor_label') }}"
            data-title-code="{{ __('heisenberg::editor.footer.aria_code_editor') }}"
            data-title-visual="{{ __('heisenberg::editor.footer.aria_visual_editor') }}">
            <span>{{ __('heisenberg::editor.footer.code_editor_label') }}</span>
        </button>
        @foreach ($locales as $code)
            <form method="POST" action="{{ route('heisenberg.locale.switch', ['locale' => $code]) }}" data-hb-locale-form="{{ $code }}" hidden>
                @csrf
                <input type="hidden" name="return" value="{{ $localeReturn ?? url()->current() }}">
                <button type="submit" aria-label="{{ $localeNames[$code] ?? $code }}">
                    {{ $localeNames[$code] ?? $code }}
                </button>
            </form>
        @endforeach
    </div>
</div>
@once
<script>
    (() => {
        const boot = () => {
            document.querySelectorAll('[data-hb-locale-switch]').forEach((opt) => {
                if (opt.__hbLocaleSwitch) return; opt.__hbLocaleSwitch = true;
                opt.addEventListener('click', () => {
                    const code = opt.getAttribute('data-hb-locale-switch');
                    const form = document.querySelector('[data-hb-locale-form="' + (window.CSS && CSS.escape ? CSS.escape(code) : code) + '"]');
                    if (!form) return;
                    const ret = form.querySelector('input[name="return"]');
                    if (ret) ret.value = window.location.href;
                    form.submit();
                });
            });
        };
        if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot, { once: true });
        else boot();
        document.addEventListener('hb:refresh', boot);
    })();
</script>
@endonce

@once
<script>
    (() => {
        const STATE_LABELS = {
            saved: @json(__('heisenberg::editor.common.saved')),
            saving: @json(__('heisenberg::editor.footer.status_saving')),
            dirty: @json(__('heisenberg::editor.footer.status_unsaved')),
            offline: @json(__('heisenberg::editor.footer.status_offline')),
            conflict: @json(__('heisenberg::editor.footer.status_conflict')),
            error: @json(__('heisenberg::editor.footer.status_error')),
        };
        let hbOnline = navigator.onLine;
        let hbSaveState = 'saved';
        let hbSaveMessage = '';

        const render = () => {
            const state = hbOnline ? hbSaveState : 'offline';
            document.querySelectorAll('[data-hb-save-status]').forEach((pill) => {
                pill.dataset.state = state;
                const label = pill.querySelector('[data-hb-save-status-text]');
                const base = STATE_LABELS[state] || STATE_LABELS.saved;
                if (label) {
                    label.textContent = ((state === 'error' || state === 'conflict') && hbSaveMessage)
                        ? base + ' — ' + hbSaveMessage
                        : base;
                }
                pill.title = (state === 'conflict' || state === 'error') ? hbSaveMessage : '';
            });
        };

        const boot = () => { render(); };
        if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot, { once: true });
        else boot();
        document.addEventListener('hb:refresh', boot);

        if (!document.__hbSaveStatusWired) {
            document.__hbSaveStatusWired = true;
            document.addEventListener('hb:save-state', (e) => {
                hbSaveState = (e.detail && e.detail.state) || 'saved';
                hbSaveMessage = (e.detail && e.detail.message) || '';
                render();
            });
            window.addEventListener('online', () => { hbOnline = true; render(); });
            window.addEventListener('offline', () => { hbOnline = false; render(); });
        }
    })();
</script>
@endonce

@once
<script>
    (() => {
        const KB = 1024;
        const WARN_BYTES = 100 * KB;

        const chip = () => document.querySelector('[data-hb-email-size]');

        const render = (bytes) => {
            const pill = chip();
            if (!pill) return;
            const text = pill.querySelector('[data-hb-email-size-text]');
            if (bytes === null) {
                if (text) text.textContent = pill.dataset.hbUnsavedLabel || '—';
                delete pill.dataset.warn;
                pill.title = '';
                return;
            }
            const warn = bytes > WARN_BYTES;
            if (text) text.textContent = '~' + Math.round(bytes / KB) + ' KB';
            if (warn) { pill.dataset.warn = 'true'; pill.title = pill.dataset.hbEmailSizeWarning || ''; }
            else { delete pill.dataset.warn; pill.title = ''; }
        };

        const fetchSize = (postId) => {
            const pill = chip();
            const template = pill ? pill.dataset.hbEmailSizeUrlTemplate : '';
            if (!pill || !template || !postId) return;
            const locale = (window.hbEditor && window.hbEditor.getEditingLocale) ? window.hbEditor.getEditingLocale() : '';
            window.fetch(template.replace('__ID__', postId) + (locale ? '?locale=' + encodeURIComponent(locale) : ''), {
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
            })
                .then((r) => (r.ok ? r.json() : null))
                .then((data) => { if (data && typeof data.sizeBytes === 'number') render(data.sizeBytes); })
                .catch(() => { });
        };

        const boot = () => {
            const pill = chip();
            if (!pill || pill.__hbEmailSize) return;
            pill.__hbEmailSize = true;
            pill.dataset.hbUnsavedLabel = pill.querySelector('[data-hb-email-size-text]')?.textContent || '—';
            const postId = pill.dataset.hbPostId;
            if (postId) fetchSize(postId);
        };
        if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot, { once: true });
        else boot();
        document.addEventListener('hb:refresh', boot);

        if (!document.__hbEmailSizeWired) {
            document.__hbEmailSizeWired = true;
            document.addEventListener('hb:post-saved', (e) => {
                const post = e.detail && e.detail.post;
                if (post && post.id != null) fetchSize(post.id);
            });
            document.addEventListener('hb:editing-locale-change', () => {
                const pill = chip();
                const postId = pill ? pill.dataset.hbPostId : '';
                if (postId) fetchSize(postId);
            });
        }
    })();
</script>
@endonce