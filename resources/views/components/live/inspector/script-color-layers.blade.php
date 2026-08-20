    // ── Fill / Stroke colour layers, composited (2026-08-05) ─────────────────────────
    // A section holds an ordered STACK of {color, opacity} layers, painted bottom-up in DOM
    // order — the newest sits on top, matching how the add button reads. CSS `color` takes one
    // value, so the stack is flattened here with source-over alpha compositing and the result is
    // what the block's style variable receives.
    //
    // Both shapes are stored: `layers` is the editable stack (so reopening a block restores every
    // layer, not just the flattened result) and the scalar path is the composited colour the
    // renderer already knows how to sanitize. Nothing in the engine had to change for this.
    function hbParseColour(value) {
        let str = String(value || '').trim();
        // A theme-token binding (var(--hb-t-…)) resolves to its computed value so it can
        // participate in compositing; resolve against the canvas page so the block-content
        // palette's own scope (--accent-*, --ink) is visible too.
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

    // A gradient layer's value (BlockRenderer::isSafeGradientValue() validates it server-side;
    // this only needs to RECOGNISE the shape, not re-validate it).
    function hbIsGradientColour(value) {
        return /^(linear|radial)-gradient\(/i.test(String(value || '').trim());
    }

    function hbCompositeLayers(layers) {
        // One fully-opaque token binding passes through verbatim — preserving the var()
        // reference keeps the colour live with the theme instead of a flattened snapshot.
        if (layers.length === 1 && /^var\(--[a-z0-9-]+\)$/i.test(layers[0].color)
            && (!layers[0].opacity || Number(layers[0].opacity) >= 100)) {
            return layers[0].color;
        }
        // A gradient paints its own whole box — there is no CSS syntax to alpha-blend it against
        // the layers beneath in this flattened-scalar model, so the TOPMOST (last) gradient layer
        // wins outright, the same way an opaque solid on top already hides what's under it.
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
            // source-over: this layer paints ON TOP of everything beneath it.
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
        // Opaque results stay hex — shorter, and what a user expects to see round-tripped.
        return out.a >= 1 ? `#${hex(out.r)}${hex(out.g)}${hex(out.b)}`
            : `rgba(${out.r}, ${out.g}, ${out.b}, ${Math.round(out.a * 1000) / 1000})`;
    }

    function hbReadLayers(list) {
        return Array.from(list?.querySelectorAll('.hb-colorlayer') || []).map((row) => ({
            // A gradient row carries its CSS on its own dataset (the hex field is for a flat
            // colour only); a row bound to a theme token displays the token's NAME, its value
            // the reference.
            color: row.dataset.hbStyleGradient || row.dataset.hbVarBound || row.querySelector('.hb-colorlayer__hex')?.value || '',
            // Opacity is a display span fed by the colour picker's alpha, not a typed field.
            opacity: (row.querySelector('[data-hb-style-layer-opacity]')?.textContent || '100').trim(),
        })).filter((layer) => layer.color !== '');
    }

    // Default layer targets: `fill` -> the block's own colour, `stroke` -> its border colour.
    // A panel may OVERRIDE fill's path via data-hb-layer-path on the list (a container's fill
    // is its background, not text colour — see live/block/style/fill.blade.php), so always
    // resolve through hbLayerPathOf() rather than reading this map directly.
    const HB_LAYER_PATHS = { fill: 'color.text', stroke: 'border.color' };
    const hbLayerPathOf = (list, group) => (list && list.dataset.hbLayerPath) || HB_LAYER_PATHS[group];

    // Rebuild the Fill/Stroke stacks from the model. Skipped when the rows already match
    // (covers the echo from hbCommitLayers' own hb:block-updated) and while the user is
    // editing inside the list. A scalar written with no stack (the toolbar colour path)
    // synthesizes one layer so the UI reflects it.
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
                // The hex field is for a flat colour only — a gradient shows its translated
                // tab label there instead of dumping the raw `linear-gradient(...)` text into it.
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
    }

    function hbCommitLayers(root, group) {
        if (!window.hbEditor) return;
        const id = window.hbEditor.getSelectedId();
        const list = root.querySelector(`[data-hb-style-layer-list="${group}"]`);
        const path = hbLayerPathOf(list, group);
        if (!id || !path) return;
        const layers = hbReadLayers(list);
        window.hbEditor.setSupport(id, hbStatePath(root, path), hbCompositeLayers(layers));
        // Sibling of the scalar, so the stack survives a reload while the renderer keeps reading
        // the flattened value it already understands.
        window.hbEditor.setSupport(id, hbStatePath(root, path.split('.')[0] + '.layers'), layers);
    }

    function hbLayerGroupOf(el) {
        const list = el.closest('[data-hb-style-layer-list]');
        return list ? list.getAttribute('data-hb-style-layer-list') : null;
    }

    // Any edit inside a layer row re-flattens its whole stack: a colour, an opacity, a removal,
    // or a token binding all change the composite, not just their own row.
    ['input', 'change'].forEach((type) => document.addEventListener(type, (event) => {
        const row = event.target.closest('.hb-colorlayer');
        if (!row) return;
        const root = mountedStyleRoot(row);
        const group = hbLayerGroupOf(row);
        if (root && group) hbCommitLayers(root, group);
    }));

    // ── Effects: compose one box-shadow from the editor's five fields ──────
    // The model holds the composed CSS string, not five separate paths — that is the shape
    // BlockRenderer's `shadow` sanitizer validates (optional inset, 2-4 signed lengths, exactly
    // one colour) and the only thing box-shadow can consume. Opacity folds into the colour as
    // rgba() rather than being its own declaration, since CSS has no shadow-opacity.
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

    function hbComposeShadow(editor) {
        const num = (sel, fallback) => {
            const v = Number(editor.querySelector(sel)?.value);
            return Number.isFinite(v) ? v : fallback;
        };
        const colour = hbShadowRgba(editor.querySelector('[data-hb-fx-color]')?.value, num('[data-hb-fx-opacity]', 100));
        if (!colour) return null;
        return num('[data-hb-fx-x]', 0) + 'px '
            + num('[data-hb-fx-y]', 0) + 'px '
            + Math.max(0, num('[data-hb-fx-blur]', 0)) + 'px '
            + colour;
    }

    document.addEventListener('input', (event) => {
        const editor = event.target.closest('[data-hb-effect]');
        if (!editor) return;
        const root = mountedStyleRoot(editor);
        if (!root || !window.hbEditor) return;
        const id = window.hbEditor.getSelectedId();
        if (!id) return;
        const css = hbComposeShadow(editor);
        if (css === null) return; // mid-edit hex like "#0" — leave the model alone
        const swatch = editor.querySelector('[data-hb-fx-swatch]');
        if (swatch) swatch.style.background = editor.querySelector('[data-hb-fx-color]')?.value || '#000000';
        window.hbEditor.setSupport(id, hbStatePath(root, 'effects.shadow'), css);
    });

    // ── Block.style theme-variable binding (TODO 7.6 / 7.7) ──────────────────────────
    // Every text-ish Style control gets a trailing selection-all-fill trigger that opens the
    // matching variable-menu. Scoped to .hb-blockstyle deliberately: the requirement is
    // "only for the Block.style sub-tab", and decorating from here expresses that exactly,
    // where a prop on ui/field would put the affordance on every field in the editor.
    //
    // Three states, driven off the field's current value:
    //   bound  — value is a var(--…) reference  -> accent colour, always visible
    //   unset  — value is empty                 -> muted, always visible
    //   manual — value is a literal             -> muted, revealed on hover/focus of the field
    const HB_VAR_TYPES = ['text', 'number'];

    // `var(--hb-t-accent-1)` -> "Accent". A bound field shows the name the user gave the token;
    // the model still holds the reference. The pair is kept apart by data-hb-var-bound on the
    // control: the input carries the LABEL, that attribute carries the VALUE, and the write path
    // prefers it. Typing over the field clears it, so a literal is written literally.
    function hbVarLabelOf(root, ref) {
        if (!root || !ref) return null;
        try {
            return JSON.parse(root.getAttribute('data-hb-var-labels') || '{}')[ref] || null;
        } catch (e) {
            return null;
        }
    }

    // `var(--hb-t-sp-3)` -> "16". The integer the user typed in the Style/Themes panel, with
    // the unit stripped — the field shows this when bound (not the label) so a bound field
    // reads as its VALUE rather than its NAME. Empty when the token has no resolvable
    // number (a hex colour, a font family); callers fall back to the label in that case.
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

    // Colour-ish paths get the swatch menu, font families their own (routing them to var-number
    // would offer spacing tokens), everything else the value menu.
    function hbVarMenuFor(path) {
        if (/fontFamily$/i.test(path)) return 'var-font';
        return /(^|\.)color(\.|$)|color$/i.test(path) ? 'var-color' : 'var-number';
    }

    function hbSyncVarTrigger(control) {
        const button = control.querySelector('[data-hb-style-var-trigger]');
        if (!button) return;
        // A bound field DISPLAYS the token's name, so the visible text reads as a literal.
        // The binding is authoritative; only fall back to inspecting the text without one.
        if (control.dataset.hbVarBound) {
            button.dataset.hbVarState = 'bound';
            return;
        }
        const input = control.matches('input') ? control : control.querySelector('input');
        button.dataset.hbVarState = hbVarStateOf(input?.value);
    }

    const HB_AGGREGATE_FIELDS = '[data-hb-style-all-value], [data-hb-style-padding-axis], [data-hb-style-margin-axis]';

    // Assembled, not a literal — the wiring tests count `data-hb-control="…"` occurrences in
    // the page source to assert each control renders once per block type.
    const hbControlSelector = (path) => '[data-hb-control=' + JSON.stringify(path) + ']';

    // The side controls a spacing aggregate covers: all four for the One-value field, a pair
    // for an H/V axis field. Null when the element is not an aggregate at all.
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

    // Returns 'padding' / 'margin' when the field is a spacing aggregate, else null. The
    // sibling-clearing branch in the typing guard needs only the group to look up its sides —
    // the axis split (left/right vs top/bottom) doesn't matter there, every side gets cleared.
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
        // The One-value and H/V spacing fields carry no data-hb-control (they fan into the
        // side inputs), so they need decorating explicitly or the trigger vanishes the
        // moment the user leaves four-sides mode.
        root.querySelectorAll(HB_AGGREGATE_FIELDS).forEach(decorate);
    }

    document.addEventListener('click', (event) => {
        const trigger = event.target.closest('[data-hb-style-var-trigger]');
        if (!trigger) return;
        const root = mountedStyleRoot(trigger);
        if (!root) return;

        // Three ways a trigger names its target, because it sits differently in each case:
        //   - injected INTO a field  -> it is a descendant of the control
        //   - beside a combobox      -> a sibling, so it names the control explicitly
        //   - inside a colour LAYER  -> the row carries no data-hb-control at all (the stack owns
        //     the write, see hbCommitLayers), so the row itself is the target
        // The layer case is why this button appeared dead: closest('[data-hb-control]') returned
        // null and the handler bailed before opening anything.
        const named = trigger.getAttribute('data-hb-style-var-for');
        const layer = trigger.closest('.hb-colorlayer');
        const aggregate = trigger.closest(HB_AGGREGATE_FIELDS);
        const control = named ? root.querySelector('[data-hb-control="' + named + '"]')
            : (layer || aggregate || trigger.closest('[data-hb-control]'));
        if (!control) return;
        event.stopPropagation();
        root.__hbVarTarget = control;
        // A layer always binds a colour, whatever section it belongs to; a spacing
        // aggregate always binds a length token.
        const menu = layer ? 'var-color'
            : (aggregate && !named ? 'var-number' : hbVarMenuFor(control.getAttribute('data-hb-control') || ''));
        showStylePopup(root, menu, trigger);
    });

    // variable-menu reports the token KEY, which ThemeRepository::tokens() already builds as the
    // CSS value itself (`var(--hb-t-accent-1) => Accent`) — so the selection is written straight
    // through, no name-to-value lookup that could drift from the theme.
    document.addEventListener('varselect', (event) => {
        const popup = event.target.closest('[data-hb-style-popup^="var-"]');
        const root = popup ? mountedStyleRoot(popup) : null;
        const control = root?.__hbVarTarget;
        if (!root || !control) return;
        // `value` is the CSS reference (var(--hb-t-…)); `name` is only the label shown on the row.
        const value = event.detail?.value ?? event.detail?.name ?? '';

        // A combobox owns its display state and only commits through its own API — writing its
        // inner <input> directly would be reverted on its next render, and the delegated write
        // handler ignores events whose target is not the combobox root, so the model would never
        // see the change either.
        const label = hbVarLabelOf(root, value);
        // Bound field shows the token's VALUE (the integer), not its name (the label) — and
        // falls back to the label when the token carries no resolvable number (a hex colour,
        // a font family).
        const resolved = hbVarResolvedValue(root, value) ?? label ?? value;

        // A spacing aggregate fans the token into its covered side controls — they carry the
        // real data-hb-control write paths — then mirrors the binding on itself so the field
        // shows the token value (not "Mixed") and its trigger reads bound.
        const aggregateSides = hbAggregateSideControls(root, control);
        if (aggregateSides) {
            aggregateSides.forEach((side) => {
                const input = side.querySelector('input');
                if (!input) return;
                if (label) side.dataset.hbVarBound = value; else delete side.dataset.hbVarBound;
                input.value = resolved;
                input.dispatchEvent(new Event('input', { bubbles: true }));
                input.dispatchEvent(new Event('change', { bubbles: true }));
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

        // A colour layer holds its value in its own hex field and commits through its stack, so
        // the write goes there rather than to a control path. The hex field is a TEXT input, so
        // the resolved (label / reference) goes there unchanged — for a colour token this is the
        // label "Accent", since colours have no integer to show.
        if (control.classList.contains('hb-colorlayer')) {
            const hex = control.querySelector('.hb-colorlayer__hex');
            if (!hex) return;
            hex.value = resolved;
            if (label) control.dataset.hbVarBound = value; else delete control.dataset.hbVarBound;
            // Paint the swatch with the reference itself, not a resolved hex: the theme's
            // --hb-t-* properties are on the page, so the browser resolves it — and the swatch
            // then tracks the token if its value is later edited in the Style tab.
            const swatch = control.querySelector('.hb-colorlayer__swatch');
            if (swatch) swatch.style.background = value || 'transparent';
            hex.dispatchEvent(new Event('input', { bubbles: true }));
            hbSyncVarTrigger(control);
            closeStylePopups(root);
            return;
        }

        if (control.getAttribute('data-hb-control-type') === 'combobox') {
            // ui/combobox already separates the two: dataset.value is what the model gets, the
            // input text is what the user sees.
            control.__hbCombobox?.setValue(value, resolved);
        } else {
            const input = control.matches('input') ? control : control.querySelector('input');
            if (!input) return;
            // Show the token's value (integer for lengths, label otherwise), remember the
            // reference for the write path.
            if (label) control.dataset.hbVarBound = value;
            else delete control.dataset.hbVarBound;
            input.value = resolved;
            // Re-use the one delegated write path rather than calling setSupport here, so the
            // linked-value/aggregate handlers (spacing, corners) still see the edit.
            input.dispatchEvent(new Event('input', { bubbles: true }));
            input.dispatchEvent(new Event('change', { bubbles: true }));
        }
        hbSyncVarTrigger(control);
        closeStylePopups(root);
    });

    // Typing over a bound field breaks the binding: the text is no longer the token's value, so
    // keeping the reference would silently discard what was typed. Cleared BEFORE the delegated
    // write handler reads it — both listeners are on document and this one is registered first,
    // but the guard does not rely on that: it compares the text to the resolved value (the
    // integer the field actually displays) and falls back to the label, so a stale attribute
    // can never outlive the value it described.
    //
    // Spacing's aggregate fields (data-hb-style-all-value / padding-axis / margin-axis) lack
    // data-hb-control on purpose (see spacing-controls comment), yet still carry the binding
    // when a token is picked — extend the selector to cover them. They live together with
    // per-side controls that hold their OWN data-hb-control and inherited the binding when the
    // token was selected, so clear those siblings too: the typed literal the aggregate fans out
    // does not match the token, and stale side bindings would resurrect the reference on the
    // next syncControls walk.
    document.addEventListener('input', (event) => {
        const control = event.target.closest(
            '[data-hb-control], [data-hb-style-all-value], [data-hb-style-padding-axis], [data-hb-style-margin-axis]'
        );
        if (!control) return;
        const root = mountedStyleRoot(control);
        if (!root) return;
        const bound = control.dataset.hbVarBound;
        if (bound) {
            const input = control.matches('input') ? control : control.querySelector('input');
            const expected = hbVarResolvedValue(root, bound) ?? hbVarLabelOf(root, bound);
            if (input && input.value !== expected) {
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

