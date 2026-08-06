{{-- live/media/media-dialog —
     one 900x640 fixed-size modal (2026-08-04: height was previously unset, so the dialog visibly
     resized itself switching Upload -> Library or as the library grid's item count changed —
     .hb-mediadialog__body fills the fixed frame and scrolls internally instead, same as before),
     shared top strip (title + Upload/Library tabs + close), both tab bodies always
     render and JS toggles which is visible (was: server picks one via `tab` and only that renders —
     that only worked for the static showcase page, not a real dialog you switch tabs inside).
     Reuses ui/tabs (DUmaG) and the media sub-views. `scrim` renders the overlay (off by default so the
     panel is previewable inline, as on editor/components.blade.php).

     There is no Alpine/Livewire on the /editor page (see live/topbar.blade.php's script for the house
     vanilla-JS idiom), so this dialog is wired with plain JS + fetch instead of the Livewire component
     the file header used to say this would grow into. Every `:scrim="true"` instance is a real,
     self-contained accessible modal: close button, Escape-to-close, a Tab focus trap, and focus
     returned to whatever opened it — implemented once here so every caller gets it for free via the
     `hbOpen(returnFocusEl)` / `hbClose()` methods exposed on the scrim element. `select-url` /
     `upload-url` (route('media.select') / route('media.upload'), see routes/media.php) wire the
     Library grid and Upload dropzone to the real MediaLibraryController; a caller that omits them (the
     showcase page never sets them) keeps the original static/presentational behavior untouched. --}}
@once
<style>
    .hb-mediadialog {
        display: flex; flex-direction: column; overflow: hidden;
        width: 900px; height: 640px; max-width: 100%; max-height: calc(100vh - 48px);
        background: var(--hb-bg, #fff);
        border: 1px solid var(--hb-border, #E4E4E4);
        border-radius: var(--hb-radius-lg, 8px);
        box-shadow: 0 24px 64px rgba(0, 0, 0, .16);
        font-family: var(--hb-font-sans, Rubik, sans-serif);
    }
    .hb-mediadialog:focus { outline: none; }
    .hb-mediadialog__top {
        display: flex; align-items: center; justify-content: space-between;
        height: 32px; padding-left: var(--hb-space-4, 16px);
        background: var(--hb-bg-muted, #F4F4F4); flex: none;
    }
    .hb-mediadialog__title { font-size: var(--hb-fs-sm, 12px); color: var(--hb-accent, #000); white-space: nowrap; flex: none; }
    .hb-mediadialog__tabs { flex: none; width: 200px; }
    .hb-mediadialog__close {
        display: inline-flex; align-items: center; justify-content: center;
        width: 30px; height: 32px; border: 0; background: none; cursor: pointer;
        color: var(--hb-text-secondary, #5A5A5A); border-radius: var(--hb-radius-lg, 8px); flex: none;
    }
    .hb-mediadialog__close:hover { background: var(--hb-surface-hover, #F7F7F7); color: var(--hb-text-primary, #0A0A0A); }
    .hb-mediadialog__err { flex: none; padding: 10px var(--hb-space-4, 16px) 0; font-size: var(--hb-fs-sm, 12px); color: var(--hb-danger, #D4191A); text-align: center; }
    .hb-mediadialog__err[hidden] { display: none; }
    .hb-mediadialog__body { flex: 1 1 auto; min-height: 0; overflow: auto; }
    .hb-mediadialog__body--upload { display: flex; align-items: center; justify-content: center; padding: var(--hb-space-4, 16px); min-height: 420px; }
    .hb-mediadialog__body--library { padding: var(--hb-space-6, 24px); }
    .hb-mediadialog__body[hidden] { display: none; }
    .hb-mediadialog__scrim {
        position: fixed; inset: 0; z-index: 120; display: flex; align-items: center; justify-content: center;
        padding: 24px; background: rgba(0, 0, 0, .4);
    }
    .hb-mediadialog__scrim[hidden] { display: none; }
</style>
@endonce
@once('hb-mediadialog-core')
<script>
    (() => {
        // The single currently-open scrimmed dialog (there is only ever one modal open at a
        // time in this editor) — drives the document-level Escape/focus-trap listeners below.
        let hbOpenMediaScrim = null;

        const focusablesIn = (container) => Array.from(container.querySelectorAll(
            'button:not([disabled]), [href], input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'
        )).filter((el) => el.offsetParent !== null);

        const openDialog = (scrim, returnFocusEl) => {
            scrim.hidden = false;
            scrim.__hbReturnFocus = returnFocusEl || document.activeElement;
            hbOpenMediaScrim = scrim;
            const dialog = scrim.querySelector('.hb-mediadialog');
            const lib = scrim.querySelector('[data-hb-medialib]');
            if (lib && typeof lib.hbRefresh === 'function') lib.hbRefresh('');
            dialog?.focus();
        };

        const closeDialog = (scrim) => {
            if (!scrim || scrim.hidden) return;
            scrim.hidden = true;
            if (hbOpenMediaScrim === scrim) hbOpenMediaScrim = null;
            const back = scrim.__hbReturnFocus;
            scrim.__hbReturnFocus = null;
            if (back && document.contains(back)) back.focus();
        };

        const csrfToken = () => {
            const meta = document.querySelector('meta[name="csrf-token"]');
            return meta ? meta.getAttribute('content') : '';
        };

        const wireUpload = (dialog) => {
            const uploadUrl = dialog.dataset.uploadUrl || '';
            const zone = dialog.querySelector('.hb-mediadialog__body--upload .hb-dropzone');
            // No upload endpoint wired for this instance (e.g. the bare showcase-page demo) —
            // leave the dropzone exactly as inert as it already was.
            if (!zone || !uploadUrl || zone.__hbUpload) return;
            zone.__hbUpload = true;

            const errEl = dialog.querySelector('[data-hb-mediadialog-err]');
            const showError = (msg) => { if (errEl) { errEl.textContent = msg || ''; errEl.hidden = !msg; } };

            const input = document.createElement('input');
            input.type = 'file';
            input.multiple = true;
            input.style.display = 'none';
            if (dialog.dataset.accept) input.accept = dialog.dataset.accept;
            zone.insertAdjacentElement('afterend', input);

            const goToLibrary = () => {
                const tablist = dialog.querySelector('[data-hb-tablist]');
                const libTab = tablist?.querySelector('[data-hb-tab="library"]');
                if (tablist?.__hbTablist && libTab) tablist.__hbTablist.activate(libTab, false);
                dialog.querySelector('[data-hb-medialib]')?.hbRefresh?.('');
            };

            const upload = (files) => {
                const list = Array.from(files || []);
                if (!list.length) return;
                showError('');
                const form = new FormData();
                list.forEach((f) => form.append('files[]', f));
                window.fetch(uploadUrl, {
                    method: 'POST',
                    headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken(), 'X-Requested-With': 'XMLHttpRequest' },
                    credentials: 'same-origin',
                    body: form,
                })
                    .then((r) => r.json().catch(() => ({})).then((data) => ({ ok: r.ok, data })))
                    .then(({ ok, data }) => {
                        if (!ok) {
                            const firstError = data && data.errors ? Object.values(data.errors)[0] : null;
                            showError((Array.isArray(firstError) ? firstError[0] : firstError) || data.message || dialog.dataset.msgUploadFailed || '');
                            return;
                        }
                        goToLibrary();
                    })
                    .catch(() => showError(dialog.dataset.msgUploadNetwork || ''));
            };

            zone.addEventListener('click', () => input.click());
            zone.addEventListener('keydown', (e) => {
                if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); input.click(); }
            });
            zone.addEventListener('dragover', (e) => { e.preventDefault(); zone.classList.add('hb-dropzone--drag'); });
            zone.addEventListener('dragleave', () => zone.classList.remove('hb-dropzone--drag'));
            zone.addEventListener('drop', (e) => {
                e.preventDefault();
                zone.classList.remove('hb-dropzone--drag');
                if (e.dataTransfer) upload(e.dataTransfer.files);
            });
            input.addEventListener('change', () => { upload(input.files); input.value = ''; });
        };

        const wireDialog = (dialog) => {
            if (dialog.__hbMediaDialog) return;
            dialog.__hbMediaDialog = true;

            const tablist = dialog.querySelector('[data-hb-tablist]');
            const bodies = Array.from(dialog.querySelectorAll('[data-hb-tab-body]'));
            tablist?.addEventListener('change', (event) => {
                bodies.forEach((body) => { body.hidden = body.dataset.hbTabBody !== event.detail.value; });
            });

            dialog.querySelector('.hb-mediadialog__close')?.addEventListener('click', () => {
                const scrim = dialog.closest('.hb-mediadialog__scrim');
                if (scrim) closeDialog(scrim);
            });

            // A pick bubbles up from live/media/media-library's grid — close the modal and
            // re-announce it as `hb:media-select` so whatever opened this dialog (e.g. the
            // Post-tab featured-image field) can react without knowing about the grid at all.
            dialog.addEventListener('hb:media-pick', (event) => {
                const scrim = dialog.closest('.hb-mediadialog__scrim');
                if (scrim) closeDialog(scrim);
                dialog.dispatchEvent(new CustomEvent('hb:media-select', { bubbles: true, detail: event.detail }));
            });

            wireUpload(dialog);
        };

        const wireScrim = (scrim) => {
            if (scrim.__hbMediaScrim) return;
            scrim.__hbMediaScrim = true;
            scrim.hbOpen = (returnFocusEl) => openDialog(scrim, returnFocusEl);
            scrim.hbClose = () => closeDialog(scrim);
            // Click on the backdrop itself (not the dialog card) closes, same as the Close button.
            scrim.addEventListener('mousedown', (e) => { if (e.target === scrim) closeDialog(scrim); });
        };

        const boot = () => {
            document.querySelectorAll('[data-hb-mediadialog]').forEach(wireDialog);
            document.querySelectorAll('.hb-mediadialog__scrim').forEach(wireScrim);
        };
        if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot, { once: true });
        else boot();
        document.addEventListener('hb:refresh', boot);

        // Escape-to-close and the Tab focus trap are both scoped to "is a scrimmed dialog
        // currently open" — a single document-level listener, guarded so re-running boot()
        // (hb:refresh) never attaches it twice.
        if (!document.__hbMediaDialogKeys) {
            document.__hbMediaDialogKeys = true;
            document.addEventListener('keydown', (event) => {
                if (!hbOpenMediaScrim) return;
                if (event.key === 'Escape') { event.preventDefault(); closeDialog(hbOpenMediaScrim); return; }
                if (event.key !== 'Tab') return;
                const dialog = hbOpenMediaScrim.querySelector('.hb-mediadialog');
                if (!dialog) return;
                const items = focusablesIn(dialog);
                if (!items.length) { event.preventDefault(); dialog.focus(); return; }
                const first = items[0];
                const last = items[items.length - 1];
                const current = document.activeElement;
                // `current === dialog` covers the very first Tab press right after hbOpen()
                // calls dialog.focus() — the dialog itself (tabindex="-1") is its own
                // `.contains()` match but isn't in `items`, so without this it would fall
                // through to native Tab handling and escape the trap on the first keystroke.
                if (!dialog.contains(current)) { event.preventDefault(); first.focus(); }
                else if (event.shiftKey && (current === first || current === dialog)) { event.preventDefault(); last.focus(); }
                else if (!event.shiftKey && (current === last || current === dialog)) { event.preventDefault(); first.focus(); }
            });
        }
    })();
</script>
@endonce

@props([
    'tab' => 'upload',
    'title' => null,
    'items' => [],
    'scrim' => false,
    'accept' => '',
    'selectUrl' => null,
    'uploadUrl' => null,
])
@php
    $title ??= __('heisenberg::editor.media.select_featured_image');
    $tabs = [
        ['value' => 'upload', 'label' => __('heisenberg::editor.media.tab_upload')],
        ['value' => 'library', 'label' => __('heisenberg::editor.media.tab_library')],
    ];
    $activeIndex = $tab === 'library' ? 1 : 0;
@endphp
@if ($scrim)
    {{-- Visibility is entirely caller-controlled: pass `hidden` on the component tag (as
         inspector.blade.php's featured-image field does) to start closed, then call
         `hbOpen()`/`hbClose()` (exposed on this element by the script above) to toggle it. --}}
    <div {{ $attributes->merge(['class' => 'hb-mediadialog__scrim']) }}>
        <div class="hb-mediadialog" role="dialog" aria-modal="true" aria-label="{{ $title }}" tabindex="-1"
            data-hb-mediadialog data-upload-url="{{ $uploadUrl }}" data-accept="{{ $accept }}"
            data-msg-upload-failed="{{ __('heisenberg::editor.media.upload_failed') }}"
            data-msg-upload-network="{{ __('heisenberg::editor.media.upload_network') }}">
            <div class="hb-mediadialog__top">
                <span class="hb-mediadialog__title">{{ $title }}</span>
                <span class="hb-mediadialog__tabs"><x-ui.tabs :items="$tabs" :active-index="$activeIndex" /></span>
                <button type="button" class="hb-mediadialog__close" aria-label="{{ __('heisenberg::editor.common.close') }}">
                    @include('heisenberg::components.ui.icon', ['name' => 'x', 'size' => 16])
                </button>
            </div>
            <div class="hb-mediadialog__err" data-hb-mediadialog-err hidden></div>
            <div class="hb-mediadialog__body hb-mediadialog__body--upload" data-hb-tab-body="upload" @if ($tab !== 'upload') hidden @endif>
                <x-live.media.upload-dropzone />
            </div>
            <div class="hb-mediadialog__body hb-mediadialog__body--library" data-hb-tab-body="library" @if ($tab !== 'library') hidden @endif>
                <x-live.media.media-library :items="$items" :select-url="$selectUrl" />
            </div>
        </div>
    </div>
@else
    <div {{ $attributes->merge(['class' => 'hb-mediadialog']) }} role="dialog" aria-modal="true" aria-label="{{ $title }}" tabindex="-1"
        data-hb-mediadialog data-upload-url="{{ $uploadUrl }}" data-accept="{{ $accept }}"
        data-msg-upload-failed="{{ __('heisenberg::editor.media.upload_failed') }}"
        data-msg-upload-network="{{ __('heisenberg::editor.media.upload_network') }}">
        <div class="hb-mediadialog__top">
            <span class="hb-mediadialog__title">{{ $title }}</span>
            <span class="hb-mediadialog__tabs"><x-ui.tabs :items="$tabs" :active-index="$activeIndex" /></span>
            <button type="button" class="hb-mediadialog__close" aria-label="{{ __('heisenberg::editor.common.close') }}">
                @include('heisenberg::components.ui.icon', ['name' => 'x', 'size' => 16])
            </button>
        </div>
        <div class="hb-mediadialog__err" data-hb-mediadialog-err hidden></div>
        <div class="hb-mediadialog__body hb-mediadialog__body--upload" data-hb-tab-body="upload" @if ($tab !== 'upload') hidden @endif>
            <x-live.media.upload-dropzone />
        </div>
        <div class="hb-mediadialog__body hb-mediadialog__body--library" data-hb-tab-body="library" @if ($tab !== 'library') hidden @endif>
            <x-live.media.media-library :items="$items" :select-url="$selectUrl" />
        </div>
    </div>
@endif
