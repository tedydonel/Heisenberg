{{-- live/footer — 32px bar with a 1px top border. Two zones pushed to opposite ends:
     a connection status pill (left) and language + code-editor pills (right).

     The connection-status pill now reflects real state — Saved / Saving… / Unsaved changes /
     Offline / Conflict / Error — via [data-state] on the pill itself. Connectivity comes from
     navigator.onLine + the window online/offline events; save state comes from the
     `hb:save-state` events live/topbar.blade.php's save/autosave wiring dispatches on `document`
     (detail: {state, message?}). This file only renders and reflects; it never calls fetch()
     itself. All six state icons are pre-rendered (see the icon spans below) and toggled purely
     via the CSS below, keyed off the pill's own [data-state] attribute.

     The right zone's language pill is also the locale switcher: a native <form method="POST"> to
     `heisenberg.locale.switch`, whose validated `return` field bounces back to the current page.
     Hosts that want a richer UI replace this surface; the form action is the only contract.
     The drop-up menu lists every locale shipped with the package (`heisenberg::editor.locales`),
     and the current locale is read from `app()->getLocale()` (which EditorLocaleMiddleware set
     before this view rendered). --}}
@once
<style>
    .hb-footer {
        display: flex;
        align-items: center;
        justify-content: space-between;
        width: 100%;
        height: 32px;
        background: var(--hb-bg, #fff);
        border-top: 1px solid var(--hb-border, #E4E4E4);
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
    .hb-footer__pill--muted { background: var(--hb-bg-inset, #EEEEEE); color: var(--hb-text-secondary, #5A5A5A); }
    .hb-footer__pill--muted .hb-footer__icon { color: var(--hb-text-muted, #9A9A9A); }
    .hb-footer__icon { display: inline-flex; width: 13px; height: 13px; flex: none; }

    /* Save-status pill states — driven by [data-state] on .hb-footer__pill--status itself (set
       by the script at the bottom of this file). All six icon spans are always in the markup;
       only the one matching the current state is shown. Saved is the only success-tinted state —
       Saving/Unsaved are neutral (nothing is actually wrong), Offline/Conflict/Error are the only
       danger-tinted ones. Previously this pill was hard-locked to the danger colour permanently.
       2026-08-04: the coloured FILL moved off the pill's own background (now always transparent,
       matching the flat/no-fill treatment the right-zone chips already use — .hb-foot-chip above)
       onto just the icon+text colour, which already carried the state tint on its own. */
    .hb-footer__pill--status .hb-footer__icon { display: none; }
    .hb-footer__pill--status[data-state="saved"] .hb-footer__icon[data-hb-status-icon="saved"],
    .hb-footer__pill--status[data-state="saving"] .hb-footer__icon[data-hb-status-icon="saving"],
    .hb-footer__pill--status[data-state="dirty"] .hb-footer__icon[data-hb-status-icon="dirty"],
    .hb-footer__pill--status[data-state="offline"] .hb-footer__icon[data-hb-status-icon="offline"],
    .hb-footer__pill--status[data-state="conflict"] .hb-footer__icon[data-hb-status-icon="conflict"],
    .hb-footer__pill--status[data-state="error"] .hb-footer__icon[data-hb-status-icon="error"] { display: inline-flex; }
    .hb-footer__pill--status { background: transparent; }
    .hb-footer__pill--status[data-state="saved"] { color: var(--hb-success, #3BD186); }
    .hb-footer__pill--status[data-state="saved"] .hb-footer__icon { color: var(--hb-success, #3BD186); }
    .hb-footer__pill--status[data-state="saving"],
    .hb-footer__pill--status[data-state="dirty"] { color: var(--hb-text-secondary, #5A5A5A); }
    .hb-footer__pill--status[data-state="saving"] .hb-footer__icon,
    .hb-footer__pill--status[data-state="dirty"] .hb-footer__icon { color: var(--hb-text-muted, #9A9A9A); }
    .hb-footer__pill--status[data-state="offline"],
    .hb-footer__pill--status[data-state="conflict"],
    .hb-footer__pill--status[data-state="error"] { color: var(--hb-danger, #D4191A); }
    .hb-footer__pill--status[data-state="offline"] .hb-footer__icon,
    .hb-footer__pill--status[data-state="conflict"] .hb-footer__icon,
    .hb-footer__pill--status[data-state="error"] .hb-footer__icon { color: var(--hb-danger, #D4191A); }
    .hb-footer__pill--status[data-state="saving"] .hb-footer__icon[data-hb-status-icon="saving"] { animation: hb-status-spin 1s linear infinite; }
    @keyframes hb-status-spin { to { transform: rotate(360deg); } }

    /* Email size chip (docs/email-system.md §7-E3) — muted "—" before the first save (no
       postId yet, nothing rendered to measure), then "~123 KB" once GET .../email-size answers.
       Amber past the 100KB warn threshold (Gmail clipping), same transparent-background/tinted-
       text treatment the status pill above uses for its own danger states. */
    .hb-footer__pill--email-size { color: var(--hb-text-secondary, #5A5A5A); }
    .hb-footer__pill--email-size .hb-footer__icon { color: var(--hb-text-muted, #9A9A9A); }
    .hb-footer__pill--email-size[data-warn="true"] { color: var(--hb-warning, #B45309); }
    .hb-footer__pill--email-size[data-warn="true"] .hb-footer__icon { color: var(--hb-warning, #B45309); }

    /* Flat right-zone buttons (language switcher + code editor) — plain text labels, no pill
       background, no border. The hidden locale POST forms never paint. */
    .hb-foot-chip {
        display: inline-flex; align-items: center;
        height: 100%; padding: 0 var(--hb-space-2, 8px);
        border: 0; background: transparent; border-radius: 0;
        font-family: var(--hb-font-sans, Rubik, sans-serif); font-size: 12px;
        color: var(--hb-text-secondary, #5A5A5A); cursor: pointer;
    }
    .hb-foot-chip:hover { color: var(--hb-text-primary, #0A0A0A); }
    .hb-foot-chip[aria-pressed="true"] { color: var(--hb-accent, #000); font-weight: 600; }

    /* Locale switcher — drop-up menu anchored to the language pill. The menu itself escapes the
       footer's overflow:hidden via `position: fixed`; the JS computes its real coordinates from
       the toggle button's getBoundingClientRect() on open (see the script at the bottom of the
       file). The hb-locale wrapper itself is a plain inline-flex — it doesn't need to be a
       positioning context because the menu doesn't position relative to it. */
    .hb-locale { display: inline-flex; }
    .hb-locale__menu {
        position: fixed; z-index: 60;
        min-width: 140px; padding: 4px;
        background: var(--hb-bg, #fff); border: 1px solid var(--hb-border, #E4E4E4);
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
        color: var(--hb-text-secondary, #5A5A5A); text-align: left; cursor: pointer;
        white-space: nowrap; width: 100%;
    }
    .hb-locale__opt:hover { background: var(--hb-surface-hover, #F7F7F7); color: var(--hb-text-primary, #0A0A0A); }
    .hb-locale__opt.is-on { background: var(--hb-surface-hover, #F7F7F7); color: var(--hb-text-primary, #0A0A0A); font-weight: 500; }
    .hb-locale__opt__check { width: 12px; height: 12px; flex: none; color: var(--hb-accent, #000); display: none; }
    .hb-locale__opt.is-on .hb-locale__opt__check { display: inline-flex; }
</style>
<script>
    (() => {
        const placeMenu = (menu, toggle) => {
            if (!menu || !toggle) return;
            const r = toggle.getBoundingClientRect();
            // Anchor right edge of the menu to right edge of the toggle, sit it 5px above.
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
                // Re-position on scroll/resize so the fixed-position menu stays anchored to its toggle.
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
    // docs/email-system.md §7-E3 — the size chip only renders for an email document.
    'documentType' => 'post',
    'postId' => null,
    'emailSizeUrlTemplate' => '',
])
@php
    // The locales we offer, in display order. Driven off `config('heisenberg.editor.locales')`
    // so a host can override the shipped `['en', 'fr']` by setting the config — adding a third
    // locale there, in LocaleController::LOCALES and EditorLocaleMiddleware::LOCALES, is
    // the coordinated change to expose it here. The hard-coded fallback matches the package
    // default and protects against a host that wipes the config in a test harness.
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
        {{-- Email size chip (docs/email-system.md §7-E3) — only for an email document; the
             script below fetches emailSizeUrlTemplate after every hb:post-saved and, if a post
             already exists at boot (an existing saved email reopened), immediately. Muted "—"
             is the pre-first-save state — nothing has been rendered/measured yet. --}}
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
        {{-- Locale switcher + code editor — plain text buttons, no pill background, no border. --}}
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
        {{-- Toggles the Code view (live/code-editor.blade.php owns the listener + state);
             the label always names the surface the click takes you TO. --}}
        <button type="button" class="hb-foot-chip" data-hb="code-editor" aria-pressed="false"
            title="{{ __('heisenberg::editor.footer.aria_code_editor') }}"
            data-label-code="{{ __('heisenberg::editor.footer.code_editor_label') }}"
            data-label-visual="{{ __('heisenberg::editor.footer.visual_editor_label') }}"
            data-title-code="{{ __('heisenberg::editor.footer.aria_code_editor') }}"
            data-title-visual="{{ __('heisenberg::editor.footer.aria_visual_editor') }}">
            <span>{{ __('heisenberg::editor.footer.code_editor_label') }}</span>
        </button>
        {{-- Hidden POST forms — one per locale — submitted on click of the matching menu option.
             The `return` input (set to the current URL right before submit) is what
             LocaleController::safeReturnUrl() bounces back to. --}}
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
                    // Persist the current URL as the switcher's return target so the
                    // controller can bounce back to the same page after App::setLocale().
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

{{-- Save-status pill — presentation only. Two independent signal sources, combined here:
     connectivity from navigator.onLine + the window online/offline events (this file's own
     concern), and save lifecycle from the `hb:save-state` events live/topbar.blade.php's save/
     autosave wiring dispatches on `document` (this file never calls fetch() itself). Offline
     always wins the display regardless of the last known save state. A brand-new document and a
     freshly loaded existing one both start "saved" (nothing to lose yet / nothing changed yet);
     the first hb:blocks-changed or title edit flips it to "Unsaved changes". --}}
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
                // Show WHY it failed inline, not only in a tooltip. A bare "Error" is
                // undiagnosable — you can't tell a validation problem from an unmigrated
                // database from a session timeout, and neither can anyone you report it to.
                // The message carries the HTTP status and the server's own text.
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

{{-- Email size chip (docs/email-system.md §7-E3) — fetches GET .../email-size (EmailPreviewController::size())
     after every save (hb:post-saved, the same event inspector.blade.php's Summary rows already
     listen for) and, for an existing saved email reopened in the editor, once at boot. Renders the
     REAL sizeBytes EmailRenderer measured (html + every embedded attachment) — the same number a
     host's mailer would actually send — never a client-side estimate. --}}
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
            window.fetch(template.replace('__ID__', postId), {
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
            })
                .then((r) => (r.ok ? r.json() : null))
                .then((data) => { if (data && typeof data.sizeBytes === 'number') render(data.sizeBytes); })
                .catch(() => { /* the chip just keeps its last known value */ });
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
        }
    })();
</script>
@endonce