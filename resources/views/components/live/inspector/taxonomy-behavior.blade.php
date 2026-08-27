        @once
        <style nonce="{{ heisenberg_csp_nonce() }}">
            .hb-post-taxonomy-body,
            .hb-post-discussion-body,
            .hb-post-layout-body,
            .hb-post-toc-body { display: flex; flex-direction: column; gap: 6px; padding: 0 var(--hb-space-3, 12px) var(--hb-space-3, 12px); }
            .hb-post-taxonomy-body[hidden],
            .hb-post-discussion-body[hidden],
            .hb-post-layout-body[hidden],
            .hb-post-toc-body[hidden] { display: none; }
            .hb-post-taxonomy-hint { font-family: var(--hb-font-sans, Rubik, sans-serif); font-size: var(--hb-fs-xs, 11px); color: var(--hb-text-muted); }
            .hb-post-taxonomy-hint[hidden] { display: none; }

            .hb-post-toc-summary { font-family: var(--hb-font-sans, Rubik, sans-serif); font-size: var(--hb-fs-sm, 12px); color: var(--hb-text-secondary); }
            .hb-post-toc-edit {
                align-self: flex-start; border: 1px solid var(--hb-border); cursor: pointer;
                padding: 5px 10px; border-radius: var(--hb-radius-control, 6px);
                background: var(--hb-bg); color: var(--hb-text-secondary);
                font-family: var(--hb-font-sans, Rubik, sans-serif); font-size: var(--hb-fs-sm, 12px); font-weight: 600;
            }
            .hb-post-toc-edit:hover { background: var(--hb-surface-hover); }

            .hb-post-taxonomy-list-wrap { position: relative; }
            .hb-post-taxonomy-list-scroll { max-height: 140px; overflow: hidden; }
            .hb-post-taxonomy-list { display: flex; flex-direction: column; gap: 2px; padding-right: 8px; }
            .hb-post-taxonomy-item { width: 100%; box-sizing: border-box; padding: 6px 8px; border-radius: var(--hb-radius-sm, 3px); flex: none; }
            .hb-post-taxonomy-item:hover { background: var(--hb-bg-muted); }
            .hb-post-taxonomy-item[hidden] { display: none; }
            .hb-post-taxonomy-empty { padding: 6px 8px; font-family: var(--hb-font-sans, Rubik, sans-serif); font-size: var(--hb-fs-xs, 11px); color: var(--hb-text-muted); }
            .hb-post-taxonomy-empty[hidden] { display: none; }
            .hb-post-taxonomy-add-input { width: 100%; height: 30px; box-sizing: border-box; padding: 0 10px; border: 1px solid var(--hb-border); border-radius: var(--hb-radius-md, 5px); background: var(--hb-bg); font-family: var(--hb-font-sans, Rubik, sans-serif); font-size: var(--hb-fs-sm, 12px); color: var(--hb-text-primary); transition: border-color .12s ease; }
            .hb-post-taxonomy-add-input:hover { border-color: var(--hb-border-strong); }
            .hb-post-taxonomy-add-input:focus { outline: 0; border-color: var(--hb-border-focus); }
            .hb-post-taxonomy-add-input:disabled { opacity: .5; cursor: not-allowed; }
            .hb-post-taxonomy-add-input::placeholder { color: var(--hb-text-muted); }

            .hb-post-layout-row { display: flex; align-items: center; gap: 10px; }
            .hb-post-layout-row__label { flex: 1 1 auto; min-width: 0; font-family: var(--hb-font-sans, Rubik, sans-serif); font-size: var(--hb-fs-sm, 12px); color: var(--hb-text-secondary); }
            .hb-post-layout-row__readout { flex: none; width: 34px; text-align: right; font-family: var(--hb-font-sans, Rubik, sans-serif); font-size: var(--hb-fs-xs, 11px); color: var(--hb-text-muted); }

            .hb-post-translations-body { display: flex; flex-direction: column; gap: 6px; padding: 0 var(--hb-space-3, 12px) var(--hb-space-3, 12px); }
            .hb-post-translations-body[hidden] { display: none; }
            .hb-post-translations-list { display: flex; flex-direction: column; gap: 4px; }
            .hb-post-translation-row {
                display: flex; align-items: center; justify-content: space-between; gap: 8px;
                width: 100%; padding: 6px 4px; border: 0; border-radius: var(--hb-radius-sm, 3px);
                background: none; cursor: pointer; text-align: left;
            }
            .hb-post-translation-row:hover { background: var(--hb-surface-hover); }
            .hb-post-translation-row.is-current { background: var(--hb-bg-muted); }
            .hb-post-translation-row__locale { flex: 1 1 auto; min-width: 0; font-family: var(--hb-font-sans, Rubik, sans-serif); font-size: var(--hb-fs-sm, 12px); color: var(--hb-text-primary); }
            .hb-post-translation-row.is-current .hb-post-translation-row__locale { font-weight: 600; }
            .hb-post-translation-row__chip {
                flex: none; padding: 2px 8px; border-radius: var(--hb-radius-full, 999px);
                font-family: var(--hb-font-sans, Rubik, sans-serif); font-size: var(--hb-fs-xs, 11px); font-weight: 600;
                background: var(--hb-bg-muted); color: var(--hb-text-secondary);
            }
            .hb-post-translation-row__chip--complete { background: #E3F5E8; color: #1B7A3D; }
        </style>
        <script nonce="{{ heisenberg_csp_nonce() }}">
            (() => {
                const csrfToken = () => {
                    const meta = document.querySelector('meta[name="csrf-token"]');
                    return meta ? meta.getAttribute('content') : '';
                };
                const jsonFetch = (url, options) => window.fetch(url, Object.assign({
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken(), 'X-Requested-With': 'XMLHttpRequest' },
                    credentials: 'same-origin',
                }, options)).then((res) => res.json().catch(() => ({})).then((body) => ({ ok: res.ok, body })));

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
                        document.dispatchEvent(new CustomEvent('hb:refresh'));
                        return node;
                    };

                    list.addEventListener('change', (event) => {
                        const input = event.target.closest('.hb-checkbox__input');
                        const item = input ? input.closest('.hb-post-taxonomy-item') : null;
                        if (!item || !input) return;
                        attach(input.value, input.checked);
                    });

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

                const wirePostDiscussion = (field) => {
                    if (field.__hbPostDiscussion) return;
                    field.__hbPostDiscussion = true;

                    const toggle = field.querySelector('[data-hb-post-allow-comments] .hb-toggle__input');
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
