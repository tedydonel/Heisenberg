@props(['searchUrl' => null, 'sets' => []])
@once
<style>
    .hb-icondialog { width: 720px; height: 560px; }
    .hb-icondialog__bar { display: flex; gap: 8px; padding: var(--hb-space-3, 12px) var(--hb-space-4, 16px) 0; flex: none; }
    .hb-icondialog__bar > .hb-searchfield { flex: 1 1 auto; min-width: 0; height: 30px; }
    .hb-icondialog__bar > .hb-searchfield .hb-searchfield__input { height: 100%; font-size: var(--hb-fs-base, 13px); }
    .hb-icondialog__set { flex: none; width: 200px; }
    .hb-icondialog__body { position: relative; flex: 1 1 auto; min-height: 0; }
    .hb-icondialog__scroll { height: 100%; box-sizing: border-box; padding: var(--hb-space-4, 16px); }
    .hb-icondialog__grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(72px, 1fr)); gap: 8px; }
    .hb-icondialog__item { display: flex; flex-direction: column; align-items: center; gap: 4px; padding: 8px 4px; border: 1px solid transparent; border-radius: var(--hb-radius-md, 5px); background: none; cursor: pointer; font-family: var(--hb-font-sans, Rubik, sans-serif); }
    .hb-icondialog__item:hover { border-color: var(--hb-border); background: var(--hb-surface-hover); }
    .hb-icondialog__item img { width: 28px; height: 28px; display: block; }
    .hb-editor--dark .hb-icondialog__item img,
    .hb-editor--dark .hb-iconfield img { filter: invert(1); }
    .hb-icondialog__item span { max-width: 100%; font-size: 10px; color: var(--hb-text-muted); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .hb-icondialog__empty { padding: 48px 0; text-align: center; color: var(--hb-text-muted); font-size: var(--hb-fs-base, 13px); }
    .hb-icondialog__empty[hidden] { display: none; }
    .hb-icondialog__more { display: block; margin: var(--hb-space-4, 16px) auto 0; padding: 6px 16px; border: 1px solid var(--hb-border); border-radius: var(--hb-radius-control, 6px); background: var(--hb-bg); cursor: pointer; font-family: var(--hb-font-sans, Rubik, sans-serif); font-size: var(--hb-fs-sm, 12px); }
    .hb-icondialog__more:hover { background: var(--hb-surface-hover); }
    .hb-icondialog__more[hidden] { display: none; }

    .hb-iconfield { display: flex; align-items: center; gap: 10px; width: 100%; margin-bottom: 6px; padding: 8px 10px; border: 1px solid var(--hb-border); border-radius: var(--hb-radius-md, 5px); background: var(--hb-bg); cursor: pointer; font-family: var(--hb-font-sans, Rubik, sans-serif); }
    .hb-iconfield:hover { border-color: var(--hb-border-focus); }
    .hb-iconfield img { width: 24px; height: 24px; display: block; flex: none; }
    .hb-iconfield img[hidden] { display: none; }
    .hb-iconfield span { flex: 1 1 auto; min-width: 0; text-align: left; font-size: var(--hb-fs-sm, 12px); color: var(--hb-text-secondary); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
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

        const boot = () => {
            decorateIconField();
            document.querySelectorAll('[data-hb-icon-picker]').forEach((scrim) => {
                if (scrim.__hbIconPicker) return;
                scrim.__hbIconPicker = true;

                const searchUrl = scrim.dataset.hbSearchUrl || '';
                const grid = scrim.querySelector('[data-hb-icon-grid]');
                const empty = scrim.querySelector('[data-hb-icon-empty-msg]');
                const more = scrim.querySelector('[data-hb-icon-more]');
                const searchWrap = scrim.querySelector('[data-hb-icon-search]');
                const search = searchWrap ? searchWrap.querySelector('.hb-searchfield__input') : null;
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
                            if (mySeq !== seq) return;
                            total = data.total || 0;
                            offset += (data.icons || []).length;
                            render(data.icons || [], !!append);
                        })
                        .catch(() => { if (mySeq === seq) { grid.innerHTML = ''; empty.hidden = false; } });
                }

                search?.addEventListener('input', () => { clearTimeout(debounce); debounce = setTimeout(() => load(false), 200); });
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

                if (!document.__hbIconPickerOpen) {
                    document.__hbIconPickerOpen = true;
                    document.addEventListener('hb:pick-icon', (event) => {
                        event.preventDefault();
                        targetId = event.detail && event.detail.id ? event.detail.id : null;
                        const blk = targetId ? document.querySelector('.hb-blk[data-block="' + targetId + '"]') : null;
                        if (scrim.hbOpen) scrim.hbOpen(blk ? blk.querySelector('.hb-icon-empty') : null); else scrim.hidden = false;
                        if (!grid.children.length) load(false);
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
            <x-heisenberg::ui.search-field :placeholder="__('heisenberg::editor.icon_picker.search_ph')"
                :aria-label="__('heisenberg::editor.icon_picker.search_ph') ?? null" data-hb-icon-search />
            <div class="hb-icondialog__set" data-hb-icon-set>
                <x-heisenberg::ui.combobox static value="" :options="$hbIconSetOptions"
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
            <x-heisenberg::ui.custom-scrollbar container="[data-hb-icon-scroll]" />
        </div>
    </div>
</div>
