    function hbActiveState(root) {
        return root?.dataset.hbStyleState || 'default';
    }

    function hbStatePath(root, path) {
        const state = hbActiveState(root);
        return state === 'default' ? path : 'states.' + state + '.' + path;
    }

    function hbGet(obj, path) {
        const parts = String(path || '').split('.');
        let value = obj;
        for (let i = 0; i < parts.length; i++) {
            if (!value || typeof value !== 'object' || !(parts[i] in value)) return undefined;
            value = value[parts[i]];
        }
        return value;
    }

    function coerceAttrValue(contract, key, raw) {
        const def = contract && contract.attributeDefinitions && contract.attributeDefinitions[key];
        if (!def) return raw;
        if (def.type === 'integer' || def.type === 'number') {
            const n = Number(raw);
            return Number.isNaN(n) ? raw : n;
        }
        if (def.type === 'boolean') return !!raw;
        return raw;
    }

    function evalPredicate(predicate, model) {
        if (!predicate || !predicate.attribute) return true;
        const value = model && model.attributes ? model.attributes[predicate.attribute] : undefined;
        if (Object.prototype.hasOwnProperty.call(predicate, 'equals')) return value === predicate.equals;
        if (Array.isArray(predicate.in)) return predicate.in.indexOf(value) !== -1;
        return true;
    }
    function refreshConditionals(panelRoot, model) {
        panelRoot.querySelectorAll('[data-hb-showwhen]').forEach((row) => {
            try { row.hidden = !evalPredicate(JSON.parse(row.getAttribute('data-hb-showwhen')), model); } catch (e) { }
        });
        panelRoot.querySelectorAll('[data-hb-disablewhen]').forEach((row) => {
            let disabled = false;
            try { disabled = !evalPredicate(JSON.parse(row.getAttribute('data-hb-disablewhen')), model); } catch (e) { }
            row.querySelectorAll('input, select, textarea, button').forEach((el) => { el.disabled = disabled; });
        });
    }

    function syncSelect(root, rawValue) {
        const value = rawValue == null ? '' : String(rawValue);
        const option = root.querySelector('[data-hb-select-option="' + CSS.escape(value) + '"]');
        const valueEl = root.querySelector('[data-hb-select-value]');
        root.querySelectorAll('[data-hb-select-option]').forEach((o) => o.setAttribute('aria-selected', o === option ? 'true' : 'false'));
        root.dataset.value = value;
        if (valueEl) {
            if (option) {
                valueEl.textContent = option.textContent.trim();
                valueEl.classList.remove('hb-select__value--placeholder');
            } else {
                valueEl.textContent = valueEl.textContent;
                valueEl.classList.add('hb-select__value--placeholder');
            }
        }
    }

    function syncColorPreview(selectRoot, rawValue) {
        const preview = selectRoot.parentElement ? selectRoot.parentElement.querySelector('[data-hb-color-preview] .hb-swatch__color') : null;
        if (preview) preview.style.background = rawValue || 'transparent';
    }

    function controlPristine(el, type) {
        if (el.__hbPristine !== undefined) return el.__hbPristine;
        let value;
        if (type === 'toggle') value = !!el.querySelector('.hb-toggle__input')?.checked;
        else if (type === 'checkbox') {
            const checked = !!el.querySelector('.hb-checkbox__input')?.checked;
            const on = el.getAttribute('data-hb-control-on');
            const off = el.getAttribute('data-hb-control-off');
            value = (on === null && off === null) ? checked : (checked ? (on ?? 'true') : (off ?? ''));
        } else if (type === 'select' || type === 'combobox') value = el.dataset.value ?? '';
        else if (type === 'segmented') value = el.querySelector('[data-hb-tab][aria-selected="true"]')?.dataset.hbTab ?? '';
        else {
            const field = el.matches('input, textarea') ? el : el.querySelector('input, textarea');
            value = field ? field.value : '';
        }
        return el.__hbPristine = value;
    }

    function syncControls(root, model) {
        root.querySelectorAll('[data-hb-control]').forEach((el) => {
            const key = el.getAttribute('data-hb-control');
            const kind = el.getAttribute('data-hb-control-kind');
            const type = el.getAttribute('data-hb-control-type');
            const source = kind === 'supports' ? (model.supports || {}) : (model.attributes || {});
            let value = kind === 'supports'
                ? hbGet(source, hbStatePath(mountedStyleRoot(el) || el.closest('.hb-blockstyle'), key))
                : (window.hbEditor && window.hbEditor.readAttr ? window.hbEditor.readAttr(model, key) : hbGet(source, key));

            if (root.querySelector('.hb-blockstyle')) {
                const pristine = controlPristine(el, type);
                if (value === undefined) value = pristine;
            }

            if (type === 'toggle') {
                const input = el.querySelector('.hb-toggle__input');
                if (input) input.checked = !!value;
                return;
            }
            if (type === 'checkbox') {
                const input = el.querySelector('.hb-checkbox__input');
                const on = el.getAttribute('data-hb-control-on');
                if (input) input.checked = on === null ? !!value : String(value ?? '') === on;
                return;
            }
            if (type === 'select') {
                syncSelect(el, value);
                syncColorPreview(el, value == null ? '' : String(value));
                return;
            }
            if (type === 'segmented') {
                const text = value == null ? '' : String(value);
                el.querySelectorAll('[data-hb-tab]').forEach((tab) => {
                    tab.setAttribute('aria-selected', tab.dataset.hbTab === text && text !== '' ? 'true' : 'false');
                });
                return;
            }
            if (type === 'combobox') {
                const text = value == null ? '' : String(value);
                let optionLabel = null;
                el.querySelectorAll('[data-hb-combobox-option]').forEach((option) => {
                    if (optionLabel === null && option.dataset.hbComboboxOption === text) {
                        optionLabel = option.querySelector('span')?.textContent.trim() || null;
                    }
                });
                const styleRoot = mountedStyleRoot(el) || el.closest('.hb-blockstyle');
                const resolved = hbVarResolvedValue(styleRoot, text);
                const display = optionLabel || resolved || text;
                if (el.__hbCombobox?.setValue) el.__hbCombobox.setValue(text, display);
                else {
                    const field = el.querySelector('[data-hb-combobox-input]');
                    if (field) field.value = display;
                    el.dataset.value = text;
                }
                return;
            }
            if (type === 'chips') {
                renderChips(el, String(value == null ? '' : value).split(/\s+/).filter(Boolean));
                return;
            }
            const input = el.matches('input, textarea') ? el : el.querySelector('input, textarea');
            if (input) {
                const ref = value == null ? '' : String(value);
                const styleRoot = mountedStyleRoot(el) || el.closest('.hb-blockstyle');
                const label = hbVarLabelOf(styleRoot, ref);
                if (label) el.dataset.hbVarBound = ref;
                else delete el.dataset.hbVarBound;
                const resolved = hbVarResolvedValue(styleRoot, ref);
                input.value = label ? (resolved ?? label) : ref;
            }
            if (type === 'range') {
                const readout = el.closest('.hb-icol')?.querySelector('[data-hb-range-readout]');
                if (readout) readout.textContent = value == null ? '' : value;
            }
        });
        syncSpacingAggregates(root);
        hbDecorateVarTriggers(root);
        hbRebuildLayerLists(root, model);
        root.querySelectorAll('[data-hb-control], .hb-colorlayer').forEach(hbSyncVarTrigger);
        refreshConditionals(root, model);
        hbSyncFonts(root, model);
        syncFlexControls(root, model);
    }

    function showBlockPanels(inspector, name, model) {
        inspector.querySelectorAll('[data-hb-subpanel]').forEach((subpanel) => {
            subpanel.querySelectorAll('[data-hb-block-panel]').forEach((panel) => {
                const match = panel.getAttribute('data-hb-block-panel') === name;
                if (match) syncControls(panel, model);
                panel.hidden = !match;
            });
        });
    }

    function showBlockIcon(inspector, name) {
        const defaultIcon = inspector.querySelector('[data-hb-block-icon-default]');
        inspector.querySelectorAll('[data-hb-block-icon]').forEach((icon) => {
            icon.hidden = icon.getAttribute('data-hb-block-icon') !== name;
        });
        if (defaultIcon) defaultIcon.hidden = !!name;
    }

    document.addEventListener('hb:block-selected', (event) => {
        const inspector = document.querySelector('[data-hb-inspector]');
        if (!inspector) return;
        const { name, model } = event.detail || {};
        inspector.querySelectorAll('.hb-blockstyle').forEach((styleRoot) => {
            if ((styleRoot.dataset.hbStyleState || 'default') === 'default') return;
            styleRoot.dataset.hbStyleState = 'default';
            const tabs = styleRoot.querySelector('[data-hb-style-state]');
            if (tabs) tabs.querySelectorAll('[data-hb-tab]').forEach((tab) => {
                tab.setAttribute('aria-selected', tab.dataset.hbTab === 'default' ? 'true' : 'false');
            });
        });
        showBlockIcon(inspector, name || '');
        const empty = inspector.querySelector('[data-hb-block-empty]');
        const populated = inspector.querySelector('[data-hb-block-populated]');
        if (empty) empty.hidden = true;
        if (populated) populated.hidden = false;
        if (name && model) showBlockPanels(inspector, name, model);
    });

    document.addEventListener('hb:block-deselected', () => {
        const inspector = document.querySelector('[data-hb-inspector]');
        if (!inspector) return;
        showBlockIcon(inspector, '');
        const empty = inspector.querySelector('[data-hb-block-empty]');
        const populated = inspector.querySelector('[data-hb-block-populated]');
        if (empty) empty.hidden = false;
        if (populated) populated.hidden = true;
    });

    document.addEventListener('hb:block-updated', (event) => {
        const inspector = document.querySelector('[data-hb-inspector]');
        const populated = inspector ? inspector.querySelector('[data-hb-block-populated]') : null;
        if (!inspector || !populated || populated.hidden) return;
        const { id, model } = event.detail || {};
        if (!model || !window.hbEditor || window.hbEditor.getSelectedId() !== id) return;
        const active = document.activeElement;
        populated.querySelectorAll('[data-hb-block-panel]').forEach((panel) => {
            if (panel.hidden) return;
            if (active && panel.contains(active)) { refreshConditionals(panel, model); return; }
            syncControls(panel, model);
        });
    });

    document.addEventListener('focusin', (event) => {
        const el = event.target.closest ? event.target.closest('[data-hb-control]') : null;
        if (el && window.hbEditor) el.__hbEditsBlock = window.hbEditor.getSelectedId();
    });
    document.addEventListener('focusout', (event) => {
        const el = event.target.closest ? event.target.closest('[data-hb-control]') : null;
        if (el) delete el.__hbEditsBlock;
    });

    function handleControlEvent(event, isChange) {
        const el = event.target.closest('[data-hb-control]');
        if (!el) return;
        const panel = el.closest('[data-hb-block-panel]');
        if (!panel || panel.hidden) return;
        if (!window.hbEditor) return;
        const id = window.hbEditor.getSelectedId();
        if (!id) return;
        if (el.__hbEditsBlock !== undefined && el.__hbEditsBlock !== id) return;
        const model = window.hbEditor.getModel(id);
        if (!model) return;
        const contract = window.hbEditor.getContract(model.name);

        const key = el.getAttribute('data-hb-control');
        const kind = el.getAttribute('data-hb-control-kind');
        const type = el.getAttribute('data-hb-control-type');
        if (!key) return;
        if (type === 'chips') return;

        let raw;
        if (type === 'toggle') {
            if (!isChange) return;
            const input = el.querySelector('.hb-toggle__input');
            raw = !!(input && input.checked);
        } else if (type === 'checkbox') {
            if (!isChange) return;
            const checked = !!el.querySelector('.hb-checkbox__input')?.checked;
            const on = el.getAttribute('data-hb-control-on');
            const off = el.getAttribute('data-hb-control-off');
            raw = (on === null && off === null) ? checked : (checked ? (on ?? 'true') : (off ?? ''));
        } else if (type === 'select') {
            if (event.target !== el) return;
            raw = el.dataset.value;
            syncColorPreview(el, raw);
        } else if (type === 'combobox') {
            if (event.target !== el) return;
            raw = el.dataset.value;
        } else if (type === 'segmented') {
            if (event.target !== el) return;
            raw = el.querySelector('[data-hb-tab][aria-selected="true"]')?.dataset.hbTab ?? '';
        } else {
            const input = el.matches('input, textarea') ? el : el.querySelector('input, textarea');
            if (!input) return;
            raw = el.dataset.hbVarBound || input.value;
            if (type === 'range') {
                const readout = el.closest('.hb-icol')?.querySelector('[data-hb-range-readout]');
                if (readout) readout.textContent = raw;
            }
            if (type === 'number' || type === 'range') raw = raw === '' ? '' : Number(raw);
        }

        if (kind === 'supports') {
            window.hbEditor.setSupport(id, hbStatePath(el.closest('.hb-blockstyle'), key), raw);
            return;
        }

        window.hbEditor.setAttribute(id, key, coerceAttrValue(contract, key, raw));
    }
    document.addEventListener('input', (event) => handleControlEvent(event, false));
    document.addEventListener('change', (event) => handleControlEvent(event, true));

