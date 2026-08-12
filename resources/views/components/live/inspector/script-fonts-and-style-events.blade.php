    // ── Typography font family: paged search against the vendored catalog ──
    // The field is ui/combobox; this only answers the `search`/`loadmore` events it dispatches
    // and hands results back through its own replaceOptions()/appendOptions(). Deliberately the
    // same contract panel-style-themes.blade.php uses for the left sidebar's Fonts rows — one
    // endpoint, one paging shape, no second dropdown implementation to keep in step.
    //
    // Page state lives on the combobox element itself, not a shared variable: the Style panel is
    // pre-rendered once per registered block type, so several font comboboxes exist at once and
    // each needs its own offset/hasMore.
    const HB_FONT_PAGE_LIMIT = 40;
    let hbFontTimer = null;

    function hbFontsUrl() {
        return document.querySelector('[data-hb-inspector]')?.dataset.hbFontsSearchUrl || '';
    }

    function hbFetchFonts(url) {
        return window.fetch(url, { headers: { 'Accept': 'application/json' } })
            .then((res) => (res.ok ? res.json() : { fonts: [], has_more: false }))
            .then((body) => ({
                list: (body.fonts || []).map((f) => ({ value: f.family, label: f.family })),
                hasMore: !!body.has_more,
            }));
    }

    function hbSearchFonts(combobox, query) {
        const url = hbFontsUrl();
        if (!url) return;
        const page = { query, offset: 0, hasMore: true, loading: true };
        combobox.__hbFontPage = page;
        hbFetchFonts(url + '?q=' + encodeURIComponent(query) + '&limit=' + HB_FONT_PAGE_LIMIT)
            .then(({ list, hasMore }) => {
                combobox.__hbCombobox?.replaceOptions(list);
                if (combobox.__hbFontPage !== page) return; // a newer search superseded this one
                page.offset = list.length;
                page.hasMore = hasMore;
                page.loading = false;
            });
    }

    function hbLoadMoreFonts(combobox, query) {
        const url = hbFontsUrl();
        const page = combobox.__hbFontPage;
        if (!url || !page || page.loading || !page.hasMore || page.query !== query) return;
        page.loading = true;
        hbFetchFonts(url + '?q=' + encodeURIComponent(query) + '&limit=' + HB_FONT_PAGE_LIMIT + '&offset=' + page.offset)
            .then(({ list, hasMore }) => {
                combobox.__hbCombobox?.appendOptions(list);
                page.offset += list.length;
                page.hasMore = hasMore;
                page.loading = false;
            });
    }

    document.addEventListener('search', (event) => {
        const combobox = event.target.closest('[data-hb-style-font-family]');
        if (!combobox) return;
        clearTimeout(hbFontTimer);
        hbFontTimer = setTimeout(() => hbSearchFonts(combobox, event.detail?.query || ''), 250);
    });

    document.addEventListener('loadmore', (event) => {
        const combobox = event.target.closest('[data-hb-style-font-family]');
        if (!combobox) return;
        hbLoadMoreFonts(combobox, event.detail?.query || '');
    });

    // ── Canvas font loading + real per-family weights ─────────────────────────────────
    // The editor page ships no content fonts of its own (only preview does, via css2Url),
    // so a picked family must be fetched here or the canvas silently keeps the fallback.
    // The same catalog lookup also yields the family's REAL weights for the Weight select.
    const hbFontMetaCache = new Map();

    function hbFontMeta(family) {
        const key = String(family || '').trim().toLowerCase();
        const url = hbFontsUrl();
        if (!key || !url) return Promise.resolve(null);
        if (!hbFontMetaCache.has(key)) {
            hbFontMetaCache.set(key, window.fetch(url + '?q=' + encodeURIComponent(family) + '&limit=8', { headers: { Accept: 'application/json' } })
                .then((res) => (res.ok ? res.json() : { fonts: [] }))
                .then((body) => (body.fonts || []).find((f) => String(f.family).toLowerCase() === key) || null)
                .catch(() => null));
        }
        return hbFontMetaCache.get(key);
    }

    function hbDocFontFamilies() {
        const families = new Set();
        (window.hbEditor?.getDoc().blocks || []).forEach((block) => {
            const family = block.supports?.typography?.fontFamily;
            if (typeof family === 'string' && family.trim() !== '' && family.indexOf('var(') === -1) {
                families.add(family.trim());
            }
        });
        return [...families];
    }

    function hbSyncCanvasFonts() {
        Promise.all(hbDocFontFamilies().map(hbFontMeta)).then((metas) => {
            // Same URL shape FontCatalogService::css2Url() builds for the published page,
            // with each family's real catalog weights so every pickable weight renders.
            const parts = metas.filter(Boolean).map((meta) => {
                const weights = (meta.weights || []).map(Number).filter(Boolean).sort((a, b) => a - b);
                let spec = 'family=' + String(meta.family).replace(/ /g, '+');
                if (weights.length && weights.join(';') !== '400') spec += ':wght@' + weights.join(';');
                return spec;
            });
            let link = document.getElementById('hb-canvas-fonts');
            if (!parts.length) { link?.remove(); return; }
            const href = 'https://fonts.googleapis.com/css2?' + parts.join('&') + '&display=swap';
            if (!link) {
                link = document.createElement('link');
                link.id = 'hb-canvas-fonts';
                link.rel = 'stylesheet';
                document.head.appendChild(link);
            }
            if (link.href !== href) link.href = href;
        });
    }

    const HB_WEIGHT_NAMES = {
        100: 'Thin', 200: 'Extra light', 300: 'Light', 400: 'Regular', 500: 'Medium',
        600: 'Semi bold', 700: 'Bold', 800: 'Extra bold', 900: 'Black',
    };

    function hbSyncWeightOptions(root, model) {
        const select = root.querySelector(hbControlSelector(['typography', 'fontWeight'].join('.')));
        const menu = select?.querySelector('[data-hb-select-menu]');
        if (!select || !menu) return;
        const current = String(model.supports?.typography?.fontWeight ?? '');
        const apply = (weights) => {
            const list = (weights && weights.length ? weights : [100, 200, 300, 400, 500, 600, 700, 800, 900]).map(String);
            const values = ['', ...list];
            if (current !== '' && !values.includes(current)) values.push(current);
            if (menu.dataset.hbWeights === values.join(',')) return;
            const prototype = menu.querySelector('[data-hb-select-option]');
            if (!prototype) return;
            const blueprint = prototype.cloneNode(true);
            menu.dataset.hbWeights = values.join(',');
            menu.textContent = '';
            values.forEach((value) => {
                const option = blueprint.cloneNode(true);
                option.dataset.hbSelectOption = value;
                option.setAttribute('aria-selected', value === current ? 'true' : 'false');
                option.removeAttribute('data-highlighted');
                const span = option.querySelector('span');
                if (span) span.textContent = value === '' ? 'Default' : (HB_WEIGHT_NAMES[value] || value);
                // ui/select binds option clicks at boot only — rebuilt options wire themselves.
                option.addEventListener('click', () => select.__hbSelect?.select(option));
                menu.appendChild(option);
            });
        };
        const family = model.supports?.typography?.fontFamily;
        if (typeof family === 'string' && family.trim() !== '' && family.indexOf('var(') === -1) {
            hbFontMeta(family).then((meta) => apply(meta && Array.isArray(meta.weights) ? meta.weights.map(Number) : null));
        } else {
            apply(null);
        }
    }

    function hbSyncFonts(root, model) {
        hbSyncCanvasFonts();
        hbSyncWeightOptions(root, model);
    }

    // Hydration and structural changes can carry font families without a selection sync.
    document.addEventListener('hb:blocks-changed', () => {
        clearTimeout(hbSyncFonts.__timer);
        hbSyncFonts.__timer = setTimeout(hbSyncCanvasFonts, 200);
    });

    // ── extraClasses chips (Content → General, 2026-08-04) ────────────────────────────
    // The model holds ONE space-separated string (contract type "string"); the panel presents it
    // as chips plus an add-input (contract control type "chips"). Read from the DOM rather than a
    // cached string so a server-rendered chip and a cloned one are treated identically.
    function chipValues(host) {
        return Array.from(host.querySelectorAll('[data-hb-chip]'))
            .map((chip) => chip.dataset.hbChipValue || chip.querySelector('span')?.textContent.trim() || '')
            .filter(Boolean);
    }

    function renderChips(host, classes) {
        const list = host.querySelector('[data-hb-chip-list]');
        // A hidden real ui/chip rather than a <template> — see content.blade.php for why.
        // Scoped to the host's own parent, since the Content panel is pre-rendered once per
        // registered block type and each copy carries its own prototype.
        const prototype = host.parentElement?.querySelector('[data-hb-chip-prototype] [data-hb-chip]');
        if (!list || !prototype) return;
        list.textContent = '';
        classes.forEach((name) => {
            const chip = prototype.cloneNode(true);
            chip.removeAttribute('hidden');
            const label = chip.querySelector('span');
            if (label) label.textContent = name;
            chip.dataset.hbChipValue = name;
            list.appendChild(chip);
        });
    }

    function writeChips(host, classes) {
        renderChips(host, classes);
        if (!window.hbEditor) return;
        const id = window.hbEditor.getSelectedId();
        if (!id) return;
        window.hbEditor.setAttribute(id, host.getAttribute('data-hb-control'), classes.join(' '));
    }

    function updateColorLayer(layer, hex) {
        if (!layer || !/^#[0-9a-f]{6}$/i.test(hex)) return;
        const normalised = hex.toUpperCase();
        const input = layer.querySelector('.hb-colorlayer__hex');
        const swatch = layer.querySelector('.hb-colorlayer__swatch');
        if (input) input.value = normalised;
        if (swatch) swatch.style.background = normalised;
    }

    function activateStyleRadio(radio) {
        const group = radio.parentElement;
        if (!group) return;
        group.querySelectorAll('[data-hb-style-radio]').forEach((item) => {
            const active = item === radio;
            item.classList.toggle('hb-iradio--on', active);
            item.classList.toggle('is-active', active);
            item.setAttribute('aria-checked', active ? 'true' : 'false');
            item.querySelector('.hb-iradio__dot')?.classList.toggle('hb-iradio__dot--on', active);
        });
    }

    document.addEventListener('click', (event) => {
        const root = mountedStyleRoot(event.target);
        if (!root) return;

        // Any trigger omitted from this list gets its popup closed the instant it opens: this
        // listener and each trigger's own are BOTH on document, so stopPropagation cannot keep
        // them apart (that needs stopImmediatePropagation, and listener order is not guaranteed).
        // data-hb-style-var-trigger was missing here, which is why the theme-variable popup
        // appeared not to respond to clicks at all.
        if (!event.target.closest('[data-hb-style-popup], [data-hb-style-color-trigger], [data-hb-style-popup-trigger], [data-hb-style-effect-trigger], [data-hb-style-var-trigger]')) {
            closeStylePopups(root);
        }

        const paddingOption = event.target.closest('[data-hb-style-padding-option]');
        if (paddingOption) {
            const index = Number(paddingOption.dataset.hbStylePaddingOption);
            const menu = paddingOption.closest('.hb-padmenu');
            menu?.querySelectorAll('[data-hb-style-padding-option]').forEach((option) => {
                const selected = option === paddingOption;
                option.classList.toggle('hb-padmenu__opt--on', selected);
                option.setAttribute('aria-checked', selected ? 'true' : 'false');
                const radio = option.querySelector('.hb-radio__input');
                if (radio) radio.checked = selected;
            });
            setPaddingMode(root, index);
            closeStylePopups(root);
            return;
        }

        const marginOption = event.target.closest('[data-hb-style-margin-option]');
        if (marginOption) {
            const index = Number(marginOption.dataset.hbStyleMarginOption);
            const menu = marginOption.closest('.hb-padmenu');
            menu?.querySelectorAll('[data-hb-style-margin-option]').forEach((option) => {
                const selected = option === marginOption;
                option.classList.toggle('hb-padmenu__opt--on', selected);
                option.setAttribute('aria-checked', selected ? 'true' : 'false');
                const radio = option.querySelector('.hb-radio__input');
                if (radio) radio.checked = selected;
            });
            setMarginMode(root, index);
            closeStylePopups(root);
            return;
        }

        const colorTrigger = event.target.closest('[data-hb-style-color-trigger]');
        if (colorTrigger) {
            root.__hbStyleActiveColorLayer = colorTrigger.closest('.hb-colorlayer');
            const picker = root.querySelector('[data-hb-style-popup="color"] [data-hb-colorpicker]');
            const value = root.__hbStyleActiveColorLayer?.querySelector('.hb-colorlayer__hex')?.value || '#000000';
            picker?.__hbCp?.setHex(value);
            showStylePopup(root, 'color', colorTrigger);
            return;
        }

        const paddingTrigger = event.target.closest('[data-hb-style-popup-trigger="padding"]');
        if (paddingTrigger) {
            showStylePopup(root, 'padding', paddingTrigger);
            return;
        }

        const marginTrigger = event.target.closest('[data-hb-style-popup-trigger="margin"]');
        if (marginTrigger) {
            showStylePopup(root, 'margin', marginTrigger);
            return;
        }

        const effectTrigger = event.target.closest('[data-hb-style-effect-trigger]');
        if (effectTrigger) {
            showStylePopup(root, 'effect', effectTrigger);
            return;
        }

        const effectVisibility = event.target.closest('[data-hb-style-effect-visibility]');
        if (effectVisibility) {
            const visible = effectVisibility.getAttribute('aria-pressed') !== 'true';
            effectVisibility.setAttribute('aria-pressed', visible ? 'true' : 'false');
            effectVisibility.closest('.hb-fxlayer')?.setAttribute('data-hb-style-effect-hidden', visible ? 'false' : 'true');
            return;
        }

        const alignment = event.target.closest('[data-hb-style-alignment]');
        if (alignment) {
            const grid = alignment.closest('[data-hb-style-alignment-grid]');
            const wasActive = alignment.classList.contains('is-active');
            grid?.querySelectorAll('[data-hb-style-alignment]').forEach((item) => {
                const active = item === alignment && !wasActive;
                item.classList.toggle('hb-agrid__dot--on', active);
                item.classList.toggle('is-active', active);
                item.setAttribute('aria-pressed', active ? 'true' : 'false');
            });
            // Flex wiring: a dot carries its justify×align pair and writes BOTH paths in one
            // gesture (re-clicking the active dot clears them). While a spacing radio owns
            // justify (space-between/around), the grid only writes align.
            if (alignment.dataset.hbFlexJustify && window.hbEditor) {
                const id = window.hbEditor.getSelectedId();
                if (id) {
                    const style = alignment.closest('.hb-blockstyle');
                    const spacingActive = root.querySelector('[data-hb-flex-spacing="space-between"].is-active, [data-hb-flex-spacing="space-around"].is-active');
                    if (!spacingActive) {
                        window.hbEditor.setSupport(id, hbStatePath(style, 'layout.justify'), wasActive ? '' : alignment.dataset.hbFlexJustify);
                    }
                    window.hbEditor.setSupport(id, hbStatePath(style, 'layout.align'), wasActive ? '' : alignment.dataset.hbFlexAlign);
                }
            }
            return;
        }

        const radio = event.target.closest('[data-hb-style-radio]');
        if (radio) {
            activateStyleRadio(radio);
            // Flex wiring: the radio column is justify's spacing mode. Space Between/Around
            // write themselves; the Gap (packed) row hands justify back to the grid's column.
            const spacing = radio.dataset.hbFlexSpacing;
            if (spacing && window.hbEditor) {
                const id = window.hbEditor.getSelectedId();
                if (id) {
                    const style = radio.closest('.hb-blockstyle');
                    let justify = spacing;
                    if (spacing === 'packed') {
                        const dot = root.querySelector('[data-hb-style-alignment-grid] .is-active');
                        justify = dot ? dot.dataset.hbFlexJustify : '';
                    }
                    window.hbEditor.setSupport(id, hbStatePath(style, 'layout.justify'), justify);
                }
            }
            return;
        }

        const link = event.target.closest('[data-hb-style-link]');
        if (link) {
            const active = link.getAttribute('aria-pressed') !== 'true';
            link.classList.toggle('is-active', active);
            link.setAttribute('aria-pressed', active ? 'true' : 'false');
            return;
        }

        const expand = event.target.closest('[data-hb-style-expand]');
        if (expand) {
            const section = expand.closest('.hb-section');
            const expanded = expand.getAttribute('aria-expanded') !== 'true';
            section?.querySelectorAll('[data-hb-style-expandable]').forEach((item) => { item.hidden = !expanded; });
            expand.setAttribute('aria-expanded', expanded ? 'true' : 'false');
            expand.classList.toggle('is-active', expanded);
            if (expanded) syncStyleLinkedValue(root, expand.dataset.hbStyleExpand);
            return;
        }

        const remove = event.target.closest('[data-hb-style-remove]');
        if (remove) {
            event.preventDefault();
            const removed = remove.closest('.hb-colorlayer, .hb-effectrow');
            if (removed && root.__hbStyleActiveColorLayer === removed) {
                root.__hbStyleActiveColorLayer = null;
                closeStylePopups(root);
            }
            // Read the group BEFORE detaching — afterwards the row has no list to belong to.
            const group = removed ? hbLayerGroupOf(removed) : null;
            removed?.remove();
            if (group) hbCommitLayers(root, group);
            return;
        }

        const add = event.target.closest('[data-hb-style-add]');
        if (add) {
            const section = add.closest('.hb-section');
            const list = section?.querySelector(`[data-hb-style-layer-list="${add.dataset.hbStyleAdd}"]`);
            const template = section?.querySelector(`template[data-hb-style-layer-template="${add.dataset.hbStyleAdd}"]`);
            if (list && template?.content) {
                list.append(template.content.cloneNode(true));
                document.dispatchEvent(new Event('hb:refresh'));
                // A new layer changes the composite immediately, not only once it is edited, and
                // its trigger needs an initial state or it renders with no styling at all.
                hbCommitLayers(root, add.dataset.hbStyleAdd);
                list.querySelectorAll('.hb-colorlayer').forEach(hbSyncVarTrigger);
            }
            return;
        }

        // The clear-font `x` button was replaced by the theme-variable trigger; its
        // handler is gone with it. Clearing now happens by picking the font menu's empty
        // "Default" row, which writes '' exactly as the x did.
    });

    document.addEventListener('input', (event) => {
        const root = mountedStyleRoot(event.target);
        if (!root) return;

        const paddingAxis = event.target.closest('[data-hb-style-padding-axis]');
        if (paddingAxis) {
            setPaddingAxisValue(root, paddingAxis.dataset.hbStylePaddingAxis, event.target.value);
            commitSpacingGroup(root, 'padding');
            return;
        }

        const marginAxis = event.target.closest('[data-hb-style-margin-axis]');
        if (marginAxis) {
            setMarginAxisValue(root, marginAxis.dataset.hbStyleMarginAxis, event.target.value);
            commitSpacingGroup(root, 'margin');
            return;
        }

        const all = event.target.closest('[data-hb-style-all-value]');
        if (all) {
            const group = all.dataset.hbStyleAllValue;
            setStyleLinkedValue(root, group, event.target.value);
            // Spacing commits its four sides as one object; stroke-sides/appearance-corners
            // commit through each side's own control hook.
            if (group === 'padding') { syncPaddingControls(root); commitSpacingGroup(root, 'padding'); }
            else if (group === 'margin') { syncMarginControls(root); commitSpacingGroup(root, 'margin'); }
            else if (HB_LINKED_GROUPS.indexOf(group) !== -1) commitLinkedSides(root, group);
            return;
        }

        const side = event.target.closest('[data-hb-style-side-value]');
        if (side) {
            syncStyleLinkedValue(root, side.dataset.hbStyleSideValue);
            if (side.dataset.hbStyleSideValue === 'padding') syncPaddingControls(root);
            else if (side.dataset.hbStyleSideValue === 'margin') syncMarginControls(root);
        }

        if (event.target.matches('.hb-colorlayer__hex')) updateColorLayer(event.target.closest('.hb-colorlayer'), event.target.value);
    });

    // extraClasses chips — Enter appends, a chip's close button removes. Both commit through
    // writeChips(); nothing else in the inspector writes this attribute (the generic
    // [data-hb-control] handler bails out on type="chips", see its own comment).
    document.addEventListener('keydown', (event) => {
        if (event.key !== 'Enter') return;
        const input = event.target.closest('[data-hb-chip-input]');
        const host = input?.closest('[data-hb-control-type="chips"]');
        if (!host) return;
        event.preventDefault();
        // Space is the model's separator, so one paste of "a b c" becomes three chips rather
        // than one chip whose label would silently re-split on the next read.
        const added = input.value.trim().split(/\s+/).filter(Boolean);
        if (added.length === 0) return;
        const classes = chipValues(host);
        added.forEach((name) => { if (!classes.includes(name)) classes.push(name); });
        writeChips(host, classes);
        input.value = '';
    });

    document.addEventListener('click', (event) => {
        const close = event.target.closest('[data-hb-chip-close]');
        const host = close?.closest('[data-hb-control-type="chips"]');
        if (!host) return;
        const name = close.closest('[data-hb-chip]')?.dataset.hbChipValue;
        if (!name) return;
        writeChips(host, chipValues(host).filter((cls) => cls !== name));
    });

    document.addEventListener('focusin', (event) => {
        const all = event.target.closest('[data-hb-style-all-value], [data-hb-style-padding-axis], [data-hb-style-margin-axis]');
        const root = mountedStyleRoot(event.target);
        if (!root || !all || all.dataset.hbStyleMixed !== 'true') return;
        event.target.select();
    });

    document.addEventListener('colorchange', (event) => {
        const popup = event.target.closest('[data-hb-style-popup="color"]');
        const root = popup ? mountedStyleRoot(popup) : null;
        if (!root || !root.__hbStyleActiveColorLayer) return;
        if (event.detail?.gradientStop !== null && event.detail?.gradientStop !== undefined) return;
        const { r, g, b, a } = event.detail || {};
        const toHex = (value) => Number(value).toString(16).padStart(2, '0');
        if ([r, g, b].some((value) => !Number.isFinite(value))) return;
        const layer = root.__hbStyleActiveColorLayer;
        updateColorLayer(layer, `#${toHex(r)}${toHex(g)}${toHex(b)}`);
        // The picker's alpha IS the layer's opacity — that is why the row needs no separate
        // opacity field. Picking a colour also un-binds any theme token the row was on, since a
        // literal colour is what was just chosen.
        const op = layer?.querySelector('[data-hb-style-layer-opacity]');
        if (op && Number.isFinite(a)) op.textContent = String(Math.round(Math.max(0, Math.min(1, a)) * 100));
        if (layer) delete layer.dataset.hbVarBound;
        const group = layer ? hbLayerGroupOf(layer) : null;
        if (group) { hbCommitLayers(root, group); hbSyncVarTrigger(layer); }
    });

    document.addEventListener('gradientchange', (event) => {
        const popup = event.target.closest('[data-hb-style-popup="color"]');
        const root = popup ? mountedStyleRoot(popup) : null;
        const css = event.detail?.css;
        if (!root || !root.__hbStyleActiveColorLayer || typeof css !== 'string') return;
        const swatch = root.__hbStyleActiveColorLayer.querySelector('.hb-colorlayer__swatch');
        if (swatch) swatch.style.background = css;
        root.__hbStyleActiveColorLayer.dataset.hbStyleGradient = css;
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            const root = mountedStyleRoot(event.target);
            if (root) closeStylePopups(root);
        }
        const radio = event.target.closest('[data-hb-style-radio]');
        if (!radio || !mountedStyleRoot(radio) || !['Enter', ' '].includes(event.key)) return;
        event.preventDefault();
        activateStyleRadio(radio);
    });
