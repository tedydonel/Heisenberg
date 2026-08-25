@once
<style>
    .hb-panel-style { display: flex; flex-direction: column; width: 240px; height: 100%; background: var(--hb-bg); border-right: 1px solid var(--hb-border); flex: none; }
    .hb-panel-style__content { display: flex; flex-direction: column; flex: 1 1 auto; min-height: 0; overflow: hidden; position: relative; }
    .hb-panel-style__content[hidden] { display: none; }

    .hb-token-section { display: flex; flex-direction: column; gap: var(--hb-space-3, 12px); padding: var(--hb-space-3, 12px); border-bottom: 1px solid var(--hb-border); flex: none; }
    .hb-token-section--last { padding: var(--hb-space-4, 16px); border-bottom: 0; }
    .hb-token-section__title { font-family: var(--hb-font-sans, Rubik, sans-serif); font-size: var(--hb-fs-lg, 16px); font-weight: 500; color: var(--hb-text-primary); }
    .hb-token-row { display: flex; align-items: center; gap: var(--hb-space-2, 8px); width: 100%; }
    .hb-token-row__remove { display: inline-flex; width: 14px; height: 14px; color: var(--hb-text-muted); flex: none; cursor: pointer; }
    .hb-token-add { display: inline-flex; align-items: center; gap: var(--hb-space-2, 8px); border: 0; background: transparent; cursor: pointer; padding: 0; }
    .hb-token-add__icon { display: inline-flex; width: 14px; height: 14px; color: var(--hb-text-muted); }
    .hb-token-add__label { font-family: var(--hb-font-sans, Rubik, sans-serif); font-size: var(--hb-fs-sm, 12px); font-weight: 500; color: var(--hb-text-muted); }

    .hb-token-savebar { padding: var(--hb-space-3, 12px); border-top: 1px solid var(--hb-border); display: flex; flex-direction: column; gap: 8px; flex: none; }
    .hb-token-savebar[hidden] { display: none; }
    .hb-token-saveform { display: flex; align-items: center; gap: 6px; }
    .hb-token-saveform[hidden] { display: none; }
    .hb-token-saveform__confirm, .hb-token-saveform__cancel { display: inline-flex; align-items: center; justify-content: center; width: 26px; height: 26px; padding: 0; border: 1px solid var(--hb-border); border-radius: var(--hb-radius-md, 5px); background: var(--hb-bg); color: var(--hb-text-muted); cursor: pointer; flex: none; }
    .hb-token-saveform__confirm:hover, .hb-token-saveform__cancel:hover { border-color: var(--hb-border-strong); }
    .hb-token-saveform__error { font-family: var(--hb-font-sans, Rubik, sans-serif); font-size: var(--hb-fs-xs, 11px); color: var(--hb-danger); }
    .hb-token-saveform__error[hidden] { display: none; }

    .hb-panel-style__scroll { flex: 1 1 auto; min-height: 0; overflow: hidden; position: relative; display: flex; flex-direction: column; }
    .hb-panel-style__grid { display: grid; grid-template-columns: 1fr 1fr; align-content: start; gap: 8px; padding: var(--hb-space-3, 12px); }
    .hb-themepresetcard-wrap { position: relative; }
    .hb-saved-theme-delete { position: absolute; top: 4px; right: 4px; display: inline-flex; align-items: center; justify-content: center; width: 18px; height: 18px; padding: 0; border: 0; border-radius: 999px; background: var(--hb-bg); box-shadow: var(--hb-shadow-sm, 0 1px 3px rgba(0, 0, 0, .25)); color: var(--hb-text-muted); cursor: pointer; opacity: 0; transition: opacity .12s ease; }
    .hb-themepresetcard-wrap:hover .hb-saved-theme-delete, .hb-saved-theme-delete:focus-visible { opacity: 1; }
        .hb-themepresetcard-wrap.hb-saved-theme--active > .hb-themepresetcard { box-shadow: 0 0 0 2px var(--hb-tb-color, #3D68F5); border-color: var(--hb-tb-color, #3D68F5); }

</style>
<script>
    (() => {
        const boot = () => {
            document.querySelectorAll('[data-hb-panel-style]').forEach((root) => {
                if (root.__hbPanelStyle) return;
                root.__hbPanelStyle = { booted: true, activeSavedTheme: null };

                const tabs = root.querySelector('[data-hb-tablist]');
                const style = root.querySelector('[data-hb-panel-style-style]');
                const themes = root.querySelector('[data-hb-panel-style-themes]');
                tabs?.addEventListener('change', (event) => {
                    if (style) style.hidden = event.detail.index !== 0;
                    if (themes) themes.hidden = event.detail.index !== 1;
                });

                const updateUrl = root.dataset.hbThemeUpdateUrl || '';
                const fontsUrl = root.dataset.hbFontsSearchUrl || '';
                const themesStoreUrl = root.dataset.hbThemesStoreUrl || '';
                const themesDestroyUrl = root.dataset.hbThemesDestroyUrl || '';
                const csrf = () => document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
                const inputOf = (field) => field?.matches('input') ? field : field?.querySelector('input');
                let saveTimer = null;

                const collectTheme = () => {
                    const theme = { colors: [], fontSizes: [], spaces: [], radii: [], fonts: [] };
                    style?.querySelectorAll('[data-hb-token-row]').forEach((row) => {
                        const section = row.dataset.hbTokenSection;
                        if (!theme[section]) return;
                        const name = row.dataset.hbTokenName || '';
                        if (section === 'colors') {
                            const label = inputOf(row.querySelector('[data-hb-token-field="label"]'))?.value || '';
                            theme.colors.push({ name, label, value: row.dataset.hbTokenColor || '#000000' });
                        } else if (section === 'fonts') {
                            const family = row.querySelector('[data-hb-token-field="family"]')?.dataset.value || '';
                            let weights = [400];
                            try { weights = JSON.parse(row.dataset.hbTokenWeights || '[400]'); } catch (e) {  }
                            theme.fonts.push({ name, label: family, family, weights });
                        } else {
                            const label = inputOf(row.querySelector('[data-hb-token-field="label"]'))?.value || '';
                            const value = inputOf(row.querySelector('[data-hb-token-field="value"]'))?.value || '';
                            theme[section].push({ name, label, value });
                        }
                    });
                    return theme;
                };

                const saveNow = () => {
                    if (!updateUrl) return;
                    window.fetch(updateUrl, {
                        method: 'PUT',
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'application/json',
                            'X-CSRF-TOKEN': csrf(),
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                        credentials: 'same-origin',
                        body: JSON.stringify(collectTheme()),
                    }).then((res) => res.json().then((body) => ({ ok: res.ok, body }))).then(({ ok, body }) => {
                        if (!ok) {
                            const detail = (body && body.errors && body.errors.join(', ')) || (body && body.message) || 'theme save failed';
                            console.error('[heisenberg] theme save failed:', detail);
                            return;
                        }
                        if (body && body.theme) {
                            applyThemeVars();
                            applyThemeToInspector(body.theme);
                        }
                    }).catch((err) => {
                        console.error('[heisenberg] theme save failed:', err.message || err);
                    });
                };

                const applyThemeVars = () => {
                    const target = document.getElementById('hb-theme-vars');
                    if (!target) return;
                    const theme = collectTheme();
                    const lines = [];
                    ['colors', 'fontSizes', 'spaces', 'radii'].forEach((section) => {
                        (theme[section] || []).forEach((token) => {
                            if (token.name && token.value) lines.push('  --hb-t-' + token.name + ': ' + token.value + ';');
                        });
                    });
                    (theme.fonts || []).forEach((token) => {
                        if (!token.name || !token.family) return;
                        const family = token.family.indexOf(' ') >= 0 ? "'" + token.family + "'" : token.family;
                        lines.push('  --hb-t-' + token.name + ': ' + family + ', sans-serif;');
                    });
                    target.textContent = ':root {\n' + lines.join('\n') + '\n}';
                };

                const scheduleSave = () => {
                    applyThemeVars();
                    clearTimeout(saveTimer);
                    saveTimer = setTimeout(saveNow, 600);
                };

                const buildVarMaps = (theme) => {
                    const labels = {};
                    const values = {};
                    const pushSection = (items, kind) => {
                        (items || []).forEach((token) => {
                            if (!token || !token.name) return;
                            const ref = 'var(--hb-t-' + token.name + ')';
                            const label = token.label || token.name;
                            labels[ref] = label;
                            if (kind === 'color') {
                                values[ref] = token.value || '';
                            } else if (kind === 'font') {
                                values[ref] = '';
                            } else {
                                values[ref] = String(token.value || '').replace(/px$/i, '');
                            }
                        });
                    };
                    pushSection(theme.colors, 'color');
                    pushSection(theme.spaces, 'space');
                    pushSection(theme.radii, 'radius');
                    pushSection(theme.fontSizes, 'size');
                    pushSection(theme.fonts, 'font');
                    return { labels, values };
                };

                const rebuildVarmenuRows = (popupSel, items, kind) => {
                    const popup = document.querySelector(popupSel);
                    const menu = popup?.querySelector('[data-hb-varmenu]');
                    const list = menu?.querySelector('.hb-varmenu__list');
                    if (!menu || !list) return;
                    list.textContent = '';
                    (items || []).forEach((token) => {
                        if (!token || !token.name) return;
                        const label = token.label || token.name;
                        const ref = 'var(--hb-t-' + token.name + ')';
                        const btn = document.createElement('button');
                        btn.type = 'button';
                        btn.className = 'hb-vmi';
                        btn.dataset.vmName = label;
                        btn.dataset.vmValue = ref;
                        const l = document.createElement('span');
                        l.className = 'hb-vmi__l';
                        const check = document.createElement('span');
                        check.className = 'hb-vmi__check';
                        l.appendChild(check);
                        const nameSpan = document.createElement('span');
                        nameSpan.className = 'hb-vmi__name';
                        nameSpan.textContent = label;
                        l.appendChild(nameSpan);
                        btn.appendChild(l);
                        if (kind === 'color') {
                            const sw = document.createElement('span');
                            sw.className = 'hb-vmi__sw';
                            if (token.value) sw.style.background = token.value;
                            else { sw.style.background = 'transparent'; sw.style.boxShadow = 'none'; }
                            btn.appendChild(sw);
                        } else {
                            const valSpan = document.createElement('span');
                            valSpan.className = 'hb-vmi__val';
                            valSpan.textContent = kind === 'font'
                                ? (token.family || '')
                                : String(token.value || '').replace(/px$/i, '');
                            btn.appendChild(valSpan);
                        }
                        list.appendChild(btn);
                    });
                    menu.__hbVm = false;
                };

                const applyThemeToInspector = (theme) => {
                    if (!theme) return;
                    const { labels, values } = buildVarMaps(theme);
                    document.querySelectorAll('.hb-blockstyle').forEach((el) => {
                        el.setAttribute('data-hb-var-labels', JSON.stringify(labels));
                        el.setAttribute('data-hb-var-values', JSON.stringify(values));
                    });
                    rebuildVarmenuRows('[data-hb-style-popup="var-color"] [data-hb-varmenu]', theme.colors, 'color');
                    rebuildVarmenuRows('[data-hb-style-popup="var-number"] [data-hb-varmenu]', theme.spaces, 'number');
                    rebuildVarmenuRows('[data-hb-style-popup="var-font"] [data-hb-varmenu]', theme.fonts, 'font');
                    document.dispatchEvent(new CustomEvent('hb:refresh'));
                    document.dispatchEvent(new CustomEvent('hb:theme-changed', { detail: { theme, labels, values } }));
                };

                const slugFor = (section) => {
                    const prefix = { colors: 'color', fontSizes: 'size', spaces: 'space', radii: 'radius', fonts: 'font' }[section] || 'token';
                    return prefix + '-' + Math.random().toString(36).slice(2, 8);
                };

                const setSwatchColor = (row, hex) => {
                    row.dataset.hbTokenColor = hex;
                    const sw = row.querySelector('[data-hb-token-swatch] .hb-swatch__color');
                    if (sw) sw.style.background = hex;
                };

                const buildRow = (section, token) => {
                    const tpl = style?.querySelector(`template[data-hb-token-template="${section}"]`);
                    if (!tpl) return null;
                    const row = tpl.content.firstElementChild.cloneNode(true);
                    row.dataset.hbTokenName = token.name || slugFor(section);
                    if (section === 'colors') {
                        const label = inputOf(row.querySelector('[data-hb-token-field="label"]'));
                        if (label) label.value = token.label || '';
                        setSwatchColor(row, token.value || '#000000');
                    } else if (section === 'fonts') {
                        const familyRoot = row.querySelector('[data-hb-token-field="family"]');
                        if (familyRoot) {
                            const family = token.family || '';
                            familyRoot.dataset.value = family;
                            const input = familyRoot.querySelector('[data-hb-combobox-input]');
                            if (input) input.value = family;
                        }
                        row.dataset.hbTokenWeights = JSON.stringify(token.weights || [400]);
                    } else {
                        const label = inputOf(row.querySelector('[data-hb-token-field="label"]'));
                        if (label) label.value = token.label || '';
                        const value = inputOf(row.querySelector('[data-hb-token-field="value"]'));
                        if (value) value.value = token.value || '';
                    }
                    return row;
                };

                const applyTheme = (theme) => {
                    ['colors', 'radii', 'spaces', 'fonts', 'fontSizes'].forEach((section) => {
                        const body = style?.querySelector(`[data-hb-token-section-body="${section}"]`);
                        if (!body) return;
                        const addBtn = body.querySelector('[data-hb-token-add]');
                        body.querySelectorAll('[data-hb-token-row]').forEach((row) => row.remove());
                        (theme[section] || []).forEach((token) => {
                            const row = buildRow(section, token);
                            if (row) body.insertBefore(row, addBtn);
                        });
                    });
                    applyThemeVars();
                    document.dispatchEvent(new CustomEvent('hb:refresh'));
                    applyThemeToInspector(theme);
                };

                const addRow = (section) => {
                    const tpl = style?.querySelector(`template[data-hb-token-template="${section}"]`);
                    const sectionBody = style?.querySelector(`[data-hb-token-section-body="${section}"]`);
                    if (!tpl || !sectionBody) return;
                    const row = tpl.content.firstElementChild.cloneNode(true);
                    row.dataset.hbTokenName = slugFor(section);
                    sectionBody.insertBefore(row, sectionBody.lastElementChild);
                    document.dispatchEvent(new CustomEvent('hb:refresh'));
                    const firstField = section === 'fonts'
                        ? row.querySelector('[data-hb-token-field="family"] [data-hb-combobox-input]')
                        : inputOf(row.querySelector('[data-hb-token-field="label"]'));
                    firstField?.focus();
                    scheduleSave();
                };

                const colorPopup = root.querySelector('[data-hb-token-colorpicker-popup]');
                const colorPickerEl = colorPopup?.querySelector('[data-hb-colorpicker]');
                let colorTarget = null;

                const closeColorPopup = () => { if (colorPopup) colorPopup.hidden = true; colorTarget = null; };

                const openColorPopup = (row, trigger) => {
                    if (!colorPopup || !colorPickerEl) return;
                    colorTarget = row;
                    colorPickerEl.__hbCp?.setHex(row.dataset.hbTokenColor || '#000000');
                    colorPopup.hidden = false;
                    const rect = trigger.getBoundingClientRect();
                    const width = colorPopup.offsetWidth || 260;
                    const height = colorPopup.offsetHeight || 320;
                    const left = Math.max(8, Math.min(window.innerWidth - width - 8, rect.right - width));
                    const below = rect.bottom + 8;
                    const top = below + height <= window.innerHeight - 8 ? below : Math.max(8, rect.top - height - 8);
                    colorPopup.style.left = left + 'px';
                    colorPopup.style.top = top + 'px';
                };

                colorPopup?.addEventListener('colorchange', (event) => {
                    if (!colorTarget) return;
                    setSwatchColor(colorTarget, event.detail.hex);
                    scheduleSave();
                });

                const saveBtn = root.querySelector('[data-hb-theme-save-open]');
                const saveForm = root.querySelector('[data-hb-theme-saveform]');
                const saveError = root.querySelector('[data-hb-theme-save-error]');
                const saveNameField = () => inputOf(root.querySelector('[data-hb-theme-save-name]'));

                const showSaveError = (message) => {
                    if (!saveError) return;
                    saveError.textContent = message || '';
                    saveError.hidden = !message;
                };

                const panelStrings = (() => {
                    try { return JSON.parse(root.dataset.hbPanelStyleStrings || '{}'); }
                    catch (e) { return {}; }
                })();
                const formatTemplate = (template, replacements) => String(template || '').replace(/:([a-z_]+)/gi, (m, key) => (
                    Object.prototype.hasOwnProperty.call(replacements, key) ? String(replacements[key]) : m
                ));
                const refreshSaveBarLabel = () => {
                    if (!saveBtn) return;
                    const label = saveBtn.querySelector('.hb-token-add__label');
                    const active = root.__hbPanelStyle?.activeSavedTheme;
                    if (active && active.name) {
                        saveBtn.dataset.hbThemeSaveMode = 'update';
                        if (label) label.textContent = formatTemplate(panelStrings.update_theme, { name: active.name });
                        saveBtn.setAttribute('aria-label', panelStrings.update_theme_aria || active.name);
                    } else {
                        saveBtn.dataset.hbThemeSaveMode = 'new';
                        if (label) label.textContent = panelStrings.save_to_themes || '';
                        saveBtn.setAttribute('aria-label', panelStrings.save_to_themes || '');
                    }
                };

                const openSaveForm = () => {
                    if (!saveForm) return;
                    showSaveError('');
                    const active = root.__hbPanelStyle?.activeSavedTheme;
                    const field = saveNameField();
                    if (field) {
                        if (active && active.name) {
                            field.value = active.name;
                            field.setAttribute('readonly', 'readonly');
                            field.setAttribute('aria-readonly', 'true');
                        } else {
                            field.removeAttribute('readonly');
                            field.removeAttribute('aria-readonly');
                            field.value = '';
                        }
                    }
                    saveForm.hidden = false;
                    if (saveBtn) saveBtn.hidden = true;
                    field?.focus();
                };

                const closeSaveForm = () => {
                    if (!saveForm) return;
                    saveForm.hidden = true;
                    if (saveBtn) saveBtn.hidden = false;
                    const field = saveNameField();
                    if (field) {
                        field.value = '';
                        field.removeAttribute('readonly');
                        field.removeAttribute('aria-readonly');
                    }
                    showSaveError('');
                };

                const confirmSaveTheme = () => {
                    const name = (saveNameField()?.value || '').trim();
                    if (!name) { showSaveError('Name is required'); return; }
                    if (!themesStoreUrl) return;
                    window.fetch(themesStoreUrl, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'application/json',
                            'X-CSRF-TOKEN': csrf(),
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                        credentials: 'same-origin',
                        body: JSON.stringify({ name, theme: collectTheme() }),
                    }).then((res) => res.json().then((body) => ({ ok: res.ok, body })))
                        .then(({ ok, body }) => {
                            if (!ok) { showSaveError((body.errors || []).join(', ') || 'Could not save'); return; }
                            root.__hbPanelStyle.activeSavedTheme = { name };
                            renderSavedThemes(body.themes || []);
                            closeSaveForm();
                            refreshSaveBarLabel();
                        }).catch(() => showSaveError('Could not save'));
                };

                const savedGrid = themes?.querySelector('[data-hb-saved-themes-grid]');
                const savedTpl = themes?.querySelector('template[data-hb-saved-theme-template]');
                const savedEmpty = themes?.querySelector('[data-hb-saved-themes-empty]');

                const renderSavedThemes = (list) => {
                    if (!savedGrid || !savedTpl) return;
                    savedGrid.querySelectorAll('[data-hb-saved-theme]').forEach((el) => el.remove());
                    list.forEach((entry) => {
                        const node = savedTpl.content.firstElementChild.cloneNode(true);
                        node.dataset.hbSavedThemeName = entry.name;
                        node.dataset.hbSavedThemePayload = JSON.stringify(entry.theme);
                        const label = node.querySelector('.hb-themepresetcard__label');
                        if (label) label.textContent = entry.name;
                        const colors = (entry.theme.colors || []).slice(0, 3).map((c) => c.value);
                        node.querySelectorAll('.hb-themepresetcard__swatch').forEach((sw, i) => {
                            sw.style.background = colors[i] || 'transparent';
                        });
                        savedGrid.insertBefore(node, savedEmpty || null);
                    });
                    if (savedEmpty) savedEmpty.hidden = list.length > 0;
                    syncActiveSavedTheme();
                };

                const syncActiveSavedTheme = () => {
                    const activeName = root.__hbPanelStyle?.activeSavedTheme?.name || '';
                    savedGrid?.querySelectorAll('[data-hb-saved-theme]').forEach((card) => {
                        const isActive = !!activeName && card.dataset.hbSavedThemeName === activeName;
                        card.classList.toggle('hb-saved-theme--active', isActive);
                        card.setAttribute('aria-pressed', isActive ? 'true' : 'false');
                    });
                };

                const deleteSavedTheme = (name) => {
                    if (!themesDestroyUrl) return;
                    window.fetch(themesDestroyUrl, {
                        method: 'DELETE',
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'application/json',
                            'X-CSRF-TOKEN': csrf(),
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                        credentials: 'same-origin',
                        body: JSON.stringify({ name }),
                    }).then((res) => res.ok ? res.json() : null)
                        .then((body) => { if (body) renderSavedThemes(body.themes || []); })
                        .catch(() => {  });
                };

                style?.addEventListener('click', (event) => {
                    const add = event.target.closest('[data-hb-token-add]');
                    if (add) { addRow(add.dataset.hbTokenAdd); return; }

                    const remove = event.target.closest('[data-hb-token-remove]');
                    if (remove) {
                        remove.closest('[data-hb-token-row]')?.remove();
                        scheduleSave();
                        return;
                    }

                    const swatch = event.target.closest('[data-hb-token-swatch]');
                    if (swatch) {
                        const row = swatch.closest('[data-hb-token-row]');
                        if (row) openColorPopup(row, swatch);
                        return;
                    }

                    if (event.target.closest('[data-hb-theme-save-open]')) { openSaveForm(); return; }
                    if (event.target.closest('[data-hb-theme-save-cancel]')) { closeSaveForm(); return; }
                    if (event.target.closest('[data-hb-theme-save-confirm]')) { confirmSaveTheme(); return; }
                });

                style?.addEventListener('keydown', (event) => {
                    if (!event.target.closest('[data-hb-theme-save-name]')) return;
                    if (event.key === 'Enter') { event.preventDefault(); confirmSaveTheme(); }
                    else if (event.key === 'Escape') { closeSaveForm(); }
                });

                style?.addEventListener('input', (event) => {
                    if (event.target.closest('[data-hb-theme-save-name]')) return;
                    if (root.__hbPanelStyle.activeSavedTheme) {
                        root.__hbPanelStyle.activeSavedTheme = null;
                        syncActiveSavedTheme();
                        refreshSaveBarLabel();
                    }
                    if (!event.target.closest('[data-hb-token-field="family"]')) scheduleSave();
                });

                const FONT_PAGE_LIMIT = 40;
                let fontTimer = null;
                const searchFonts = (comboboxRoot, query) => {
                    if (!fontsUrl) return;
                    const page = { query, offset: 0, hasMore: true, loading: true };
                    comboboxRoot.__hbFontPage = page;
                    window.fetch(fontsUrl + '?q=' + encodeURIComponent(query) + '&limit=' + FONT_PAGE_LIMIT, { headers: { 'Accept': 'application/json' } })
                        .then((res) => res.ok ? res.json() : { fonts: [], has_more: false })
                        .then((body) => {
                            const list = (body.fonts || []).map((f) => ({ value: f.family, label: f.family }));
                            comboboxRoot.__hbCombobox?.replaceOptions(list);
                            if (comboboxRoot.__hbFontPage !== page) return;
                            page.offset = list.length;
                            page.hasMore = !!body.has_more;
                            page.loading = false;
                        });
                };
                const loadMoreFonts = (comboboxRoot, query) => {
                    const page = comboboxRoot.__hbFontPage;
                    if (!fontsUrl || !page || page.loading || !page.hasMore || page.query !== query) return;
                    page.loading = true;
                    window.fetch(fontsUrl + '?q=' + encodeURIComponent(query) + '&limit=' + FONT_PAGE_LIMIT + '&offset=' + page.offset, { headers: { 'Accept': 'application/json' } })
                        .then((res) => res.ok ? res.json() : { fonts: [], has_more: false })
                        .then((body) => {
                            const list = (body.fonts || []).map((f) => ({ value: f.family, label: f.family }));
                            comboboxRoot.__hbCombobox?.appendOptions(list);
                            page.offset += list.length;
                            page.hasMore = !!body.has_more;
                            page.loading = false;
                        });
                };
                style?.addEventListener('search', (event) => {
                    const comboboxRoot = event.target.closest('[data-hb-token-field="family"]');
                    if (!comboboxRoot) return;
                    clearTimeout(fontTimer);
                    fontTimer = setTimeout(() => searchFonts(comboboxRoot, event.detail?.query || ''), 250);
                });
                style?.addEventListener('loadmore', (event) => {
                    const comboboxRoot = event.target.closest('[data-hb-token-field="family"]');
                    if (!comboboxRoot) return;
                    loadMoreFonts(comboboxRoot, event.detail?.query || '');
                });
                style?.addEventListener('change', (event) => {
                    if (event.target.closest('[data-hb-token-field="family"]')) scheduleSave();
                });

                document.addEventListener('click', (event) => {
                    if (colorPopup && !colorPopup.hidden && !colorPopup.contains(event.target) && !event.target.closest('[data-hb-token-swatch]')) closeColorPopup();
                });
                document.addEventListener('keydown', (event) => {
                    if (event.key !== 'Escape') return;
                    closeColorPopup();
                });

                themes?.addEventListener('click', (event) => {
                    const delBtn = event.target.closest('[data-hb-saved-theme-delete]');
                    if (delBtn) {
                        const wrap = delBtn.closest('[data-hb-saved-theme]');
                        if (wrap?.dataset.hbSavedThemeName) deleteSavedTheme(wrap.dataset.hbSavedThemeName);
                        return;
                    }

                    const savedCard = event.target.closest('[data-hb-saved-theme]');
                    if (savedCard) {
                        let payload = null;
                        try { payload = JSON.parse(savedCard.dataset.hbSavedThemePayload || 'null'); } catch (e) { return; }
                        if (!payload) return;
                        applyTheme(payload);
                        saveNow();
                        root.__hbPanelStyle.activeSavedTheme = { name: savedCard.dataset.hbSavedThemeName || '' };
                        syncActiveSavedTheme();
                        refreshSaveBarLabel();
                        const firstTab = tabs?.querySelectorAll('[data-hb-tab]')[0];
                        if (firstTab) tabs.__hbTablist?.activate(firstTab);
                        return;
                    }

                    const card = event.target.closest('[data-hb-theme-preset]');
                    if (!card) return;
                    let colors = [];
                    try { colors = JSON.parse(card.dataset.hbThemePresetColors || '[]'); } catch (e) { return; }
                    const rows = [...(style?.querySelectorAll('[data-hb-token-row][data-hb-token-section="colors"]') || [])];
                    rows.slice(0, colors.length).forEach((row, i) => setSwatchColor(row, colors[i]));
                    themes.querySelectorAll('[data-hb-theme-preset]').forEach((c) => {
                        c.classList.toggle('hb-themepresetcard--selected', c === card);
                        c.setAttribute('aria-pressed', c === card ? 'true' : 'false');
                    });
                    if (root.__hbPanelStyle.activeSavedTheme) {
                        root.__hbPanelStyle.activeSavedTheme = null;
                        syncActiveSavedTheme();
                        refreshSaveBarLabel();
                    }
                    scheduleSave();
                });

                refreshSaveBarLabel();
            });
        };
        if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot, { once: true });
        else boot();
        document.addEventListener('hb:refresh', boot);
    })();
</script>
@endonce
