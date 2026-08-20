{{-- Script extracted from components/live/panel-style-themes.blade.php when the file crossed
     Livewire's 64KB morph-compile ceiling (see tests/Editor/BladeFileSizeGuardTest). The Style
     tab is the biggest single piece of JS in the panel (token collect/save, var-menu rebuild,
     theme apply, preset/saved-theme click delegation, font search); pulling it into its own
     partial keeps the markup view under the ceiling. Kept as plain JS — the @once directive
     still applies when the partial is @include'd from the parent. --}}
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
    /* Saved theme that the Style tab is currently editing — mirrors the Preset card's
       selected treatment so the user can tell at a glance which entry an "Update :name"
       save would overwrite. */
    .hb-themepresetcard-wrap.hb-saved-theme--active > .hb-themepresetcard { box-shadow: 0 0 0 2px var(--hb-tb-color, #3D68F5); border-color: var(--hb-tb-color, #3D68F5); }

</style>
<script>
    (() => {
        const boot = () => {
            document.querySelectorAll('[data-hb-panel-style]').forEach((root) => {
                if (root.__hbPanelStyle) return;
                // activeSavedTheme holds {name} when the in-DOM theme was loaded by clicking a
                // saved-theme card — the save bar flips to "Update :name" in that case, and
                // the card gets a visual marker. Stays sticky until the user picks a Preset
                // or hand-edits a row to a state that no longer matches the snapshot (in which
                // case the next save is a fresh intent: "create").
                root.__hbPanelStyle = { booted: true, activeSavedTheme: null };

                const tabs = root.querySelector('[data-hb-tablist]');
                const style = root.querySelector('[data-hb-panel-style-style]');
                const themes = root.querySelector('[data-hb-panel-style-themes]');
                tabs?.addEventListener('change', (event) => {
                    if (style) style.hidden = event.detail.index !== 0;
                    if (themes) themes.hidden = event.detail.index !== 1;
                });

                // ── Theme token editor (Style tab) ─────────────────────────────
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
                            // No separate name/label field for fonts — the family name IS the
                            // display label (it's already human-readable, e.g. "Rubik"), so a
                            // second free-text nickname was pure redundancy. ui/combobox's value
                            // lives in data-value on its own root, not an <input>.
                            const family = row.querySelector('[data-hb-token-field="family"]')?.dataset.value || '';
                            let weights = [400];
                            try { weights = JSON.parse(row.dataset.hbTokenWeights || '[400]'); } catch (e) { /* keep default */ }
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
                        // The PUT response carries the freshly-persisted theme (ThemeController::update
                        // returns { saved, theme, css, tokens }). Drive the inspector off it so a hand
                        // edit also refreshes data-hb-var-labels / the variable-menu rows without a reload.
                        if (body && body.theme) {
                            applyThemeVars();
                            applyThemeToInspector(body.theme);
                        }
                    }).catch((err) => {
                        console.error('[heisenberg] theme save failed:', err.message || err);
                    });
                };

                // Repaint the page's `--hb-t-*` custom properties from the in-DOM theme. Mirrors
                // ThemeRepository::css()'s CSS_PREFIX and its `family, sans-serif` quoting for
                // fonts, so the live preview and the saved render agree.
                //
                // Without this the panel could edit and PUT a token that nothing on the page then
                // used: the editor emits #hb-theme-vars once at render time, so a change was
                // invisible until reload — and any block bound to that token appeared not to
                // respond at all. Applied immediately, not on the debounce, so dragging a colour
                // reads as live.
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

                // Mirror the reverse-map computation in resources/views/components/live/block/
                // style-panel.blade.php:90-103: every CSS reference (`var(--hb-t-…)`) maps to
                // its display label and (for numeric tokens) to its bare integer value, so a
                // bound field reads as "16" rather than "sp-3".
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
                                values[ref] = ''; // fonts have no resolvable number — callers fall back to the label
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

                // Rebuild the row contents of one variable-menu popup from a theme section.
                // Clear and replace the .hb-varmenu__list children; the menu itself (and any
                // search input) is left alone. variable-menu's boot() re-binds click handlers
                // via the hb:refresh it listens for, so the new rows are interactive.
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
                    menu.__hbVm = false; // force variable-menu's boot() to rebind click handlers on the new rows
                };

                // Reflect `theme` into the inspector: rewrite data-hb-var-labels / data-hb-var-values
                // on every .hb-blockstyle, rebuild the three var-* popups' rows, and dispatch
                // hb:theme-changed with the payload. Used both after applying a saved theme and
                // after a successful PUT /editor/theme (hand edits), so the inspector never lags
                // behind the active theme.
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
                    // Re-bind click handlers for the freshly-rebuilt rows.
                    document.dispatchEvent(new CustomEvent('hb:refresh'));
                    document.dispatchEvent(new CustomEvent('hb:theme-changed', { detail: { theme, labels, values } }));
                };

                // Slugs only ever need to be unique + kebab-case; they're never shown to the user.
                const slugFor = (section) => {
                    const prefix = { colors: 'color', fontSizes: 'size', spaces: 'space', radii: 'radius', fonts: 'font' }[section] || 'token';
                    return prefix + '-' + Math.random().toString(36).slice(2, 8);
                };

                const setSwatchColor = (row, hex) => {
                    row.dataset.hbTokenColor = hex;
                    const sw = row.querySelector('[data-hb-token-swatch] .hb-swatch__color');
                    if (sw) sw.style.background = hex;
                };

                // Builds one token row from {name,label,value|family/weights} — the counterpart to
                // collectTheme()'s per-row reads. Used by applyTheme() (saved-theme apply) below.
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
                        // No label field to fill in for fonts — the family name is the label
                        // (see collectTheme()). A real <input> (ui/combobox), so this works even
                        // before hb:refresh boots the clone — applyTheme() below still dispatches
                        // it once afterward, which is what makes the dropdown itself
                        // openable/searchable again.
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

                // Fully replaces every section's rows with `theme`'s own — used when applying a
                // saved theme (which, unlike a curated preset, has real data for all 5 sections).
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
                    // Repaint the page's --hb-t-* custom properties from the in-DOM theme now
                    // (applyTheme is paired with saveNow in the saved-card click handler, but
                    // saveNow's PUT doesn't itself trigger applyThemeVars — so the canvas would
                    // stay stale until the next Style-tab interaction).
                    applyThemeVars();
                    // Newly-cloned rows carry markup for components with their own boot script (the
                    // Fonts field's ui/select) that only runs on DOMContentLoaded/hb:refresh — without
                    // this, a font row from an applied theme would show the right value but never open.
                    document.dispatchEvent(new CustomEvent('hb:refresh'));
                    applyThemeToInspector(theme);
                };

                const addRow = (section) => {
                    const tpl = style?.querySelector(`template[data-hb-token-template="${section}"]`);
                    const sectionBody = style?.querySelector(`[data-hb-token-section-body="${section}"]`);
                    if (!tpl || !sectionBody) return;
                    const row = tpl.content.firstElementChild.cloneNode(true);
                    row.dataset.hbTokenName = slugFor(section);
                    sectionBody.insertBefore(row, sectionBody.lastElementChild); // keep the "+ Add" button last
                    document.dispatchEvent(new CustomEvent('hb:refresh')); // boots a fresh Fonts ui/combobox, if this is one
                    // Fonts has no label field — focus the one field there is to fill in.
                    const firstField = section === 'fonts'
                        ? row.querySelector('[data-hb-token-field="family"] [data-hb-combobox-input]')
                        : inputOf(row.querySelector('[data-hb-token-field="label"]'));
                    firstField?.focus();
                    scheduleSave();
                };

                // ── Colour popup (Colors section) — mounts the app's own live/pickers/color-picker,
                // the same component the per-block Fill/Stroke panels use, instead of a native input.
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

                // ── Save to Themes (Style tab footer) ───────────────────────────
                const saveBtn = root.querySelector('[data-hb-theme-save-open]');
                const saveForm = root.querySelector('[data-hb-theme-saveform]');
                const saveError = root.querySelector('[data-hb-theme-save-error]');
                const saveNameField = () => inputOf(root.querySelector('[data-hb-theme-save-name]'));

                const showSaveError = (message) => {
                    if (!saveError) return;
                    saveError.textContent = message || '';
                    saveError.hidden = !message;
                };

                // The save bar's trigger label and aria swap between "Save to Themes" (no active
                // saved theme) and "Update :name" (activeSavedTheme set). Done via a hidden
                // data-hb-panel-style-strings JSON blob (see the markup at the bottom of the file
                // and style-panel's data-hb-nav-strings pattern for the convention).
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
                    // In update mode, prefill and lock the name to the active saved theme —
                    // the backend upserts on case-insensitive name match, so the user can never
                    // accidentally create a new entry under a different name.
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
                        // The CURRENT in-DOM theme, not whatever's last been written to disk by the
                        // debounced autosave above — an in-flight, not-yet-saved edit must never be
                        // silently dropped from the snapshot.
                        body: JSON.stringify({ name, theme: collectTheme() }),
                    }).then((res) => res.json().then((body) => ({ ok: res.ok, body })))
                        .then(({ ok, body }) => {
                            if (!ok) { showSaveError((body.errors || []).join(', ') || 'Could not save'); return; }
                            // The server returns the full saved list, including any prior entry of the
                            // same name that was upserted. Mark this name as the active saved theme so
                            // the bar shows "Update :name" on the next open and the matching card gets
                            // the active marker.
                            root.__hbPanelStyle.activeSavedTheme = { name };
                            renderSavedThemes(body.themes || []);
                            closeSaveForm();
                            refreshSaveBarLabel();
                        }).catch(() => showSaveError('Could not save'));
                };

                // ── Saved themes grid (Themes tab) ──────────────────────────────
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
                    // Re-apply the active marker for the card that matches root.__hbPanelStyle
                    // .activeSavedTheme — the grid was just wiped, so without this the visual
                    // marker would silently disappear after a save or delete.
                    syncActiveSavedTheme();
                };

                // Mark the saved-theme card whose name matches activeSavedTheme, clear the
                // marker from every other card. Called after every grid render AND after a
                // card click sets/changes activeSavedTheme.
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
                        .catch(() => { /* the card simply stays put; nothing was silently lost */ });
                };

                // ── Click delegation (Style tab: rows, add, swatch, save-to-themes) ─
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
                    if (event.target.closest('[data-hb-theme-save-name]')) return; // not a token field
                    // A hand edit means the in-DOM theme no longer matches the snapshot the active
                    // saved theme was loaded from — the next save is a fresh "create" intent, so
                    // clear activeSavedTheme and update the bar label.
                    if (root.__hbPanelStyle.activeSavedTheme) {
                        root.__hbPanelStyle.activeSavedTheme = null;
                        syncActiveSavedTheme();
                        refreshSaveBarLabel();
                    }
                    if (!event.target.closest('[data-hb-token-field="family"]')) scheduleSave();
                });

                // ── Font family search (Fonts section only) — the field itself is ui/combobox; this
                // just answers the `search` event it dispatches by fetching the vendored catalog and
                // handing the results back via its own replaceOptions() API. No bespoke dropdown
                // widget, no separate open/close/positioning logic to maintain. Pagination: the
                // catalog has ~1942 entries but a page is only FONT_PAGE_LIMIT, so scrolling near the
                // bottom (combobox's own `loadmore` event) fetches the next page via appendOptions()
                // rather than the picker silently topping out at page 1 until the query changes.
                // Page state lives on the comboboxRoot itself (not a shared var) since a style panel
                // can have several font rows, each with its own independent combobox + scroll state.
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
                            if (comboboxRoot.__hbFontPage !== page) return; // a newer search superseded this one
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

                // ── Presets + saved themes (Themes tab) ─────────────────────────
                themes?.addEventListener('click', (event) => {
                    const delBtn = event.target.closest('[data-hb-saved-theme-delete]');
                    if (delBtn) {
                        const wrap = delBtn.closest('[data-hb-saved-theme]');
                        if (wrap?.dataset.hbSavedThemeName) deleteSavedTheme(wrap.dataset.hbSavedThemeName);
                        return;
                    }

                    // A saved theme has real data for every section, so applying it replaces all 5 —
                    // unlike a curated preset (colors only, see below) — then switches back to Style
                    // so the result is immediately visible.
                    const savedCard = event.target.closest('[data-hb-saved-theme]');
                    if (savedCard) {
                        let payload = null;
                        try { payload = JSON.parse(savedCard.dataset.hbSavedThemePayload || 'null'); } catch (e) { return; }
                        if (!payload) return;
                        applyTheme(payload);
                        saveNow();
                        // Mark this card as the active saved theme — the save bar flips to
                        // "Update :name" and confirms POST under the same name (the backend
                        // upserts in place via case-insensitive dedupe).
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
                    // Picking a Preset is a fresh "create" intent — clear any active saved theme
                    // so the save bar falls back to "Save to Themes" until the user reapplies a
                    // saved theme or hand-edits a token.
                    if (root.__hbPanelStyle.activeSavedTheme) {
                        root.__hbPanelStyle.activeSavedTheme = null;
                        syncActiveSavedTheme();
                        refreshSaveBarLabel();
                    }
                    scheduleSave();
                });

                // Initial label so the bar reads "Save to Themes" on first paint; flips to
                // "Update :name" the moment a saved theme card is clicked.
                refreshSaveBarLabel();
            });
        };
        if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot, { once: true });
        else boot();
        document.addEventListener('hb:refresh', boot);
    })();
</script>
@endonce
