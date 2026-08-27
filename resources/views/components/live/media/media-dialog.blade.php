@once
<style nonce="{{ heisenberg_csp_nonce() }}">
    .hb-mediadialog {
        display: flex; flex-direction: column; overflow: hidden;
        width: 900px; height: 640px; max-width: 100%; max-height: calc(100vh - 48px);
        background: var(--hb-bg);
        border: 1px solid var(--hb-border);
        border-radius: var(--hb-radius-lg, 8px);
        box-shadow: 3px 4px 4px rgba(0, 0, 0, .1);
        font-family: var(--hb-font-sans, Rubik, sans-serif);
    }
    .hb-mediadialog:focus { outline: none; }
    .hb-mediadialog__top {
        display: flex; align-items: center; justify-content: space-between;
        height: 32px; padding-left: var(--hb-space-4, 16px);
        background: var(--hb-bg-muted); flex: none;
    }
    .hb-mediadialog__title { font-size: var(--hb-fs-sm, 12px); color: var(--hb-accent); white-space: nowrap; flex: none; }
    .hb-mediadialog__tabs { flex: none; width: 200px; }
    .hb-mediadialog__close {
        display: inline-flex; align-items: center; justify-content: center;
        width: 30px; height: 32px; border: 0; background: none; cursor: pointer;
        color: var(--hb-text-secondary); border-radius: var(--hb-radius-lg, 8px); flex: none;
    }
    .hb-mediadialog__close:hover { background: var(--hb-surface-hover); color: var(--hb-text-primary); }
    .hb-mediadialog__err { flex: none; padding: 10px var(--hb-space-4, 16px) 0; font-size: var(--hb-fs-sm, 12px); color: var(--hb-danger); text-align: center; }
    .hb-mediadialog__err[hidden] { display: none; }
    .hb-mediadialog__body { flex: 1 1 auto; min-height: 0; overflow: hidden; position: relative; }
    .hb-mediadialog__body--upload { display: flex; align-items: center; justify-content: center; padding: var(--hb-space-4, 16px); min-height: 420px; }
    .hb-mediadialog__body--library { padding: var(--hb-space-6, 24px); }
    .hb-mediadialog__body[hidden] { display: none; }
    .hb-mediadialog__scroll { box-sizing: border-box; height: 100%; }
    .hb-mediadialog__scrim {
        position: fixed; inset: 0; z-index: 120; display: flex; align-items: center; justify-content: center;
        padding: 24px; background: rgba(0, 0, 0, .4);
    }
    .hb-mediadialog__scrim[hidden] { display: none; }
</style>
@endonce
@once('hb-mediadialog-core')
<script nonce="{{ heisenberg_csp_nonce() }}">
    (() => {
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

            const lib = () => dialog.querySelector('[data-hb-medialib]');
            const goToLibrary = () => {
                const tablist = dialog.querySelector('[data-hb-tablist]');
                const libTab = tablist?.querySelector('[data-hb-tab="library"]');
                if (tablist?.__hbTablist && libTab) tablist.__hbTablist.activate(libTab, false);
            };

            const maxBytes = parseInt(dialog.dataset.maxBytes || '0', 10) || 0;
            const uploadOne = (file) => {
                const root = lib();
                const card = root && root.hbUploadCard ? root.hbUploadCard(file.name) : null;
                if (!card) { showError(dialog.dataset.msgUploadFailed || ''); return; }

                if (maxBytes > 0 && file.size > maxBytes) {
                    card.setProgress(0);
                    card.fail((dialog.dataset.msgTooLarge || '').replace(':max', dialog.dataset.maxHuman || ''), null);
                    return;
                }

                const form = new FormData();
                form.append('file', file);
                const xhr = new XMLHttpRequest();
                xhr.open('POST', uploadUrl, true);
                xhr.setRequestHeader('Accept', 'application/json');
                xhr.setRequestHeader('X-CSRF-TOKEN', csrfToken());
                xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
                xhr.withCredentials = true;
                xhr.upload.addEventListener('progress', (e) => {
                    if (e.lengthComputable) card.setProgress((e.loaded / e.total) * 100);
                });
                const startedAt = Date.now();
                const MIN_VISIBLE_MS = 650;
                xhr.addEventListener('load', () => {
                    let data = {};
                    try { data = JSON.parse(xhr.responseText || '{}'); } catch (e) { }
                    if (xhr.status >= 200 && xhr.status < 300 && data.files && data.files[0]) {
                        card.setProgress(100);
                        const rest = Math.max(0, MIN_VISIBLE_MS - (Date.now() - startedAt));
                        window.setTimeout(() => card.succeed(data.files[0]), rest);
                        return;
                    }
                    const firstError = data && data.errors ? Object.values(data.errors)[0] : null;
                    const message = (Array.isArray(firstError) ? firstError[0] : firstError) || data.message || dialog.dataset.msgUploadFailed || '';
                    card.fail(message, () => uploadOne(file));
                });
                xhr.addEventListener('error', () => card.fail(dialog.dataset.msgUploadNetwork || '', () => uploadOne(file)));
                xhr.send(form);
            };

            const upload = (files) => {
                const list = Array.from(files || []);
                if (!list.length) return;
                showError('');
                goToLibrary();
                const root = lib();
                const start = () => list.forEach(uploadOne);
                if (root && root.hbRefresh) Promise.resolve(root.hbRefresh('')).then(start, start);
                else start();
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
            scrim.addEventListener('mousedown', (e) => { if (e.target === scrim) closeDialog(scrim); });
        };

        const boot = () => {
            document.querySelectorAll('[data-hb-mediadialog]').forEach(wireDialog);
            document.querySelectorAll('.hb-mediadialog__scrim').forEach(wireScrim);
        };
        if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot, { once: true });
        else boot();
        document.addEventListener('hb:refresh', boot);

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

    $hbIniBytes = static function (string $v): ?int {
        $v = trim($v);
        if ($v === '' || $v === '0' || $v === '-1') { return null; }
        $unit = strtolower(substr($v, -1));
        $n = (float) $v;
        return (int) match ($unit) {
            'g' => $n * 1024 ** 3,
            'm' => $n * 1024 ** 2,
            'k' => $n * 1024,
            default => $n,
        };
    };
    $hbLimits = array_filter([
        ((int) config('heisenberg.media.max_kb', \Heisenberg\Models\PublicFile::MAX_KB)) * 1024,
        $hbIniBytes((string) ini_get('upload_max_filesize')),
        $hbIniBytes((string) ini_get('post_max_size')),
    ]);
    $hbMaxBytes = $hbLimits === [] ? 0 : min($hbLimits);
    $hbMaxHuman = $hbMaxBytes >= 1024 ** 2
        ? round($hbMaxBytes / 1024 ** 2, $hbMaxBytes % (1024 ** 2) === 0 ? 0 : 1) . ' MB'
        : round($hbMaxBytes / 1024) . ' KB';
@endphp
@if ($scrim)
    <div {{ $attributes->merge(['class' => 'hb-mediadialog__scrim']) }}>
        <div class="hb-mediadialog" role="dialog" aria-modal="true" aria-label="{{ $title }}" tabindex="-1"
            data-hb-mediadialog data-upload-url="{{ $uploadUrl }}" data-accept="{{ $accept }}"
            data-max-bytes="{{ $hbMaxBytes }}" data-max-human="{{ $hbMaxHuman }}"
            data-msg-too-large="{{ __('heisenberg::editor.media.upload_too_large') }}"
            data-msg-upload-failed="{{ __('heisenberg::editor.media.upload_failed') }}"
            data-msg-upload-network="{{ __('heisenberg::editor.media.upload_network') }}">
            <div class="hb-mediadialog__top">
                <span class="hb-mediadialog__title">{{ $title }}</span>
                <span class="hb-mediadialog__tabs"><x-heisenberg::ui.tabs :items="$tabs" :active-index="$activeIndex" /></span>
                <button type="button" class="hb-mediadialog__close" aria-label="{{ __('heisenberg::editor.common.close') }}">
                    @include('heisenberg::components.ui.icon', ['name' => 'x', 'size' => 16])
                </button>
            </div>
            <div class="hb-mediadialog__err" data-hb-mediadialog-err hidden></div>
            <div class="hb-mediadialog__body hb-mediadialog__body--upload" data-hb-tab-body="upload" @if ($tab !== 'upload') hidden @endif>
                <x-heisenberg::live.media.upload-dropzone />
            </div>
            <div class="hb-mediadialog__body hb-mediadialog__body--library" data-hb-tab-body="library" @if ($tab !== 'library') hidden @endif>
                <div class="hb-mediadialog__scroll" data-hb-mediadialog-scroll>
                    <x-heisenberg::live.media.media-library :items="$items" :select-url="$selectUrl" />
                </div>
                <x-heisenberg::ui.custom-scrollbar container="[data-hb-mediadialog-scroll]" />
            </div>
        </div>
    </div>
@else
    <div {{ $attributes->merge(['class' => 'hb-mediadialog']) }} role="dialog" aria-modal="true" aria-label="{{ $title }}" tabindex="-1"
        data-hb-mediadialog data-upload-url="{{ $uploadUrl }}" data-accept="{{ $accept }}"
        data-max-bytes="{{ $hbMaxBytes }}" data-max-human="{{ $hbMaxHuman }}"
        data-msg-too-large="{{ __('heisenberg::editor.media.upload_too_large') }}"
        data-msg-upload-failed="{{ __('heisenberg::editor.media.upload_failed') }}"
        data-msg-upload-network="{{ __('heisenberg::editor.media.upload_network') }}">
        <div class="hb-mediadialog__top">
            <span class="hb-mediadialog__title">{{ $title }}</span>
            <span class="hb-mediadialog__tabs"><x-heisenberg::ui.tabs :items="$tabs" :active-index="$activeIndex" /></span>
            <button type="button" class="hb-mediadialog__close" aria-label="{{ __('heisenberg::editor.common.close') }}">
                @include('heisenberg::components.ui.icon', ['name' => 'x', 'size' => 16])
            </button>
        </div>
        <div class="hb-mediadialog__err" data-hb-mediadialog-err hidden></div>
        <div class="hb-mediadialog__body hb-mediadialog__body--upload" data-hb-tab-body="upload" @if ($tab !== 'upload') hidden @endif>
            <x-heisenberg::live.media.upload-dropzone />
        </div>
        <div class="hb-mediadialog__body hb-mediadialog__body--library" data-hb-tab-body="library" @if ($tab !== 'library') hidden @endif>
            <div class="hb-mediadialog__scroll" data-hb-mediadialog-scroll>
                <x-heisenberg::live.media.media-library :items="$items" :select-url="$selectUrl" />
            </div>
            <x-heisenberg::ui.custom-scrollbar container="[data-hb-mediadialog-scroll]" />
        </div>
    </div>
@endif
