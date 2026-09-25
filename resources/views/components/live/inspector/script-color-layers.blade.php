    function hbParseColour(value) {
        let str = String(value || '').trim();
        const ref = /^var\((--[a-z0-9-]+)\)$/i.exec(str);
        if (ref) {
            const scope = document.querySelector('.hb-page') || document.documentElement;
            str = getComputedStyle(scope).getPropertyValue(ref[1]).trim();
            if (!str) return null;
        }
        const rgbFn = /^rgba?\(\s*(\d{1,3})\s*,\s*(\d{1,3})\s*,\s*(\d{1,3})/i.exec(str);
        if (rgbFn) return [Number(rgbFn[1]), Number(rgbFn[2]), Number(rgbFn[3])];
        const raw = str.replace(/^#/, '');
        const full = raw.length === 3 ? raw.split('').map((c) => c + c).join('') : raw;
        if (!/^[0-9a-f]{6}$/i.test(full.slice(0, 6)) || (raw.length !== 3 && raw.length !== 6 && raw.length !== 8)) return null;
        return [parseInt(full.slice(0, 2), 16), parseInt(full.slice(2, 4), 16), parseInt(full.slice(4, 6), 16)];
    }

    function hbIsGradientColour(value) {
        return /^(linear|radial)-gradient\(/i.test(String(value || '').trim());
    }

    function hbCompositeLayers(layers) {
        if (layers.length === 1 && /^var\(--[a-z0-9-]+\)$/i.test(layers[0].color)
            && (!layers[0].opacity || Number(layers[0].opacity) >= 100)) {
            return layers[0].color;
        }
        for (let i = layers.length - 1; i >= 0; i--) {
            if (hbIsGradientColour(layers[i].color)) return layers[i].color;
        }
        let out = null;
        layers.forEach((layer) => {
            const rgb = hbParseColour(layer.color);
            if (!rgb) return;
            let a = Number(layer.opacity);
            a = Number.isFinite(a) ? Math.max(0, Math.min(100, a)) / 100 : 1;
            if (!out) { out = { r: rgb[0], g: rgb[1], b: rgb[2], a }; return; }
            const outA = a + out.a * (1 - a);
            if (outA === 0) { out = { r: 0, g: 0, b: 0, a: 0 }; return; }
            out = {
                r: Math.round((rgb[0] * a + out.r * out.a * (1 - a)) / outA),
                g: Math.round((rgb[1] * a + out.g * out.a * (1 - a)) / outA),
                b: Math.round((rgb[2] * a + out.b * out.a * (1 - a)) / outA),
                a: outA,
            };
        });
        if (!out) return '';
        const hex = (n) => n.toString(16).padStart(2, '0');
        return out.a >= 1 ? `#${hex(out.r)}${hex(out.g)}${hex(out.b)}`
            : `rgba(${out.r}, ${out.g}, ${out.b}, ${Math.round(out.a * 1000) / 1000})`;
    }

    function hbReadLayers(list) {
        return Array.from(list?.querySelectorAll('.hb-colorlayer') || []).map((row) => ({
            color: row.dataset.hbStyleGradient || row.dataset.hbVarBound || row.querySelector('.hb-colorlayer__hex')?.value || '',
            opacity: (row.querySelector('[data-hb-style-layer-opacity]')?.textContent || '100').trim(),
        })).filter((layer) => layer.color !== '');
    }

    const HB_LAYER_PATHS = { fill: 'color.text', stroke: 'border.color' };
    const hbLayerPathOf = (list, group) => (list && list.dataset.hbLayerPath) || HB_LAYER_PATHS[group];

    function hbRebuildLayerLists(root, model) {
        const sroot = root.matches?.('.hb-blockstyle') ? root : root.querySelector('.hb-blockstyle');
        if (!sroot) return;
        Object.keys(HB_LAYER_PATHS).forEach((group) => {
            const list = sroot.querySelector(`[data-hb-style-layer-list="${group}"]`);
            const template = sroot.querySelector(`template[data-hb-style-layer-template="${group}"]`);
            if (!list || !template?.content) return;
            const path = hbLayerPathOf(list, group);
            const sup = (model.supports || {})[path.split('.')[0]] || {};
            let layers = Array.isArray(sup.layers) ? sup.layers.filter((l) => l && l.color) : [];
            const scalar = hbGet(model.supports || {}, path);
            if (!layers.length && scalar != null && String(scalar) !== '') {
                layers = [{ color: String(scalar), opacity: '100' }];
            }
            const state = JSON.stringify(layers.map((l) => [String(l.color || ''), String(l.opacity ?? '100')]));
            if (list.dataset.hbLayersState === state && list.children.length === layers.length) return;
            if (list.contains(document.activeElement)) return;
            list.textContent = '';
            layers.forEach((layer) => {
                const frag = template.content.cloneNode(true);
                const row = frag.querySelector('.hb-colorlayer');
                if (!row) return;
                const value = String(layer.color || '');
                const isGradient = hbIsGradientColour(value);
                const label = hbVarLabelOf(sroot, value);
                if (label) row.dataset.hbVarBound = value; else delete row.dataset.hbVarBound;
                if (isGradient) row.dataset.hbStyleGradient = value; else delete row.dataset.hbStyleGradient;
                const hex = row.querySelector('.hb-colorlayer__hex');
                if (hex) hex.value = label || (isGradient ? @json(__('heisenberg::editor.color_picker.tab_gradient')) : value);
                const swatch = row.querySelector('.hb-colorlayer__swatch');
                if (swatch) swatch.style.background = value || 'transparent';
                const opacity = row.querySelector('[data-hb-style-layer-opacity]');
                if (opacity) opacity.textContent = String(layer.opacity ?? '100');
                list.appendChild(frag);
                hbSyncVarTrigger(row);
            });
            list.dataset.hbLayersState = state;
        });
        hbSyncStrokeBody(sroot);
    }

    // The Stroke section shows only its + until a stroke exists; its Position/Weight/sides
    // controls belong to a stroke, so they appear with the first one and leave with the last.
    function hbSyncStrokeBody(root) {
        const body = root?.querySelector('[data-hb-stroke-body]');
        const list = root?.querySelector('[data-hb-style-layer-list="stroke"]');
        if (body && list) body.hidden = list.children.length === 0;
    }

    function hbCommitLayers(root, group) {
        if (!window.hbEditor) return;
        const id = window.hbEditor.getSelectedId();
        const list = root.querySelector(`[data-hb-style-layer-list="${group}"]`);
        const path = hbLayerPathOf(list, group);
        if (!id || !path) return;
        const layers = hbReadLayers(list);
        window.hbEditor.setSupport(id, hbStatePath(root, path), hbCompositeLayers(layers));
        window.hbEditor.setSupport(id, hbStatePath(root, path.split('.')[0] + '.layers'), layers);
        if (group === 'stroke') {
            if (layers.length) hbSeedStrokeWeight(root, id);
            else hbClearStrokeWeight(root, id);
            hbSyncStrokeBody(root);
        }
    }

    // Removing the last stroke takes its widths with it: a width with no stroke colour is a
    // leftover the author can no longer see or reach (the controls hide with the last stroke).
    function hbClearStrokeWeight(root, id) {
        const model = window.hbEditor.getModel ? window.hbEditor.getModel(id) : null;
        const widthPath = hbStatePath(root, 'border.width');
        const current = model ? hbGet(model.supports || {}, widthPath) : null;
        if (current == null || current === '') return;
        if (typeof current === 'object') {
            ['top', 'right', 'bottom', 'left'].forEach((side) => {
                if (current[side] != null && String(current[side]) !== '') window.hbEditor.setSupport(id, widthPath + '.' + side, '');
            });
            return;
        }
        window.hbEditor.setSupport(id, widthPath, '');
    }

    // A stroke colour with no width draws nothing: the Weight field only SHOWS 1 as its
    // placeholder. So the first stroke on a block writes that 1 to every side it can take,
    // making the stroke visible the moment it is added. A width the author already set wins.
    function hbSeedStrokeWeight(root, id) {
        const model = window.hbEditor.getModel ? window.hbEditor.getModel(id) : null;
        if (!model) return;
        const widthPath = hbStatePath(root, 'border.width');
        const current = hbGet(model.supports || {}, widthPath);
        const sides = ['top', 'right', 'bottom', 'left'];
        const isSet = (v) => v != null && String(v) !== '';
        if (typeof current === 'object' && current && sides.some((s) => isSet(current[s]))) return;
        if (isSet(current) && typeof current !== 'object') return;
        const declared = window.hbEditor.getContract?.(model.name)?.supports?.border?.width;
        sides.forEach((side) => {
            if (declared === true || (declared && declared[side] === true)) {
                window.hbEditor.setSupport(id, widthPath + '.' + side, '1');
            }
        });
    }

    function hbLayerGroupOf(el) {
        const list = el.closest('[data-hb-style-layer-list]');
        return list ? list.getAttribute('data-hb-style-layer-list') : null;
    }

    ['input', 'change'].forEach((type) => document.addEventListener(type, (event) => {
        const row = event.target.closest('.hb-colorlayer');
        if (!row) return;
        const root = mountedStyleRoot(row);
        const group = hbLayerGroupOf(row);
        if (root && group) hbCommitLayers(root, group);
    }));

    function hbShadowRgba(hex, opacityPercent) {
        const raw = String(hex || '').trim().replace(/^#/, '');
        const full = raw.length === 3 ? raw.split('').map((c) => c + c).join('') : raw;
        if (!/^[0-9a-f]{6}$/i.test(full)) return null;
        const r = parseInt(full.slice(0, 2), 16);
        const g = parseInt(full.slice(2, 4), 16);
        const b = parseInt(full.slice(4, 6), 16);
        let a = Number(opacityPercent);
        if (!Number.isFinite(a)) a = 100;
        a = Math.max(0, Math.min(100, a)) / 100;
        return 'rgba(' + r + ', ' + g + ', ' + b + ', ' + Math.round(a * 1000) / 1000 + ')';
    }

    const HB_VAR_TYPES = ['text', 'number'];

    function hbVarLabelOf(root, ref) {
        if (!root || !ref) return null;
        try {
            return JSON.parse(root.getAttribute('data-hb-var-labels') || '{}')[ref] || null;
        } catch (e) {
            return null;
        }
    }

    function hbVarResolvedValue(root, ref) {
        if (!root || !ref) return null;
        try {
            return JSON.parse(root.getAttribute('data-hb-var-values') || '{}')[ref] || null;
        } catch (e) {
            return null;
        }
    }

    function hbVarStateOf(value) {
        const v = String(value ?? '').trim();
        if (v === '') return 'unset';
        return /^var\(\s*--/.test(v) ? 'bound' : 'manual';
    }

    function hbVarMenuFor(path) {
        if (/fontFamily$/i.test(path)) return 'var-font';
        if (/fontSize$/i.test(path)) return 'var-fontsize';
        return /(^|\.)color(\.|$)|color$/i.test(path) ? 'var-color' : 'var-number';
    }

    function hbSyncVarTrigger(control) {
        const button = control.querySelector('[data-hb-style-var-trigger]');
        if (!button) return;
        if (control.dataset.hbVarBound) {
            button.dataset.hbVarState = 'bound';
            return;
        }
        const input = control.matches('input') ? control : control.querySelector('input');
        button.dataset.hbVarState = hbVarStateOf(input?.value);
    }

    const HB_AGGREGATE_FIELDS = '[data-hb-style-all-value], [data-hb-style-padding-axis], [data-hb-style-margin-axis]';

    const hbControlSelector = (path) => '[data-hb-control=' + JSON.stringify(path) + ']';

    function hbAggregateSideControls(root, field) {
        if (!field || !field.matches?.(HB_AGGREGATE_FIELDS)) return null;
        const group = field.getAttribute('data-hb-style-all-value')
            || (field.hasAttribute('data-hb-style-padding-axis') ? 'padding' : 'margin');
        const axis = field.getAttribute('data-hb-style-padding-axis') || field.getAttribute('data-hb-style-margin-axis');
        const sides = axis ? (axis === 'horizontal' ? ['left', 'right'] : ['top', 'bottom']) : ['top', 'right', 'bottom', 'left'];
        return sides
            .map((side) => root.querySelector(hbControlSelector(['spacing', group, side].join('.'))))
            .filter(Boolean);
    }

    function hbAggregateGroupOf(field) {
        if (!field || !field.matches?.(HB_AGGREGATE_FIELDS)) return null;
        return field.getAttribute('data-hb-style-all-value')
            || (field.hasAttribute('data-hb-style-padding-axis') ? 'padding' : 'margin');
    }

    function hbDecorateVarTriggers(root) {
        const prototype = root.querySelector('[data-hb-style-var-prototype] [data-hb-style-var-trigger]');
        if (!prototype) return;
        const decorate = (control) => {
            if (control.closest('[data-hb-style-var-prototype]')) return;
            if (control.querySelector('[data-hb-style-var-trigger]')) return;
            const button = prototype.cloneNode(true);
            button.removeAttribute('hidden');
            control.appendChild(button);
            control.classList.add('hb-has-varbtn');
            hbSyncVarTrigger(control);
        };
        root.querySelectorAll('[data-hb-control]').forEach((control) => {
            if (!HB_VAR_TYPES.includes(control.getAttribute('data-hb-control-type'))) return;
            decorate(control);
        });
        root.querySelectorAll(HB_AGGREGATE_FIELDS).forEach(decorate);
    }

    document.addEventListener('click', (event) => {
        const trigger = event.target.closest('[data-hb-style-var-trigger]');
        if (!trigger) return;
        const root = mountedStyleRoot(trigger);
        if (!root) return;

        const named = trigger.getAttribute('data-hb-style-var-for');
        const layer = trigger.closest('.hb-colorlayer');
        const aggregate = trigger.closest(HB_AGGREGATE_FIELDS);
        const control = named ? root.querySelector('[data-hb-control="' + named + '"]')
            : (layer || aggregate || trigger.closest('[data-hb-control]'));
        if (!control) return;
        event.stopPropagation();
        root.__hbVarTarget = control;
        const menu = layer ? 'var-color'
            : (aggregate && !named ? 'var-number' : hbVarMenuFor(control.getAttribute('data-hb-control') || ''));
        showStylePopup(root, menu, trigger);
    });

    document.addEventListener('varselect', (event) => {
        const popup = event.target.closest('[data-hb-style-popup^="var-"]');
        const root = popup ? mountedStyleRoot(popup) : null;
        const control = root?.__hbVarTarget;
        if (!root || !control) return;
        const value = event.detail?.value ?? event.detail?.name ?? '';

        const label = hbVarLabelOf(root, value);
        const resolved = hbVarResolvedValue(root, value) ?? label ?? value;

        const aggregateSides = hbAggregateSideControls(root, control);
        if (aggregateSides) {
            aggregateSides.forEach((side) => {
                const input = side.querySelector('input');
                if (!input) return;
                if (label) side.dataset.hbVarBound = value; else delete side.dataset.hbVarBound;
                input.value = resolved;
                side.__hbVarJustBound = true;
                input.dispatchEvent(new Event('input', { bubbles: true }));
                input.dispatchEvent(new Event('change', { bubbles: true }));
                side.__hbVarJustBound = false;
                hbSyncVarTrigger(side);
            });
            const aggregateInput = control.querySelector('input');
            if (aggregateInput) {
                if (label) control.dataset.hbVarBound = value; else delete control.dataset.hbVarBound;
                aggregateInput.value = resolved;
                aggregateInput.classList.remove('hb-field__value--mixed');
                control.dataset.hbStyleMixed = 'false';
            }
            hbSyncVarTrigger(control);
            closeStylePopups(root);
            return;
        }

        if (control.classList.contains('hb-colorlayer')) {
            const hex = control.querySelector('.hb-colorlayer__hex');
            if (!hex) return;
            // A bound layer names its token, as hbRebuildLayerLists() does on every model sync.
            hex.value = label || resolved;
            if (label) control.dataset.hbVarBound = value; else delete control.dataset.hbVarBound;
            const swatch = control.querySelector('.hb-colorlayer__swatch');
            if (swatch) swatch.style.background = value || 'transparent';
            control.__hbVarJustBound = true;
            hex.dispatchEvent(new Event('input', { bubbles: true }));
            control.__hbVarJustBound = false;
            hbSyncVarTrigger(control);
            closeStylePopups(root);
            return;
        }

        if (control.getAttribute('data-hb-control-type') === 'combobox') {
            // setValue() only repaints (it is shared with the silent model->DOM sync), so the pick
            // needs its own 'change' to reach the write handler in script-controls-sync.
            control.__hbCombobox?.setValue(value, resolved);
            control.dispatchEvent(new CustomEvent('change', { bubbles: true, detail: { value } }));
        } else {
            const input = control.matches('input') ? control : control.querySelector('input');
            if (!input) return;
            if (label) control.dataset.hbVarBound = value;
            else delete control.dataset.hbVarBound;
            input.value = resolved;
            control.__hbVarJustBound = true;
            input.dispatchEvent(new Event('input', { bubbles: true }));
            input.dispatchEvent(new Event('change', { bubbles: true }));
            control.__hbVarJustBound = false;
        }
        hbSyncVarTrigger(control);
        closeStylePopups(root);
    });

    document.addEventListener('input', (event) => {
        const control = event.target.closest(
            '[data-hb-control], [data-hb-style-all-value], [data-hb-style-padding-axis], [data-hb-style-margin-axis]'
        );
        if (!control) return;
        const root = mountedStyleRoot(control);
        if (!root) return;
        const bound = control.dataset.hbVarBound;
        if (bound && !control.__hbVarJustBound) {
            const input = control.matches('input') ? control : control.querySelector('input');
            const label = hbVarLabelOf(root, bound);
            if (input && input.value !== '' && input.value !== label) {
                delete control.dataset.hbVarBound;
                const group = hbAggregateGroupOf(control);
                if (group) {
                    root.querySelectorAll(`[data-hb-style-side-value="${group}"]`).forEach((side) => {
                        if (side.dataset.hbVarBound) {
                            delete side.dataset.hbVarBound;
                            hbSyncVarTrigger(side);
                        }
                    });
                }
            }
        }
        hbSyncVarTrigger(control);
    }, true);

