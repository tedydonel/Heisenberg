{{-- live/panel-style-themes — Middle panel, 240px (same
     240-vs-260 note as live/panel-components-blocks).
     Style tab: 5 token-editor sections (Colors/Radius/Spacing/Fonts/Font sizes), each a real repeated
     row pattern from the source — Colors uses ui/swatch + ui/input (name only); Radius/Spacing/Font
     sizes use two ui/input fields (name+value) + a remove icon. Fonts is the one exception to that
     name+value shape (2026-08-04): a font's family (e.g. "Rubik") is already a human-readable name,
     so a second free-text nickname field was pure redundancy — it's just ui/combobox (family) alone,
     and the family value doubles as the token's label (see collectTheme()/buildRow() below). Widths
     differ per section in the source itself (not my choice) — Radius/Fonts/Font-sizes: name=70,
     value=fill; Spacing: name=fill, value=80. Font sizes is the last section and has no border-bottom
     + a wider padding (space-4 not space-3) in the source — kept as-is, not treated as a copy-paste
     oversight. A "Save to Themes" bar follows it (our own addition, not in the design).
     Rows are real ThemeRepository data — every field reads from and writes back to
     GET/PUT /editor/theme (ThemeController). The single visible text field
     per row (Fonts excepted) edits the token's human `label`; its machine `name` (the `--hb-t-*` CSS
     variable, kebab-case, must stay stable so nothing referencing it silently breaks) rides along in
     data-hb-token-name and is generated once, at Add time. Colors' swatch opens the app's OWN color
     picker (live/pickers/color-picker, the same component the per-block Fill/Stroke panels use) in a
     floating popup — not a native <input type=color> — so editing a theme colour looks and behaves
     like editing any other colour in the editor; the token's current hex rides in data-hb-token-color
     on the row (the picker only ever hands back a flat hex via its `colorchange` event, even if its
     Gradient tab is poked, so a theme token can never accidentally become an actual gradient string).
     Fonts' family field is ui/combobox (2026-08-03, a search-first sibling of ui/select — see its own
     docblock for why it's a separate component): typing dispatches a `search` event this file listens
     for, fetches GET /editor/fonts (FontController::search, the vendored Google Fonts catalog, not a
     static list), and calls the combobox's own replaceOptions() — no bespoke dropdown widget. Any edit
     (typing, add, remove, swatch, font pick, preset/saved-theme apply) schedules one debounced PUT of
     the whole theme; ThemeRepository re-validates everything server-side regardless of client-side
     care. "Save to Themes" snapshots the CURRENT in-DOM theme (not the last-saved-to-disk one, so an
     unsaved edit is never silently dropped) under a user-typed name via POST /editor/themes
     (SavedThemeController) — distinct from the single active theme PUT above.
     Themes tab: search field pinned above one shared scroll region holding two card grids — "Your
     themes" (live, user-saved, 2026-08-03: server-seeded from SavedThemeRepository, refreshed
     client-side after every save/delete, each card deletable) above the original "Presets" (search +
     "Presets" category head + a 6-card grid, the source's actual named palettes: Default/Midnight/
     Sunset/Ocean/Forest/Blush). Clicking a PRESET overwrites the first 3 color rows' values (a preset
     only ever ships 3 colors) — it doesn't invent extra semantic roles the source palettes don't
     define. Clicking a SAVED theme fully replaces all 5 token sections (it has real data for all of
     them) and switches back to the Style tab so the result is visible, then saves as the active theme. --}}
@once
<style>
    .hb-panel-style { display: flex; flex-direction: column; width: 240px; height: 100%; background: var(--hb-bg, #fff); border-right: 1px solid var(--hb-border, #E4E4E4); flex: none; }
    .hb-panel-style__content { display: flex; flex-direction: column; flex: 1 1 auto; min-height: 0; overflow: hidden; position: relative; }
    .hb-panel-style__content[hidden] { display: none; }

    .hb-token-section { display: flex; flex-direction: column; gap: var(--hb-space-3, 12px); padding: var(--hb-space-3, 12px); border-bottom: 1px solid var(--hb-border, #E4E4E4); flex: none; }
    .hb-token-section--last { padding: var(--hb-space-4, 16px); border-bottom: 0; }
    .hb-token-section__title { font-family: var(--hb-font-sans, Rubik, sans-serif); font-size: var(--hb-fs-lg, 16px); font-weight: 500; color: var(--hb-text-primary, #0A0A0A); }
    .hb-token-row { display: flex; align-items: center; gap: var(--hb-space-2, 8px); width: 100%; }
    .hb-token-row__remove { display: inline-flex; width: 14px; height: 14px; color: var(--hb-text-muted, #9A9A9A); flex: none; cursor: pointer; }
    .hb-token-add { display: inline-flex; align-items: center; gap: var(--hb-space-2, 8px); border: 0; background: transparent; cursor: pointer; padding: 0; }
    .hb-token-add__icon { display: inline-flex; width: 14px; height: 14px; color: var(--hb-text-muted, #9A9A9A); }
    .hb-token-add__label { font-family: var(--hb-font-sans, Rubik, sans-serif); font-size: var(--hb-fs-sm, 12px); font-weight: 500; color: var(--hb-text-muted, #9A9A9A); }

    .hb-token-savebar { padding: var(--hb-space-3, 12px); border-top: 1px solid var(--hb-border, #E4E4E4); display: flex; flex-direction: column; gap: 8px; flex: none; }
    .hb-token-savebar[hidden] { display: none; }
    .hb-token-saveform { display: flex; align-items: center; gap: 6px; }
    .hb-token-saveform[hidden] { display: none; }
    .hb-token-saveform__confirm, .hb-token-saveform__cancel { display: inline-flex; align-items: center; justify-content: center; width: 26px; height: 26px; padding: 0; border: 1px solid var(--hb-border, #E4E4E4); border-radius: var(--hb-radius-md, 5px); background: var(--hb-bg, #fff); color: var(--hb-text-muted, #9A9A9A); cursor: pointer; flex: none; }
    .hb-token-saveform__confirm:hover, .hb-token-saveform__cancel:hover { border-color: var(--hb-border-strong, #C8C8C8); }
    .hb-token-saveform__error { font-family: var(--hb-font-sans, Rubik, sans-serif); font-size: var(--hb-fs-xs, 11px); color: var(--hb-danger, #D4191A); }
    .hb-token-saveform__error[hidden] { display: none; }

    .hb-panel-style__scroll { flex: 1 1 auto; min-height: 0; overflow: hidden; position: relative; display: flex; flex-direction: column; }
    .hb-panel-style__grid { display: grid; grid-template-columns: 1fr 1fr; align-content: start; gap: 8px; padding: var(--hb-space-3, 12px); }
    .hb-themepresetcard-wrap { position: relative; }
    .hb-saved-theme-delete { position: absolute; top: 4px; right: 4px; display: inline-flex; align-items: center; justify-content: center; width: 18px; height: 18px; padding: 0; border: 0; border-radius: 999px; background: var(--hb-bg, #fff); box-shadow: var(--hb-shadow-sm, 0 1px 3px rgba(0, 0, 0, .25)); color: var(--hb-text-muted, #9A9A9A); cursor: pointer; opacity: 0; transition: opacity .12s ease; }
    .hb-themepresetcard-wrap:hover .hb-saved-theme-delete, .hb-saved-theme-delete:focus-visible { opacity: 1; }

</style>
<script>
    (() => {
        const boot = () => {
            document.querySelectorAll('[data-hb-panel-style]').forEach((root) => {
                if (root.__hbPanelStyle) return;
                root.__hbPanelStyle = true;

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
                    }).then((res) => {
                        if (!res.ok) return res.json().then((body) => { throw new Error((body && body.errors || []).join(', ') || res.statusText); });
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
                    // Newly-cloned rows carry markup for components with their own boot script (the
                    // Fonts field's ui/select) that only runs on DOMContentLoaded/hb:refresh — without
                    // this, a font row from an applied theme would show the right value but never open.
                    document.dispatchEvent(new CustomEvent('hb:refresh'));
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

                const openSaveForm = () => {
                    if (!saveForm) return;
                    showSaveError('');
                    saveForm.hidden = false;
                    if (saveBtn) saveBtn.hidden = true;
                    saveNameField()?.focus();
                };

                const closeSaveForm = () => {
                    if (!saveForm) return;
                    saveForm.hidden = true;
                    if (saveBtn) saveBtn.hidden = false;
                    const field = saveNameField();
                    if (field) field.value = '';
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
                            renderSavedThemes(body.themes || []);
                            closeSaveForm();
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
                    scheduleSave();
                });
            });
        };
        if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot, { once: true });
        else boot();
        document.addEventListener('hb:refresh', boot);
    })();
</script>
@endonce

@props([
    // ThemeRepository::load()'s shape — colors/fontSizes/spaces/radii/fonts, each a list of
    // {name,label,value} (fonts also carry family/weights). Real saved data, not fixtures
    // (2026-08-03) — see EditorController::sharedViewData().
    'theme' => [],
    // SavedThemeRepository::all()'s shape — list of {name, theme} (2026-08-03).
    'savedThemes' => [],
    'themeUpdateUrl' => '',
    'fontsSearchUrl' => '',
    'themesStoreUrl' => '',
    'themesDestroyUrl' => '',
    // FontCatalogService's popular-head, as [{value,label}] — seeds the Fonts field's ui/select so
    // it never opens empty before a keystroke (2026-08-03).
    'fontOptions' => [],
])

@php
    $colors = $theme['colors'] ?? [];
    $radii = $theme['radii'] ?? [];
    $spacings = $theme['spaces'] ?? [];
    $fonts = $theme['fonts'] ?? [];
    $fontSizes = $theme['fontSizes'] ?? [];
    $themePresets = [
        ['label' => 'Default', 'colors' => ['#FFFFFF', '#000000', '#0A0A0A']],
        ['label' => 'Midnight', 'colors' => ['#12141C', '#5B8DEF', '#E8EAF0']],
        ['label' => 'Sunset', 'colors' => ['#FFF6ED', '#E8703A', '#3A2A1E']],
        ['label' => 'Ocean', 'colors' => ['#EFF6FB', '#1AA7A0', '#12324A']],
        ['label' => 'Forest', 'colors' => ['#F1F5EE', '#5B8C3E', '#23331F']],
        ['label' => 'Blush', 'colors' => ['#FDF1F4', '#D65F86', '#401A2B']],
    ];
@endphp
<div data-hb-panel-style
    data-hb-theme-update-url="{{ $themeUpdateUrl }}"
    data-hb-fonts-search-url="{{ $fontsSearchUrl }}"
    data-hb-themes-store-url="{{ $themesStoreUrl }}"
    data-hb-themes-destroy-url="{{ $themesDestroyUrl }}"
    {{ $attributes->merge(['class' => 'hb-panel-style']) }}>
    <x-ui.panel-tabs :items="[['label' => __('heisenberg::editor.panel_style_themes.tab_style')], ['label' => __('heisenberg::editor.panel_style_themes.tab_themes')]]" :active-index="0" />

    <div class="hb-panel-style__content" data-hb-panel-style-style>
        {{-- Two-layer scroll shell (same reasoning as the Themes tab's own [data-hb-themes-scroll]
             below, and live/panel-components-blocks.blade.php's [data-hb-panel-cb-scroll]): the
             scrollbar's `container` must be an element that is NOT also the scrollbar bar's own
             direct parent-for-positioning-purposes conflated with content — this inner wrapper is
             purely the JS-tracked scroll region; the outer [data-hb-panel-style-style] is just the
             flex slot + position:relative anchor the scrollbar bar itself sits in. --}}
        <div class="hb-panel-style__scroll" data-hb-style-scroll>
        <div class="hb-token-section" data-hb-token-section-body="colors">
            <span class="hb-token-section__title">{{ __('heisenberg::editor.panel_style_themes.token_colors') }}</span>
            @foreach ($colors as $c)
                <div class="hb-token-row" data-hb-token-row data-hb-token-section="colors" data-hb-token-name="{{ $c['name'] }}" data-hb-token-color="{{ $c['value'] }}">
                    <x-ui.swatch :color="$c['value']" size="26" data-hb-token-swatch />
                    <x-ui.input :value="$c['label']" width="100%" data-hb-token-field="label" />
                    <span class="hb-token-row__remove" data-hb-token-remove aria-hidden="true">@include('heisenberg::components.ui.icon', ['name' => 'x', 'size' => 14])</span>
                </div>
            @endforeach
            <button type="button" class="hb-token-add" data-hb-token-add="colors">
                <span class="hb-token-add__icon" aria-hidden="true">@include('heisenberg::components.ui.icon', ['name' => 'plus', 'size' => 14])</span>
                <span class="hb-token-add__label">{{ __('heisenberg::editor.panel_style_themes.add_color') }}</span>
            </button>
        </div>
        <template data-hb-token-template="colors">
            <div class="hb-token-row" data-hb-token-row data-hb-token-section="colors" data-hb-token-name="" data-hb-token-color="#000000">
                <x-ui.swatch color="#000000" size="26" data-hb-token-swatch />
                <x-ui.input value="" width="100%" data-hb-token-field="label" />
                <span class="hb-token-row__remove" data-hb-token-remove aria-hidden="true">@include('heisenberg::components.ui.icon', ['name' => 'x', 'size' => 14])</span>
            </div>
        </template>

        <div class="hb-token-section" data-hb-token-section-body="radii">
            <span class="hb-token-section__title">{{ __('heisenberg::editor.panel_style_themes.token_radius') }}</span>
            @foreach ($radii as $r)
                <div class="hb-token-row" data-hb-token-row data-hb-token-section="radii" data-hb-token-name="{{ $r['name'] }}">
                    <x-ui.input :value="$r['label']" width="70px" data-hb-token-field="label" />
                    <x-ui.input :value="$r['value']" width="100%" data-hb-token-field="value" />
                    <span class="hb-token-row__remove" data-hb-token-remove aria-hidden="true">@include('heisenberg::components.ui.icon', ['name' => 'x', 'size' => 14])</span>
                </div>
            @endforeach
            <button type="button" class="hb-token-add" data-hb-token-add="radii">
                <span class="hb-token-add__icon" aria-hidden="true">@include('heisenberg::components.ui.icon', ['name' => 'plus', 'size' => 14])</span>
                <span class="hb-token-add__label">{{ __('heisenberg::editor.panel_style_themes.add_radius') }}</span>
            </button>
        </div>
        <template data-hb-token-template="radii">
            <div class="hb-token-row" data-hb-token-row data-hb-token-section="radii" data-hb-token-name="">
                <x-ui.input value="" width="70px" data-hb-token-field="label" />
                <x-ui.input value="" width="100%" data-hb-token-field="value" />
                <span class="hb-token-row__remove" data-hb-token-remove aria-hidden="true">@include('heisenberg::components.ui.icon', ['name' => 'x', 'size' => 14])</span>
            </div>
        </template>

        <div class="hb-token-section" data-hb-token-section-body="spaces">
            <span class="hb-token-section__title">{{ __('heisenberg::editor.panel_style_themes.token_spacing') }}</span>
            @foreach ($spacings as $s)
                <div class="hb-token-row" data-hb-token-row data-hb-token-section="spaces" data-hb-token-name="{{ $s['name'] }}">
                    <x-ui.input :value="$s['label']" width="100%" data-hb-token-field="label" />
                    <x-ui.input :value="$s['value']" width="80px" data-hb-token-field="value" />
                    <span class="hb-token-row__remove" data-hb-token-remove aria-hidden="true">@include('heisenberg::components.ui.icon', ['name' => 'x', 'size' => 14])</span>
                </div>
            @endforeach
            <button type="button" class="hb-token-add" data-hb-token-add="spaces">
                <span class="hb-token-add__icon" aria-hidden="true">@include('heisenberg::components.ui.icon', ['name' => 'plus', 'size' => 14])</span>
                <span class="hb-token-add__label">{{ __('heisenberg::editor.panel_style_themes.add_spacing') }}</span>
            </button>
        </div>
        <template data-hb-token-template="spaces">
            <div class="hb-token-row" data-hb-token-row data-hb-token-section="spaces" data-hb-token-name="">
                <x-ui.input value="" width="100%" data-hb-token-field="label" />
                <x-ui.input value="" width="80px" data-hb-token-field="value" />
                <span class="hb-token-row__remove" data-hb-token-remove aria-hidden="true">@include('heisenberg::components.ui.icon', ['name' => 'x', 'size' => 14])</span>
            </div>
        </template>

        <div class="hb-token-section" data-hb-token-section-body="fonts">
            <span class="hb-token-section__title">{{ __('heisenberg::editor.panel_style_themes.token_fonts') }}</span>
            @foreach ($fonts as $f)
                <div class="hb-token-row" data-hb-token-row data-hb-token-section="fonts" data-hb-token-name="{{ $f['name'] }}" data-hb-token-weights="{{ json_encode($f['weights'] ?? [400]) }}">
                    <x-ui.combobox :options="$fontOptions" :value="$f['family']"
                        :placeholder="__('heisenberg::editor.panel_style_themes.select_font_ph')"
                        style="width:100%;" data-hb-token-field="family" />
                    <span class="hb-token-row__remove" data-hb-token-remove aria-hidden="true">@include('heisenberg::components.ui.icon', ['name' => 'x', 'size' => 14])</span>
                </div>
            @endforeach
            <button type="button" class="hb-token-add" data-hb-token-add="fonts">
                <span class="hb-token-add__icon" aria-hidden="true">@include('heisenberg::components.ui.icon', ['name' => 'plus', 'size' => 14])</span>
                <span class="hb-token-add__label">{{ __('heisenberg::editor.panel_style_themes.add_font') }}</span>
            </button>
        </div>
        <template data-hb-token-template="fonts">
            <div class="hb-token-row" data-hb-token-row data-hb-token-section="fonts" data-hb-token-name="" data-hb-token-weights="[400]">
                <x-ui.combobox :options="$fontOptions" value=""
                    :placeholder="__('heisenberg::editor.panel_style_themes.select_font_ph')"
                    style="width:100%;" data-hb-token-field="family" />
                <span class="hb-token-row__remove" data-hb-token-remove aria-hidden="true">@include('heisenberg::components.ui.icon', ['name' => 'x', 'size' => 14])</span>
            </div>
        </template>

        <div class="hb-token-section hb-token-section--last" data-hb-token-section-body="fontSizes">
            <span class="hb-token-section__title">{{ __('heisenberg::editor.panel_style_themes.token_font_sizes') }}</span>
            @foreach ($fontSizes as $fs)
                <div class="hb-token-row" data-hb-token-row data-hb-token-section="fontSizes" data-hb-token-name="{{ $fs['name'] }}">
                    <x-ui.input :value="$fs['label']" width="70px" data-hb-token-field="label" />
                    <x-ui.input :value="$fs['value']" width="100%" data-hb-token-field="value" />
                    <span class="hb-token-row__remove" data-hb-token-remove aria-hidden="true">@include('heisenberg::components.ui.icon', ['name' => 'x', 'size' => 14])</span>
                </div>
            @endforeach
            <button type="button" class="hb-token-add" data-hb-token-add="fontSizes">
                <span class="hb-token-add__icon" aria-hidden="true">@include('heisenberg::components.ui.icon', ['name' => 'plus', 'size' => 14])</span>
                <span class="hb-token-add__label">{{ __('heisenberg::editor.panel_style_themes.add_size') }}</span>
            </button>
        </div>
        <template data-hb-token-template="fontSizes">
            <div class="hb-token-row" data-hb-token-row data-hb-token-section="fontSizes" data-hb-token-name="">
                <x-ui.input value="" width="70px" data-hb-token-field="label" />
                <x-ui.input value="" width="100%" data-hb-token-field="value" />
                <span class="hb-token-row__remove" data-hb-token-remove aria-hidden="true">@include('heisenberg::components.ui.icon', ['name' => 'x', 'size' => 14])</span>
            </div>
        </template>

        <div class="hb-token-savebar" data-hb-theme-savebar>
            <button type="button" class="hb-token-add" data-hb-theme-save-open>
                <span class="hb-token-add__icon" aria-hidden="true">@include('heisenberg::components.ui.icon', ['name' => 'bookmark', 'size' => 14])</span>
                <span class="hb-token-add__label">{{ __('heisenberg::editor.panel_style_themes.save_to_themes') }}</span>
            </button>
            <div class="hb-token-saveform" data-hb-theme-saveform hidden>
                <x-ui.input placeholder="{{ __('heisenberg::editor.panel_style_themes.save_theme_name_ph') }}" width="100%" data-hb-theme-save-name />
                <button type="button" class="hb-token-saveform__confirm" data-hb-theme-save-confirm aria-label="{{ __('heisenberg::editor.panel_style_themes.save_theme_confirm_aria') }}">
                    @include('heisenberg::components.ui.icon', ['name' => 'check', 'size' => 13])
                </button>
                <button type="button" class="hb-token-saveform__cancel" data-hb-theme-save-cancel aria-label="{{ __('heisenberg::editor.common.cancel') }}">
                    @include('heisenberg::components.ui.icon', ['name' => 'x', 'size' => 13])
                </button>
            </div>
            <span class="hb-token-saveform__error" data-hb-theme-save-error hidden></span>
        </div>
        </div>
        <x-ui.custom-scrollbar container="[data-hb-style-scroll]" />
    </div>

    <div class="hb-panel-style__content" data-hb-panel-style-themes hidden>
        <x-ui.search-field :placeholder="__('heisenberg::editor.panel_style_themes.search_themes')" />

        <div class="hb-panel-style__scroll" data-hb-themes-scroll>
            <x-ui.category-head :label="__('heisenberg::editor.panel_style_themes.category_your_themes')" />
            <div class="hb-panel-style__grid" data-hb-saved-themes-grid>
                @foreach ($savedThemes as $saved)
                    <div class="hb-themepresetcard-wrap" data-hb-saved-theme data-hb-saved-theme-name="{{ $saved['name'] }}" data-hb-saved-theme-payload="{{ json_encode($saved['theme']) }}">
                        <x-ui.theme-preset-card :colors="array_slice(array_column($saved['theme']['colors'] ?? [], 'value'), 0, 3)" :label="$saved['name']" />
                        <button type="button" class="hb-saved-theme-delete" data-hb-saved-theme-delete aria-label="{{ __('heisenberg::editor.panel_style_themes.delete_theme_aria') }}">
                            @include('heisenberg::components.ui.icon', ['name' => 'x', 'size' => 12])
                        </button>
                    </div>
                @endforeach
                <span class="hb-token-add__label" data-hb-saved-themes-empty @if (count($savedThemes)) hidden @endif>{{ __('heisenberg::editor.panel_style_themes.no_saved_themes') }}</span>
            </div>

            <x-ui.category-head :label="__('heisenberg::editor.panel_style_themes.category_presets')" />
            <div class="hb-panel-style__grid">
                @foreach ($themePresets as $preset)
                    <x-ui.theme-preset-card :colors="$preset['colors']" :label="$preset['label']"
                        data-hb-theme-preset :data-hb-theme-preset-colors="json_encode($preset['colors'])" />
                @endforeach
            </div>

            {{-- Deliberately AFTER the unconditional Presets loop above, not before it: content
                 inside <template> is inert (browsers never apply styles/scripts from it), so if
                 ui/theme-preset-card's own @once <style> block fired for the FIRST time in here —
                 which it would, whenever $savedThemes is empty, since this would otherwise be the
                 first instance of the component on the page — that stylesheet would never actually
                 reach the live document and every real card (Presets included) would render
                 unstyled. Presets is never empty, so putting it first guarantees @once always fires
                 from a real, rendered card. --}}
            <template data-hb-saved-theme-template>
                <div class="hb-themepresetcard-wrap" data-hb-saved-theme data-hb-saved-theme-name="" data-hb-saved-theme-payload="">
                    <x-ui.theme-preset-card :colors="['#FFFFFF', '#FFFFFF', '#FFFFFF']" label="" />
                    <button type="button" class="hb-saved-theme-delete" data-hb-saved-theme-delete aria-label="{{ __('heisenberg::editor.panel_style_themes.delete_theme_aria') }}">
                        @include('heisenberg::components.ui.icon', ['name' => 'x', 'size' => 12])
                    </button>
                </div>
            </template>
        </div>
        <x-ui.custom-scrollbar container="[data-hb-themes-scroll]" />
    </div>

    <div class="hb-style-popup" data-hb-token-colorpicker-popup hidden>
        <x-live.pickers.color-picker mode="fill" value="#000000" />
    </div>
</div>
