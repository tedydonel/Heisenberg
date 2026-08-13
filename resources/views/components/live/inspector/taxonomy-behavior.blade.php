        {{-- Categories/Tags/Discussion/Page-layout field behavior — backed by the taxonomy and
             PostSettings controllers. Scoped to [data-hb-post-taxonomy-field]/
             [data-hb-post-discussion-field]/[data-hb-post-layout-field] so this never touches the
             Block-tab region below, same convention as the featured-image block above. --}}
        @once
        <style>
            .hb-post-taxonomy-body,
            .hb-post-discussion-body,
            .hb-post-layout-body,
            .hb-post-toc-body { display: flex; flex-direction: column; gap: 6px; padding: 0 var(--hb-space-3, 12px) var(--hb-space-3, 12px); }
            .hb-post-taxonomy-body[hidden],
            .hb-post-discussion-body[hidden],
            .hb-post-layout-body[hidden],
            .hb-post-toc-body[hidden] { display: none; }
            .hb-post-taxonomy-hint { font-family: var(--hb-font-sans, Rubik, sans-serif); font-size: var(--hb-fs-xs, 11px); color: var(--hb-text-muted, #9A9A9A); }
            .hb-post-taxonomy-hint[hidden] { display: none; }

            .hb-post-toc-summary { font-family: var(--hb-font-sans, Rubik, sans-serif); font-size: var(--hb-fs-sm, 12px); color: var(--hb-text-secondary, #5A5A5A); }
            .hb-post-toc-edit {
                align-self: flex-start; border: 1px solid var(--hb-border, #E4E4E4); cursor: pointer;
                padding: 5px 10px; border-radius: var(--hb-radius-control, 6px);
                background: var(--hb-bg, #fff); color: var(--hb-text-secondary, #5A5A5A);
                font-family: var(--hb-font-sans, Rubik, sans-serif); font-size: var(--hb-fs-sm, 12px); font-weight: 600;
            }
            .hb-post-toc-edit:hover { background: var(--hb-surface-hover, #F7F7F7); }

            {{-- Two-layer scroll shell (the scrollbar's `container` can't double as the bar's own
                 direct parent). Shared by BOTH Categories and Tags. `max-height`
                 (not a fixed `height`) on -scroll, and no explicit height at all on -wrap: a list
                 shorter than the cap shrink-wraps to its real content height (no dead space when a
                 post only has 2-3 categories) — the wrap simply matches whatever height -scroll
                 actually renders at, since it's -scroll's only child. custom-scrollbar's own
                 `bar.hidden = bounds <= 0` already auto-hides the bar when nothing needs scrolling. --}}
            .hb-post-taxonomy-list-wrap { position: relative; }
            .hb-post-taxonomy-list-scroll { max-height: 140px; overflow: hidden; }
            .hb-post-taxonomy-list { display: flex; flex-direction: column; gap: 2px; padding-right: 8px; }
            .hb-post-taxonomy-item { width: 100%; box-sizing: border-box; padding: 6px 8px; border-radius: var(--hb-radius-sm, 3px); flex: none; }
            .hb-post-taxonomy-item:hover { background: var(--hb-bg-muted, #F4F4F4); }
            {{-- [hidden] override: ui/checkbox's own .hb-checkbox rule sets display:inline-flex,
                 which otherwise beats the UA stylesheet's [hidden] at equal specificity — same fix
                 already applied elsewhere in this file (see .hb-inspector__icon[hidden] above). --}}
            .hb-post-taxonomy-item[hidden] { display: none; }
            .hb-post-taxonomy-empty { padding: 6px 8px; font-family: var(--hb-font-sans, Rubik, sans-serif); font-size: var(--hb-fs-xs, 11px); color: var(--hb-text-muted, #9A9A9A); }
            .hb-post-taxonomy-empty[hidden] { display: none; }
            {{-- outline:0 + a border-color shift on :focus, not a native browser focus ring — same
                 treatment ui/input gives its own text input (there via :focus-within on a wrapping
                 div, since that input has no border of its own; this one has no wrapper, so :focus
                 lands directly on it). Leaving the UA default outline in would show a much heavier
                 ring than every other text field in this app. --}}
            .hb-post-taxonomy-add-input { width: 100%; height: 30px; box-sizing: border-box; padding: 0 10px; border: 1px solid var(--hb-border, #E4E4E4); border-radius: var(--hb-radius-md, 5px); background: var(--hb-bg, #fff); font-family: var(--hb-font-sans, Rubik, sans-serif); font-size: var(--hb-fs-sm, 12px); color: var(--hb-text-primary, #0A0A0A); transition: border-color .12s ease; }
            .hb-post-taxonomy-add-input:hover { border-color: var(--hb-border-strong, #C8C8C8); }
            .hb-post-taxonomy-add-input:focus { outline: 0; border-color: var(--hb-border-focus, #000); }
            .hb-post-taxonomy-add-input:disabled { opacity: .5; cursor: not-allowed; }
            .hb-post-taxonomy-add-input::placeholder { color: var(--hb-text-muted, #9A9A9A); }

            .hb-post-layout-row { display: flex; align-items: center; gap: 10px; }
            .hb-post-layout-row__label { flex: 1 1 auto; min-width: 0; font-family: var(--hb-font-sans, Rubik, sans-serif); font-size: var(--hb-fs-sm, 12px); color: var(--hb-text-secondary, #5A5A5A); }
            .hb-post-layout-row__readout { flex: none; width: 34px; text-align: right; font-family: var(--hb-font-sans, Rubik, sans-serif); font-size: var(--hb-fs-xs, 11px); color: var(--hb-text-muted, #9A9A9A); }

            {{-- Translations (docs/content-translation.md §0/Wave 2) — one row per configured
                 locale; clicking a row switches the editing locale (no navigation, no request). --}}
            .hb-post-translations-body { display: flex; flex-direction: column; gap: 6px; padding: 0 var(--hb-space-3, 12px) var(--hb-space-3, 12px); }
            .hb-post-translations-body[hidden] { display: none; }
            .hb-post-translations-list { display: flex; flex-direction: column; gap: 4px; }
            .hb-post-translation-row {
                display: flex; align-items: center; justify-content: space-between; gap: 8px;
                width: 100%; padding: 6px 4px; border: 0; border-radius: var(--hb-radius-sm, 3px);
                background: none; cursor: pointer; text-align: left;
            }
            .hb-post-translation-row:hover { background: var(--hb-surface-hover, #F7F7F7); }
            .hb-post-translation-row.is-current { background: var(--hb-bg-muted, #F4F4F4); }
            .hb-post-translation-row__locale { flex: 1 1 auto; min-width: 0; font-family: var(--hb-font-sans, Rubik, sans-serif); font-size: var(--hb-fs-sm, 12px); color: var(--hb-text-primary, #0A0A0A); }
            .hb-post-translation-row.is-current .hb-post-translation-row__locale { font-weight: 600; }
            .hb-post-translation-row__chip {
                flex: none; padding: 2px 8px; border-radius: var(--hb-radius-full, 999px);
                font-family: var(--hb-font-sans, Rubik, sans-serif); font-size: var(--hb-fs-xs, 11px); font-weight: 600;
                background: var(--hb-bg-muted, #F4F4F4); color: var(--hb-text-secondary, #5A5A5A);
            }
            .hb-post-translation-row__chip--complete { background: #E3F5E8; color: #1B7A3D; }
        </style>
        <script>
            (() => {
                const csrfToken = () => {
                    const meta = document.querySelector('meta[name="csrf-token"]');
                    return meta ? meta.getAttribute('content') : '';
                };
                const jsonFetch = (url, options) => window.fetch(url, Object.assign({
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken(), 'X-Requested-With': 'XMLHttpRequest' },
                    credentials: 'same-origin',
                }, options)).then((res) => res.json().catch(() => ({})).then((body) => ({ ok: res.ok, body })));

                // ── Categories/Tags: ONE shared multi-select checklist ──
                // Both now attach via a real pivot (Category::posts()/Tag::posts() are both
                // BelongsToMany as of 2026-08-03 — see Post::categories()/tags()), so a single
                // generic widget serves both fields, differentiated only by their own data-*
                // attributes (attach URL template, the "create new" index URL + its response
                // envelope key) and whichever options Blade already rendered as real ui/checkbox
                // rows. Checking a box POSTs an attach; unchecking DELETEs.
                const wirePostTaxonomy = (field) => {
                    if (field.__hbPostTaxonomy) return;
                    field.__hbPostTaxonomy = true;

                    const list = field.querySelector('[data-hb-post-taxonomy-list]');
                    const empty = field.querySelector('[data-hb-post-taxonomy-empty]');
                    const addInput = field.querySelector('[data-hb-post-taxonomy-add-input]');
                    const hint = field.querySelector('[data-hb-post-taxonomy-hint]');
                    const itemTemplate = field.querySelector('template[data-hb-post-taxonomy-item-template]');
                    if (!list || !addInput || !itemTemplate) return;

                    let postId = field.dataset.hbPostId ? Number(field.dataset.hbPostId) : null;
                    const attachTemplate = field.dataset.hbAttachUrlTemplate || '';
                    const createUrl = field.dataset.hbIndexUrl || '';
                    const responseKey = field.dataset.hbCreateResponseKey || '';

                    const items = () => Array.from(list.querySelectorAll('.hb-post-taxonomy-item'));
                    const inputOf = (item) => item.querySelector('.hb-checkbox__input');
                    const labelOf = (item) => item.querySelector('.hb-checkbox__label')?.textContent || '';

                    const setEnabled = (enabled) => {
                        addInput.disabled = !enabled;
                        items().forEach((item) => {
                            const input = inputOf(item);
                            if (input) input.disabled = !enabled;
                            item.classList.toggle('hb-checkbox--disabled', !enabled);
                        });
                        if (hint) hint.hidden = enabled;
                    };

                    const attach = (itemValue, checked) => {
                        if (postId === null || !attachTemplate) return;
                        const url = attachTemplate.replace('__ID__', postId).replace('__ITEM_ID__', itemValue);
                        const options = checked ? { method: 'POST', body: '{}' } : { method: 'DELETE' };
                        jsonFetch(url, options).then(({ ok }) => {
                            if (!ok) console.error('[heisenberg] taxonomy ' + (checked ? 'attach' : 'detach') + ' failed');
                        });
                    };

                    const clearFilter = () => items().forEach((item) => { item.hidden = false; });

                    const addItem = (record) => {
                        const node = itemTemplate.content.firstElementChild.cloneNode(true);
                        const input = inputOf(node);
                        if (input) input.value = String(record.id);
                        const labelEl = node.querySelector('.hb-checkbox__label');
                        if (labelEl) labelEl.textContent = record.name_en || record.name || '';
                        list.insertBefore(node, empty || null);
                        if (empty) empty.hidden = true;
                        // The scrollbar's own ResizeObserver watches the FIXED-height wrap, not
                        // this list's scrollHeight growing inside it — nudge it directly so a
                        // freshly-added row is immediately reflected in the track/thumb math.
                        document.dispatchEvent(new CustomEvent('hb:refresh'));
                        return node;
                    };

                    list.addEventListener('change', (event) => {
                        const input = event.target.closest('.hb-checkbox__input');
                        const item = input ? input.closest('.hb-post-taxonomy-item') : null;
                        if (!item || !input) return;
                        attach(input.value, input.checked);
                    });

                    // The add-input doubles as a filter over the already-loaded list (every
                    // category/tag is server-rendered up front — nothing to fetch to search it,
                    // unlike the Fonts catalog) — Enter with no exact match creates a new one.
                    addInput.addEventListener('input', () => {
                        const query = addInput.value.trim().toLowerCase();
                        items().forEach((item) => { item.hidden = query !== '' && !labelOf(item).toLowerCase().includes(query); });
                    });

                    addInput.addEventListener('keydown', (event) => {
                        if (event.key !== 'Enter') return;
                        event.preventDefault();
                        const value = addInput.value.trim();
                        if (!value) return;
                        const exact = items().find((item) => labelOf(item).toLowerCase() === value.toLowerCase());
                        if (exact) {
                            const input = inputOf(exact);
                            if (input && !input.checked) { input.checked = true; attach(input.value, true); }
                            addInput.value = '';
                            clearFilter();
                            return;
                        }
                        if (!createUrl) return;
                        jsonFetch(createUrl, { method: 'POST', body: JSON.stringify({ name_en: value }) })
                            .then(({ ok, body }) => {
                                const record = responseKey ? body[responseKey] : null;
                                if (!ok || !record) { console.error('[heisenberg] taxonomy create failed'); return; }
                                const node = addItem(record);
                                addInput.value = '';
                                clearFilter();
                                const input = inputOf(node);
                                if (input) input.checked = true;
                                attach(String(record.id), true);
                            });
                    });

                    document.addEventListener('hb:post-id', (event) => {
                        postId = event.detail.id;
                        field.dataset.hbPostId = String(postId);
                        setEnabled(true);
                    });

                    if (postId !== null) setEnabled(true);
                };

                // ── Discussion: a single Allow-comments toggle ──
                const wirePostDiscussion = (field) => {
                    if (field.__hbPostDiscussion) return;
                    field.__hbPostDiscussion = true;

                    const toggle = field.querySelector('[data-hb-post-allow-comments]');
                    const hint = field.querySelector('[data-hb-post-discussion-hint]');
                    if (!toggle) return;

                    let postId = field.dataset.hbPostId ? Number(field.dataset.hbPostId) : null;
                    const urlTemplate = field.dataset.hbDiscussionUrlTemplate || '';

                    const setEnabled = (enabled) => {
                        toggle.disabled = !enabled;
                        toggle.closest('label')?.classList.toggle('hb-toggle--disabled', !enabled);
                        if (hint) hint.hidden = enabled;
                    };

                    toggle.addEventListener('change', () => {
                        if (postId === null || !urlTemplate) return;
                        const url = urlTemplate.replace('__ID__', postId);
                        jsonFetch(url, { method: 'PUT', body: JSON.stringify({ allow_comments: toggle.checked }) })
                            .then(({ ok }) => { if (!ok) console.error('[heisenberg] discussion save failed'); });
                    });

                    document.addEventListener('hb:post-id', (event) => {
                        postId = event.detail.id;
                        field.dataset.hbPostId = String(postId);
                        setEnabled(true);
                    });

                    if (postId !== null) setEnabled(true);
                };

                // ── Page layout: X/Y page padding sliders ──
                // Reaches past this component into the canvas (document.querySelector('.hb-page'))
                // the same direct way the featured-image field above reaches into the media dialog
                // — there's only ever one .hb-page on screen, and every other cross-component
                // channel in this app (hb:refresh, hb:post-id) is a broadcast, not a fit for "paint
                // this CSS var live as the slider moves."
                const wirePostLayout = (field) => {
                    if (field.__hbPostLayout) return;
                    field.__hbPostLayout = true;

                    const xSlider = field.querySelector('[data-hb-post-layout-x]');
                    const ySlider = field.querySelector('[data-hb-post-layout-y]');
                    const xReadout = field.querySelector('[data-hb-post-layout-x-readout]');
                    const yReadout = field.querySelector('[data-hb-post-layout-y-readout]');
                    const hint = field.querySelector('[data-hb-post-layout-hint]');
                    if (!xSlider || !ySlider) return;

                    let postId = field.dataset.hbPostId ? Number(field.dataset.hbPostId) : null;
                    const urlTemplate = field.dataset.hbLayoutUrlTemplate || '';
                    let saveTimer = null;

                    const setEnabled = (enabled) => {
                        xSlider.disabled = !enabled;
                        ySlider.disabled = !enabled;
                        if (hint) hint.hidden = enabled;
                    };

                    const apply = () => {
                        const page = document.querySelector('.hb-page');
                        if (!page) return;
                        page.style.setProperty('--hb-page-padding-x', xSlider.value + 'px');
                        page.style.setProperty('--hb-page-padding-y', ySlider.value + 'px');
                    };

                    const scheduleSave = () => {
                        if (postId === null || !urlTemplate) return;
                        clearTimeout(saveTimer);
                        saveTimer = setTimeout(() => {
                            const url = urlTemplate.replace('__ID__', postId);
                            jsonFetch(url, { method: 'PUT', body: JSON.stringify({
                                page_padding_x: Number(xSlider.value),
                                page_padding_y: Number(ySlider.value),
                            }) }).then(({ ok }) => { if (!ok) console.error('[heisenberg] page layout save failed'); });
                        }, 400);
                    };

                    const onInput = (slider, readout) => {
                        if (readout) readout.textContent = slider.value + 'px';
                        apply();
                        scheduleSave();
                    };

                    xSlider.addEventListener('input', () => onInput(xSlider, xReadout));
                    ySlider.addEventListener('input', () => onInput(ySlider, yReadout));

                    document.addEventListener('hb:post-id', (event) => {
                        postId = event.detail.id;
                        field.dataset.hbPostId = String(postId);
                        setEnabled(true);
                    });

                    if (postId !== null) setEnabled(true);
                };

                // ── Translations: click a row to switch the editing locale ──
                // docs/content-translation.md §0/Wave 2 — a pure client-side switch
                // (window.hbEditor.setEditingLocale), so this wires against EVERY render of the
                // field, saved post or not (unlike wirePostTaxonomy/wirePostDiscussion/
                // wirePostLayout above, there is no hb:post-id-gated enable step to wait for).
                const wirePostTranslations = (field) => {
                    if (field.__hbPostTranslations) return;
                    field.__hbPostTranslations = true;

                    const markCurrent = () => {
                        const current = window.hbEditor && window.hbEditor.getEditingLocale ? window.hbEditor.getEditingLocale() : null;
                        field.querySelectorAll('[data-hb-translation-row]').forEach((row) => {
                            row.classList.toggle('is-current', row.dataset.hbTranslationLocale === current);
                        });
                    };
                    field.querySelectorAll('[data-hb-translation-row]').forEach((row) => {
                        row.addEventListener('click', () => {
                            if (window.hbEditor && window.hbEditor.setEditingLocale) window.hbEditor.setEditingLocale(row.dataset.hbTranslationLocale);
                        });
                    });
                    markCurrent();
                    document.addEventListener('hb:editing-locale-change', markCurrent);
                };

                const boot = () => {
                    document.querySelectorAll('[data-hb-post-taxonomy-field]').forEach(wirePostTaxonomy);
                    document.querySelectorAll('[data-hb-post-discussion-field]').forEach(wirePostDiscussion);
                    document.querySelectorAll('[data-hb-post-layout-field]').forEach(wirePostLayout);
                    document.querySelectorAll('[data-hb-post-translations-field]').forEach(wirePostTranslations);
                };
                if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot, { once: true });
                else boot();
                document.addEventListener('hb:refresh', boot);
            })();
        </script>
        @endonce
