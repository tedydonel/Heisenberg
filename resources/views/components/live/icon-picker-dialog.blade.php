{{-- live/icon-picker-dialog — the icon block's library picker, in the media-dialog's modal
     shell (same scrim classes on purpose: live/media/media-dialog's @once script wires every
     `.hb-mediadialog__scrim` with hbOpen()/hbClose(), Escape, backdrop-close and the focus
     trap, so this dialog inherits all of that for free — same idiom as live/revisions-dialog).

     Opened by the runtime's cancelable hb:pick-icon (an empty icon block's placeholder —
     decorateIconBlock in block-runtime — or the inspector's icon-preview field this file
     decorates onto the Content tab). A pick writes the "<set>/<slug>" reference through
     hbEditor.setAttribute, the one write path every other control uses. Fed by GET
     /editor/icons (EditorController::iconsSearch over IconLibraryService's manifest); the
     grid shows each icon as an <img> onto the same per-icon asset route the canvas
     fetch-injects, so nothing here ships 29k inline SVGs into the page.

     The grid scrolls via the app's own ui/custom-scrollbar (never a native bar): the bar is
     mounted as a SIBLING of [data-hb-icon-scroll] inside the positioned __body — the same
     two-layer arrangement the inspector's sub-panels use — and every open/render dispatches
     hb:refresh so a bar that booted while the dialog was [hidden] re-measures. The set
     filter is a STATIC ui/combobox (the sets are known server-side via IconLibraryService),
     not a native select. --}}
@props(['searchUrl' => null, 'sets' => []])
@once
<style>
    .hb-icondialog { width: 720px; height: 560px; }
    .hb-icondialog__bar { display: flex; gap: 8px; padding: var(--hb-space-3, 12px) var(--hb-space-4, 16px) 0; flex: none; }
    .hb-icondialog__search { flex: 1 1 auto; min-width: 0; height: 30px; padding: 0 10px; border: 1px solid var(--hb-border, #E4E4E4); border-radius: var(--hb-radius-md, 5px); font-family: var(--hb-font-sans, Rubik, sans-serif); font-size: var(--hb-fs-base, 13px); }
    .hb-icondialog__set { flex: none; width: 200px; }
    /* Two-layer scroll shell: __body is the positioned, non-scrolling parent the absolute
       custom-scrollbar anchors to; __scroll is the region the bar drives (the bar itself adds
       hb-scroll-container + overflow to it on boot). */
    .hb-icondialog__body { position: relative; flex: 1 1 auto; min-height: 0; }
    .hb-icondialog__scroll { height: 100%; box-sizing: border-box; padding: var(--hb-space-4, 16px); }
    .hb-icondialog__grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(72px, 1fr)); gap: 8px; }
    .hb-icondialog__item { display: flex; flex-direction: column; align-items: center; gap: 4px; padding: 8px 4px; border: 1px solid transparent; border-radius: var(--hb-radius-md, 5px); background: none; cursor: pointer; font-family: var(--hb-font-sans, Rubik, sans-serif); }
    .hb-icondialog__item:hover { border-color: var(--hb-border, #E4E4E4); background: var(--hb-surface-hover, #F7F7F7); }
    .hb-icondialog__item img { width: 28px; height: 28px; display: block; }
    /* The Phosphor SVGs ship with fill="currentColor" but the picker renders them via
       <img> — the SVG is in its own browsing context, currentColor resolves to canvastext
       (black in both themes), so the icons render as hard black on every theme.
       Invert the channel under .hb-editor--dark so they track the chrome text colour
       (--hb-text-primary: #FFF in dark, #0A0A0A in light — filter:invert(1) gives white
       against black, black against white). Scope is the picker dialog + the Content tab
       preview field below, both of which sit on the editor chrome (never the canvas paper). */
    .hb-editor--dark .hb-icondialog__item img,
    .hb-editor--dark .hb-iconfield img { filter: invert(1); }
    .hb-icondialog__item span { max-width: 100%; font-size: 10px; color: var(--hb-text-muted, #9A9A9A); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .hb-icondialog__empty { padding: 48px 0; text-align: center; color: var(--hb-text-muted, #9A9A9A); font-size: var(--hb-fs-base, 13px); }
    .hb-icondialog__empty[hidden] { display: none; }
    .hb-icondialog__more { display: block; margin: var(--hb-space-4, 16px) auto 0; padding: 6px 16px; border: 1px solid var(--hb-border, #E4E4E4); border-radius: var(--hb-radius-control, 6px); background: var(--hb-bg, #fff); cursor: pointer; font-family: var(--hb-font-sans, Rubik, sans-serif); font-size: var(--hb-fs-sm, 12px); }
    .hb-icondialog__more:hover { background: var(--hb-surface-hover, #F7F7F7); }
    .hb-icondialog__more[hidden] { display: none; }

    /* The Content tab's icon field decoration (decorateIconField below): a live preview of the
       picked icon that reopens this dialog. Sits above the raw reference input, which stays for
       power users typing "set/slug" directly. */
    .hb-iconfield { display: flex; align-items: center; gap: 10px; width: 100%; margin-bottom: 6px; padding: 8px 10px; border: 1px solid var(--hb-border, #E4E4E4); border-radius: var(--hb-radius-md, 5px); background: var(--hb-bg, #fff); cursor: pointer; font-family: var(--hb-font-sans, Rubik, sans-serif); }
    .hb-iconfield:hover { border-color: var(--hb-border-focus, #000); }
    .hb-iconfield img { width: 24px; height: 24px; display: block; flex: none; }
    .hb-iconfield img[hidden] { display: none; }
    .hb-iconfield span { flex: 1 1 auto; min-width: 0; text-align: left; font-size: var(--hb-fs-sm, 12px); color: var(--hb-text-secondary, #5A5A5A); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
</style>
<script>
    (() => {
        const PAGE = 96;
        const REF_RE = /^[a-z0-9-]+\/[a-z0-9-]+$/;
        const iconUrl = (reference) => {
            const template = (window.__hbEditor || {}).iconUrlTemplate || '';
            if (!template || !REF_RE.test(reference)) return '';
            const parts = reference.split('/');
            return template.replace('__SET__', parts[0]).replace('__SLUG__', parts[1]);
        };

        // ── the Content tab's icon-preview field ──────────────────────────────
        // Decorates the icon block's generic `icon` text control with a clickable preview that
        // reopens the picker. Lives here (not in inspector.blade.php) because it is icon-feature
        // wiring end to end: same event, same URL template, same dialog.
        function decorateIconField() {
            document.querySelectorAll('[data-hb-block-panel="heisenberg/icon"] [data-hb-control="icon"]').forEach((control) => {
                if (control.__hbIconField) return;
                control.__hbIconField = true;
                const row = document.createElement('button');
                row.type = 'button';
                row.className = 'hb-iconfield';
                row.setAttribute('data-hb-icon-field', '');
                const img = document.createElement('img');
                img.alt = '';
                const label = document.createElement('span');
                row.appendChild(img);
                row.appendChild(label);
                control.parentNode.insertBefore(row, control);
                row.addEventListener('click', () => {
                    const id = window.hbEditor && window.hbEditor.getSelectedId();
                    if (!id) return;
                    document.dispatchEvent(new CustomEvent('hb:pick-icon', { detail: { id: id }, cancelable: true }));
                });
                const input = control.matches('input') ? control : control.querySelector('input');
                input?.addEventListener('input', () => syncIconFields());
            });
        }
        function syncIconFields() {
            const selected = window.hbEditor && window.hbEditor.getSelectedId();
            const model = selected ? window.hbEditor.getModel(selected) : null;
            const isIcon = !!(model && model.name === 'heisenberg/icon');
            document.querySelectorAll('[data-hb-icon-field]').forEach((row) => {
                const control = row.nextElementSibling;
                const input = control && (control.matches('input') ? control : control.querySelector('input'));
                const reference = String((isIcon ? model.attributes.icon : (input && input.value)) || '').trim();
                const url = iconUrl(reference);
                const img = row.querySelector('img');
                img.hidden = !url;
                if (url && img.getAttribute('src') !== url) img.setAttribute('src', url);
                const emptyLabel = document.querySelector('[data-hb-icon-picker]')?.dataset.hbFieldEmpty || 'Select an icon…';
                row.querySelector('span').textContent = url ? reference : emptyLabel;
            });
        }
        document.addEventListener('hb:block-selected', () => { decorateIconField(); syncIconFields(); });
        document.addEventListener('hb:block-updated', (event) => {
            if (event.detail && event.detail.key === 'icon') syncIconFields();
        });

        // ── the dialog itself ─────────────────────────────────────────────────
        const boot = () => {
            decorateIconField();
            document.querySelectorAll('[data-hb-icon-picker]').forEach((scrim) => {
                if (scrim.__hbIconPicker) return;
                scrim.__hbIconPicker = true;

                const searchUrl = scrim.dataset.hbSearchUrl || '';
                const grid = scrim.querySelector('[data-hb-icon-grid]');
                const empty = scrim.querySelector('[data-hb-icon-empty-msg]');
                const more = scrim.querySelector('[data-hb-icon-more]');
                const search = scrim.querySelector('[data-hb-icon-search]');
                const setCombo = scrim.querySelector('[data-hb-icon-set] [data-hb-combobox]');
                let targetId = null;
                let offset = 0;
                let total = 0;
                let seq = 0;
                let debounce = null;

                const query = () => (search ? search.value.trim() : '');
                const activeSet = () => (setCombo ? (setCombo.dataset.value || '') : '');

                function render(rows, append) {
                    if (!append) grid.innerHTML = '';
                    rows.forEach((row) => {
                        const item = document.createElement('button');
                        item.type = 'button';
                        item.className = 'hb-icondialog__item';
                        item.setAttribute('data-hb-icon-pick', row.reference);
                        const img = document.createElement('img');
                        img.src = row.url;
                        img.loading = 'lazy';
                        img.alt = '';
                        const label = document.createElement('span');
                        label.textContent = row.slug;
                        item.title = row.reference;
                        item.appendChild(img);
                        item.appendChild(label);
                        grid.appendChild(item);
                    });
                    empty.hidden = grid.children.length > 0;
                    more.hidden = grid.children.length >= total;
                    // The custom scrollbar tracks content height — a fresh page of icons (or a
                    // cleared grid) changes it, so ask every bar to re-measure.
                    document.dispatchEvent(new CustomEvent('hb:refresh'));
                }

                function load(append) {
                    if (!searchUrl) return;
                    const mySeq = ++seq;
                    if (!append) { offset = 0; }
                    const params = new URLSearchParams({ q: query(), set: activeSet(), limit: String(PAGE), offset: String(offset) });
                    window.fetch(searchUrl + '?' + params.toString(), { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
                        .then((r) => (r.ok ? r.json() : { icons: [], total: 0 }))
                        .then((data) => {
                            if (mySeq !== seq) return; // a newer search superseded this response
                            total = data.total || 0;
                            offset += (data.icons || []).length;
                            render(data.icons || [], !!append);
                        })
                        .catch(() => { if (mySeq === seq) { grid.innerHTML = ''; empty.hidden = false; } });
                }

                search?.addEventListener('input', () => { clearTimeout(debounce); debounce = setTimeout(() => load(false), 200); });
                // ui/combobox dispatches its committed value as a bubbling `change` on its root.
                setCombo?.addEventListener('change', (event) => {
                    if (event.target !== setCombo) return;
                    load(false);
                });
                more?.addEventListener('click', () => load(true));

                grid.addEventListener('click', (event) => {
                    const item = event.target.closest('[data-hb-icon-pick]');
                    if (!item || !targetId || !window.hbEditor) return;
                    window.hbEditor.setAttribute(targetId, 'icon', item.getAttribute('data-hb-icon-pick'));
                    targetId = null;
                    if (scrim.hbClose) scrim.hbClose(); else scrim.hidden = true;
                    syncIconFields();
                });

                scrim.querySelector('.hb-mediadialog__close')?.addEventListener('click', () => {
                    if (scrim.hbClose) scrim.hbClose(); else scrim.hidden = true;
                });

                // The runtime's cancelable intent event — same seam as the image block's
                // hb:pick-image. preventDefault claims it so no fallback ever competes.
                if (!document.__hbIconPickerOpen) {
                    document.__hbIconPickerOpen = true;
                    document.addEventListener('hb:pick-icon', (event) => {
                        event.preventDefault();
                        targetId = event.detail && event.detail.id ? event.detail.id : null;
                        const blk = targetId ? document.querySelector('.hb-blk[data-block="' + targetId + '"]') : null;
                        if (scrim.hbOpen) scrim.hbOpen(blk ? blk.querySelector('.hb-icon-empty') : null); else scrim.hidden = false;
                        if (!grid.children.length) load(false);
                        // The bar booted while the dialog was [hidden] and measured a 0-height
                        // track — now that it's visible, re-measure.
                        document.dispatchEvent(new CustomEvent('hb:refresh'));
                        if (search) { search.focus(); search.select(); }
                    });
                }
            });
        };
        if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot, { once: true });
        else boot();
        document.addEventListener('hb:refresh', boot);
    })();
</script>
@endonce

@php
    $hbIconSetOptions = collect($sets)
        ->map(fn ($set) => ['value' => (string) $set, 'label' => (string) $set])
        ->prepend(['value' => '', 'label' => __('heisenberg::editor.icon_picker.all_sets')])
        ->values()
        ->all();
@endphp
<div class="hb-mediadialog__scrim" data-hb-icon-picker hidden data-hb-search-url="{{ $searchUrl ?? '' }}"
    data-hb-field-empty="{{ __('heisenberg::editor.icon_picker.field_empty') }}">
    <div class="hb-mediadialog hb-icondialog" role="dialog" aria-modal="true" aria-label="{{ __('heisenberg::editor.icon_picker.title') }}" tabindex="-1">
        <div class="hb-mediadialog__top">
            <span class="hb-mediadialog__title">{{ __('heisenberg::editor.icon_picker.title') }}</span>
            <button type="button" class="hb-mediadialog__close" aria-label="{{ __('heisenberg::editor.common.close') }}">
                @include('heisenberg::components.ui.icon', ['name' => 'x', 'size' => 16])
            </button>
        </div>
        <div class="hb-icondialog__bar">
            <input type="search" class="hb-icondialog__search" data-hb-icon-search
                placeholder="{{ __('heisenberg::editor.icon_picker.search_ph') }}" aria-label="{{ __('heisenberg::editor.icon_picker.search_ph') }}">
            <div class="hb-icondialog__set" data-hb-icon-set>
                <x-ui.combobox static value="" :options="$hbIconSetOptions"
                    :placeholder="__('heisenberg::editor.icon_picker.all_sets')"
                    :aria-label="__('heisenberg::editor.icon_picker.all_sets')" />
            </div>
        </div>
        <div class="hb-icondialog__body">
            <div class="hb-icondialog__scroll" data-hb-icon-scroll>
                <div class="hb-icondialog__empty" data-hb-icon-empty-msg hidden>{{ __('heisenberg::editor.icon_picker.empty') }}</div>
                <div class="hb-icondialog__grid" data-hb-icon-grid></div>
                <button type="button" class="hb-icondialog__more" data-hb-icon-more hidden>{{ __('heisenberg::editor.icon_picker.load_more') }}</button>
            </div>
            <x-ui.custom-scrollbar container="[data-hb-icon-scroll]" />
        </div>
    </div>
</div>
