    // ── Effects (live/block/style/effects.blade.php + pickers/effect-editor) ───────────────────
    // A block's effects are an ordered list of layers, each {type, visible, …fields}, kept at
    // supports.effects.layers as editor state. Every commit also writes the three real values the
    // renderer reads, so the page never depends on the layer list:
    //   effects.shadow   ← visible drop shadows           (box-shadow, comma-separated)
    //   effects.filter   ← visible layer blur              (filter)
    //   effects.backdrop ← visible background blur         (backdrop-filter)
    // A document edited elsewhere (code view, AI, MCP) has no layer list, so the list is rebuilt
    // from those three values instead.

    const HB_FX_SHADOW_DEFAULT = { color: '#000000', opacity: 14, x: 0, y: 8, blur: 28, spread: 0 };

    function hbFxTypes(root) {
        const list = root?.querySelector('[data-hb-fx-list]');
        if (!list) return {};
        if (!list.__hbFxTypes) {
            try { list.__hbFxTypes = JSON.parse(list.getAttribute('data-hb-fx-types') || '{}'); } catch (e) { list.__hbFxTypes = {}; }
        }
        return list.__hbFxTypes;
    }

    function hbFxNewLayer(types, type) {
        const def = types[type];
        if (!def) return null;
        if (def.target === 'shadow') return Object.assign({ type, visible: true }, HB_FX_SHADOW_DEFAULT);
        return { type, visible: true, amount: Number(def.amount) || 0 };
    }

    function hbFxNum(value, fallback) {
        const n = Number(String(value ?? '').replace(/[^\d.-]/g, ''));
        return Number.isFinite(n) ? n : fallback;
    }

    function hbFxCompose(types, layers) {
        const shadows = [];
        const filters = [];
        const backdrops = [];
        layers.forEach((layer) => {
            const def = types[layer.type];
            if (!def || layer.visible === false) return;
            if (def.target === 'shadow') {
                const colour = hbShadowRgba(layer.color, hbFxNum(layer.opacity, 100));
                if (!colour) return;
                shadows.push((layer.inset ? 'inset ' : '')
                    + hbFxNum(layer.x, 0) + 'px ' + hbFxNum(layer.y, 0) + 'px '
                    + Math.max(0, hbFxNum(layer.blur, 0)) + 'px ' + hbFxNum(layer.spread, 0) + 'px ' + colour);
                return;
            }
            const amount = Math.min(Number(def.max) || 100, Math.max(0, hbFxNum(layer.amount, 0)));
            const css = def.fn + '(' + amount + def.unit + ')';
            (def.target === 'backdrop' ? backdrops : filters).push(css);
        });
        return { shadow: shadows.join(', '), filter: filters.join(' '), backdrop: backdrops.join(' ') };
    }

    // The fallback for a document with no layer list: read the three real values back into layers.
    function hbFxParse(types, supportsEffects) {
        const layers = [];
        const fx = supportsEffects || {};
        const byFn = (fn, target) => Object.keys(types).find((t) => types[t].fn === fn && types[t].target === target);

        String(fx.shadow || '').split(/,(?![^(]*\))/).map((s) => s.trim()).filter(Boolean).forEach((part) => {
            const inset = /^inset\s/i.test(part);
            const colourMatch = part.match(/(rgba?\([^)]*\)|#[0-9a-f]{3,8})/i);
            const nums = part.replace(/^inset\s+/i, '').replace(colourMatch ? colourMatch[0] : '', '').trim().split(/\s+/).map((n) => hbFxNum(n, 0));
            let color = '#000000';
            let opacity = 100;
            const rgba = colourMatch && colourMatch[0].match(/rgba?\(\s*(\d+)[ ,]+(\d+)[ ,]+(\d+)(?:[ ,/]+([\d.]+))?/i);
            if (rgba) {
                color = '#' + [rgba[1], rgba[2], rgba[3]].map((c) => Number(c).toString(16).padStart(2, '0')).join('');
                opacity = rgba[4] !== undefined ? Math.round(Number(rgba[4]) * 100) : 100;
            } else if (colourMatch) {
                color = colourMatch[0];
            }
            if (!types['drop-shadow']) return;
            // An inset shadow (written by AI or the code view; the panel no longer offers one) is
            // kept as a drop-shadow layer flagged inset, so editing the list never drops it.
            const layer = { type: 'drop-shadow', visible: true, color, opacity, x: nums[0] || 0, y: nums[1] || 0, blur: nums[2] || 0, spread: nums[3] || 0 };
            if (inset) layer.inset = true;
            layers.push(layer);
        });

        [['filter', fx.filter], ['backdrop', fx.backdrop]].forEach(([target, value]) => {
            String(value || '').split(/\s+/).filter(Boolean).forEach((fnCss) => {
                const m = fnCss.match(/^([a-z-]+)\((-?[\d.]+)[a-z%]*\)$/i);
                const type = m && byFn(m[1], target);
                if (type) layers.push({ type, visible: true, amount: Number(m[2]) });
            });
        });
        return layers;
    }

    function hbFxLayersOf(root, model) {
        const types = hbFxTypes(root);
        const effects = hbGet(model?.supports || {}, hbStatePath(root, 'effects')) || {};
        if (Array.isArray(effects.layers)) return effects.layers.filter((l) => l && types[l.type]).map((l) => Object.assign({}, l));
        return hbFxParse(types, effects);
    }

    function hbFxCommit(root, layers) {
        if (!window.hbEditor) return;
        const id = window.hbEditor.getSelectedId();
        if (!id) return;
        const types = hbFxTypes(root);
        const model = window.hbEditor.getModel ? window.hbEditor.getModel(id) : null;
        const declared = window.hbEditor.getContract?.(model?.name)?.supports?.effects || {};
        const composed = hbFxCompose(types, layers);
        window.hbEditor.setSupport(id, hbStatePath(root, 'effects.layers'), layers);
        ['shadow', 'filter', 'backdrop'].forEach((key) => {
            if (declared[key] === true) window.hbEditor.setSupport(id, hbStatePath(root, 'effects.' + key), composed[key]);
        });
        hbFxRender(root, layers);
    }

    function hbFxRender(root, layers) {
        const list = root.querySelector('[data-hb-fx-list]');
        const template = root.querySelector('template[data-hb-fx-row-template]');
        if (!list || !template?.content) return;
        const types = hbFxTypes(root);
        const state = JSON.stringify(layers);
        if (list.dataset.hbFxState === state) return;
        list.textContent = '';
        layers.forEach((layer, index) => {
            const frag = template.content.cloneNode(true);
            const row = frag.querySelector('[data-hb-fx-row]');
            row.dataset.hbFxIndex = String(index);
            row.dataset.hbFxHidden = layer.visible === false ? 'true' : 'false';
            const name = row.querySelector('[data-hb-fx-name]');
            if (name) name.textContent = types[layer.type]?.label || layer.type;
            const eye = row.querySelector('[data-hb-fx-visibility]');
            if (eye) eye.setAttribute('aria-pressed', layer.visible === false ? 'false' : 'true');
            list.appendChild(frag);
        });
        list.dataset.hbFxState = state;
    }

    function hbRebuildEffects(root, model) {
        const sroot = root.matches?.('.hb-blockstyle') ? root : root.querySelector('.hb-blockstyle');
        if (!sroot || !sroot.querySelector('[data-hb-fx-list]')) return;
        const editor = sroot.querySelector('[data-hb-effect]');
        if (editor && editor.contains(document.activeElement)) return;
        hbFxRender(sroot, hbFxLayersOf(sroot, model));
    }

    // Fill the editor popup from one layer and remember which layer it edits.
    function hbFxOpenEditor(root, index) {
        const editor = root.querySelector('[data-hb-effect]');
        const model = window.hbEditor?.getModel?.(window.hbEditor.getSelectedId());
        const layer = hbFxLayersOf(root, model)[index];
        if (!editor || !layer) return;
        const def = hbFxTypes(root)[layer.type] || {};
        root.__hbFxIndex = index;
        const set = (sel, value) => { const el = editor.querySelector(sel); if (el) el.value = String(value ?? ''); };
        const title = editor.querySelector('[data-hb-fx-title]');
        if (title) title.textContent = def.label || layer.type;
        const isShadow = def.target === 'shadow';
        editor.querySelector('[data-hb-fx-fields="shadow"]').hidden = !isShadow;
        editor.querySelector('[data-hb-fx-fields="amount"]').hidden = isShadow;
        if (isShadow) {
            set('[data-hb-fx-color]', layer.color);
            set('[data-hb-fx-opacity]', layer.opacity);
            set('[data-hb-fx-blur]', layer.blur);
            set('[data-hb-fx-x]', layer.x);
            set('[data-hb-fx-y]', layer.y);
            set('[data-hb-fx-spread]', layer.spread);
            const swatch = editor.querySelector('[data-hb-fx-swatch]');
            if (swatch) swatch.style.background = layer.color || '#000000';
        } else {
            set('[data-hb-fx-amount]', layer.amount);
            const unit = editor.querySelector('[data-hb-fx-unit]');
            if (unit) unit.textContent = def.unit;
            const range = editor.querySelector('[data-hb-fx-amount-range]');
            if (range) {
                range.max = String(def.max || 100);
                range.value = String(layer.amount);
            }
        }
    }

    document.addEventListener('click', (event) => {
        const root = mountedStyleRoot(event.target);
        if (!root || !root.querySelector('[data-hb-fx-list]')) return;

        const addButton = event.target.closest('[data-hb-fx-add]');
        if (addButton) {
            showStylePopup(root, 'effect-add', addButton);
            return;
        }

        const addType = event.target.closest('[data-hb-fx-add-type]');
        if (addType) {
            const types = hbFxTypes(root);
            const layer = hbFxNewLayer(types, addType.dataset.hbFxAddType);
            if (!layer) return;
            const model = window.hbEditor?.getModel?.(window.hbEditor.getSelectedId());
            const layers = hbFxLayersOf(root, model);
            layers.push(layer);
            closeStylePopups(root);
            hbFxCommit(root, layers);
            return;
        }

        const row = event.target.closest('[data-hb-fx-row]');
        if (!row) return;
        const index = Number(row.dataset.hbFxIndex);
        const model = window.hbEditor?.getModel?.(window.hbEditor.getSelectedId());

        if (event.target.closest('[data-hb-style-effect-trigger]')) {
            hbFxOpenEditor(root, index);
            return; // the popup itself is opened by the shared effect-trigger handler
        }
        if (event.target.closest('[data-hb-fx-visibility]')) {
            const layers = hbFxLayersOf(root, model);
            if (!layers[index]) return;
            layers[index].visible = layers[index].visible === false;
            hbFxCommit(root, layers);
            return;
        }
        if (event.target.closest('[data-hb-fx-remove]')) {
            const layers = hbFxLayersOf(root, model);
            layers.splice(index, 1);
            if (root.__hbFxIndex === index) closeStylePopups(root);
            root.__hbFxIndex = undefined;
            hbFxCommit(root, layers);
        }
    });

    document.addEventListener('input', (event) => {
        const editor = event.target.closest('[data-hb-effect]');
        if (!editor || !window.hbEditor) return;
        const root = mountedStyleRoot(editor);
        if (!root || !root.querySelector('[data-hb-fx-list]')) return;
        const types = hbFxTypes(root);
        const model = window.hbEditor.getModel?.(window.hbEditor.getSelectedId());
        const layers = hbFxLayersOf(root, model);

        // Typing into the editor with no layer chosen edits the first shadow, creating one if the
        // block has none — the editor opens on a drop shadow, so that is what it shows.
        let index = root.__hbFxIndex;
        if (index === undefined || !layers[index]) {
            index = layers.findIndex((l) => types[l.type]?.target === 'shadow');
            if (index < 0) {
                const created = hbFxNewLayer(types, 'drop-shadow');
                if (!created) return;
                layers.push(created);
                index = layers.length - 1;
            }
            root.__hbFxIndex = index;
        }

        const layer = layers[index];
        const def = types[layer.type] || {};
        const val = (sel) => editor.querySelector(sel)?.value;
        if (def.target === 'shadow') {
            Object.assign(layer, {
                color: String(val('[data-hb-fx-color]') || '#000000').trim(),
                opacity: hbFxNum(val('[data-hb-fx-opacity]'), 100),
                blur: hbFxNum(val('[data-hb-fx-blur]'), 0),
                x: hbFxNum(val('[data-hb-fx-x]'), 0),
                y: hbFxNum(val('[data-hb-fx-y]'), 0),
                spread: hbFxNum(val('[data-hb-fx-spread]'), 0),
            });
            if (!hbShadowRgba(layer.color, layer.opacity)) return; // a half-typed hex: wait for the rest
            const swatch = editor.querySelector('[data-hb-fx-swatch]');
            if (swatch) swatch.style.background = layer.color;
        } else {
            const fromRange = event.target.matches('[data-hb-fx-amount-range]');
            const amount = hbFxNum(fromRange ? val('[data-hb-fx-amount-range]') : val('[data-hb-fx-amount]'), 0);
            layer.amount = amount;
            const other = editor.querySelector(fromRange ? '[data-hb-fx-amount]' : '[data-hb-fx-amount-range]');
            if (other) other.value = String(amount);
        }
        hbFxCommit(root, layers);
    });
