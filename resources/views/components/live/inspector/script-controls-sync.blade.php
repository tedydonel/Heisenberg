    // ── tiny dotted-path helpers (mirrors block-runtime.blade.php's own dataGet — that copy is
    //    private to its closure and not part of window.hbEditor, so this is a deliberate, small,
    //    read-only-in-spirit reimplementation, not a divergent one) ─────────────────────────────
    // ── Interaction states ────────────────────────────────────────────────
    // The Style panel's State tabs retarget where every supports-keyed control reads and writes.
    // On `default` a control addresses `supports.<path>` as before; on hover/active/focus it
    // addresses `supports.states.<state>.<path>` — the exact shape
    // BlockRenderer::stateStylesCss() compiles, so an override authored here is what the public
    // page and preview render, with no second format to keep in step.
    //
    // Only supports-keyed controls retarget. Attributes are per-block content, not per-state
    // style, and `stateDeclarations()` already skips any variable not sourced from `supports.`.
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

    // Coerce a raw (always-string-ish) form value back to the attribute's declared type before
    // writing it — without this, e.g. heading's integer `level` would be written as the string
    // "3", and block-runtime's own resolveTag() does a strict `allowed.indexOf(value)` against
    // the contract's (numeric) enum, silently falling back to the first option.
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

    // Mirrors block-runtime.blade.php's own predicateMatches() for `showWhen`/`disableWhen`
    // (the same {attribute, equals|in} shape BlockRegistryService::deriveControls emits) — that
    // function is likewise private to its closure, so this is the same kind of small, necessary
    // reimplementation as hbGet above.
    function evalPredicate(predicate, model) {
        if (!predicate || !predicate.attribute) return true;
        const value = model && model.attributes ? model.attributes[predicate.attribute] : undefined;
        if (Object.prototype.hasOwnProperty.call(predicate, 'equals')) return value === predicate.equals;
        if (Array.isArray(predicate.in)) return predicate.in.indexOf(value) !== -1;
        return true;
    }
    function refreshConditionals(panelRoot, model) {
        panelRoot.querySelectorAll('[data-hb-showwhen]').forEach((row) => {
            try { row.hidden = !evalPredicate(JSON.parse(row.getAttribute('data-hb-showwhen')), model); } catch (e) { /* malformed — never hide */ }
        });
        panelRoot.querySelectorAll('[data-hb-disablewhen]').forEach((row) => {
            let disabled = false;
            try { disabled = !evalPredicate(JSON.parse(row.getAttribute('data-hb-disablewhen')), model); } catch (e) { /* malformed — never disable */ }
            row.querySelectorAll('input, select, textarea, button').forEach((el) => { el.disabled = disabled; });
        });
    }

    // Set a select-type control's DISPLAYED value from the model without going through its own
    // select()/close() (ui/select's boot(), select.blade.php) — that path fires the same
    // 'change' event a user pick does, which our own write-back listener below would then treat
    // as an edit and write straight back (harmless — same value — but pointless and noisy).
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
                valueEl.textContent = valueEl.textContent; // leave the placeholder text as-is
                valueEl.classList.add('hb-select__value--placeholder');
            }
        }
    }

    function syncColorPreview(selectRoot, rawValue) {
        const preview = selectRoot.parentElement ? selectRoot.parentElement.querySelector('[data-hb-color-preview] .hb-swatch__color') : null;
        if (preview) preview.style.background = rawValue || 'transparent';
    }

    // The server-rendered presentation of a Style control, snapshotted before the first model
    // sync ever touches it. When a block has NO value at a control's path, the control must show
    // this pristine default again — the old behavior (skip the sync entirely) kept whatever the
    // PREVIOUSLY selected block left in the shared per-type panel, so block B appeared to carry
    // block A's padding/width/etc., and group-committing controls then wrote those stale values
    // into block B's model on its first edit.
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

    // Populate every [data-hb-control] under `root` (one shown block-panel) with the block's
    // real current value — called right before that panel is unhidden, so nothing visible ever
    // shows a stale/default value (the "re-selecting a block shows its real values" requirement).
    function syncControls(root, model) {
        root.querySelectorAll('[data-hb-control]').forEach((el) => {
            const key = el.getAttribute('data-hb-control');
            const kind = el.getAttribute('data-hb-control-kind');
            const type = el.getAttribute('data-hb-control-type');
            const source = kind === 'supports' ? (model.supports || {}) : (model.attributes || {});
            // On a non-default state tab a supports control reads its own override, not the base
            // value — otherwise every state would open showing the default and overwrite it on
            // the first edit. An `attributes`-kind control reads through window.hbEditor.readAttr
            // (docs/content-translation.md §0/Wave 2) rather than the model directly — a
            // translatable attribute must show whichever locale the canvas is currently editing.
            let value = kind === 'supports'
                ? hbGet(source, hbStatePath(mountedStyleRoot(el) || el.closest('.hb-blockstyle'), key))
                : (window.hbEditor && window.hbEditor.readAttr ? window.hbEditor.readAttr(model, key) : hbGet(source, key));

            // Style panels: snapshot the pristine presentation on first contact (even when a
            // model value is about to overwrite it), and substitute it for an absent path so a
            // stale value from the previously synced block can never survive a selection change.
            if (root.querySelector('.hb-blockstyle')) {
                const pristine = controlPristine(el, type);
                if (value === undefined) value = pristine;
            }

            if (type === 'toggle') {
                const input = el.querySelector('.hb-toggle__input');
                if (input) input.checked = !!value;
                return;
            }
            // ui/checkbox drives two different model shapes. With data-hb-control-on it maps onto
            // a NON-boolean value (the Absolute Position box writes 'absolute' or ''), so
            // "checked" means equalling that string. Without it the model value is a plain
            // boolean attribute (fill/hug/clip), so truthiness is the right test.
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
            // ui/segmented is a tablist: the "value" is which tab carries aria-selected. An
            // unset support deselects every tab rather than defaulting to the first, so an
            // untouched control does not read as a real choice.
            if (type === 'segmented') {
                const text = value == null ? '' : String(value);
                el.querySelectorAll('[data-hb-tab]').forEach((tab) => {
                    tab.setAttribute('aria-selected', tab.dataset.hbTab === text && text !== '' ? 'true' : 'false');
                });
                return;
            }
            // ui/combobox owns its own display state (the input doubles as the search field), so
            // writing input.value directly would be overwritten the next time it re-renders.
            // Show the matching option's LABEL when one exists (e.g. animate 'fade-up' reads
            // "Fade up"); values outside the loaded options (fonts) fall back to themselves.
            if (type === 'combobox') {
                const text = value == null ? '' : String(value);
                let optionLabel = null;
                el.querySelectorAll('[data-hb-combobox-option]').forEach((option) => {
                    if (optionLabel === null && option.dataset.hbComboboxOption === text) {
                        optionLabel = option.querySelector('span')?.textContent.trim() || null;
                    }
                });
                if (el.__hbCombobox?.setValue) el.__hbCombobox.setValue(text, optionLabel || text);
                else {
                    const field = el.querySelector('[data-hb-combobox-input]');
                    if (field) field.value = optionLabel || text;
                    el.dataset.value = text;
                }
                return;
            }
            // Must precede the generic input fallback below — a chips host CONTAINS an
            // <input> (its add-class field), which would otherwise be filled with the whole
            // space-separated class string.
            if (type === 'chips') {
                renderChips(el, String(value == null ? '' : value).split(/\s+/).filter(Boolean));
                return;
            }
            const input = el.matches('input, textarea') ? el : el.querySelector('input, textarea');
            if (input) {
                // Re-derive the binding from the stored value rather than trusting a leftover
                // attribute, so selecting a different block cannot carry the previous one's label.
                const ref = value == null ? '' : String(value);
                const label = hbVarLabelOf(mountedStyleRoot(el) || el.closest('.hb-blockstyle'), ref);
                if (label) el.dataset.hbVarBound = ref;
                else delete el.dataset.hbVarBound;
                input.value = label || ref;
            }
            if (type === 'range') {
                const readout = el.closest('.hb-icol')?.querySelector('[data-hb-range-readout]');
                if (readout) readout.textContent = value == null ? '' : value;
            }
        });
        syncSpacingAggregates(root);
        // Runs after the values are in, so each trigger reads the block's real state rather
        // than the component's rendered default. Idempotent — it skips fields already decorated.
        hbDecorateVarTriggers(root);
        // Fill/Stroke rows are DOM in a panel shared by every block of the same TYPE — rebuild
        // them from this block's model or they show whichever block was edited last (and show
        // nothing after a reload or a toolbar colour write).
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

    // Show the pre-rendered icon matching `name` (or the default placeholder, for the
    // empty/deselected state) — see the header markup's comment for why the icon can't be
    // synced by just setting a text node the way the frozen runtime's updateInspector() does
    // for the name/description.
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
        // The Style panels are shared per block TYPE, and dataset.hbStyleState would otherwise
        // survive a selection change: with the tab stuck on hover/active/focus, every edit on the
        // newly selected block silently retargets supports.states.<state>.* — the canvas shows
        // nothing (no forced preview on this block), and a save "loses" the styling because it
        // only ever applies in that state. A fresh selection always starts on Default. Reset
        // BEFORE showBlockPanels so syncControls reads base paths, and without activate() so the
        // tablist's change event can't cascade into a write.
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

    // Something else changed the selected block (e.g. typing in the canvas, or the floating
    // toolbar) — re-sync the currently-visible panel so it never drifts from the real model,
    // but leave alone whichever control the user is actively focused in (don't fight their typing).
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

    // A focused control keeps that focus while the user clicks the next block on the canvas;
    // the browser fires the field's pending 'change' only after the click already moved the
    // selection, so the delegated write below would land the value typed for block A onto the
    // freshly selected block B. Stamp the selection at focus time and drop any event whose
    // stamp no longer matches; the stamp clears on focusout, so synthetic re-dispatches (the
    // variable menu, commitLinkedSides) on an unfocused control are never affected.
    document.addEventListener('focusin', (event) => {
        const el = event.target.closest ? event.target.closest('[data-hb-control]') : null;
        if (el && window.hbEditor) el.__hbEditsBlock = window.hbEditor.getSelectedId();
    });
    document.addEventListener('focusout', (event) => {
        const el = event.target.closest ? event.target.closest('[data-hb-control]') : null;
        if (el) delete el.__hbEditsBlock;
    });

    // One delegated listener for every control, present or future — the panels above are only
    // ever shown/hidden and value-synced, never rebuilt, so a single document-level listener
    // never needs re-wiring (see this file's docblock).
    function handleControlEvent(event, isChange) {
        const el = event.target.closest('[data-hb-control]');
        if (!el) return;
        const panel = el.closest('[data-hb-block-panel]');
        if (!panel || panel.hidden) return;
        if (!window.hbEditor) return;
        const id = window.hbEditor.getSelectedId();
        if (!id) return;
        if (el.__hbEditsBlock !== undefined && el.__hbEditsBlock !== id) return; // stale trailing edit — see the focusin stamp above
        const model = window.hbEditor.getModel(id);
        if (!model) return;
        const contract = window.hbEditor.getContract(model.name);

        const key = el.getAttribute('data-hb-control');
        const kind = el.getAttribute('data-hb-control-kind');
        const type = el.getAttribute('data-hb-control-type');
        if (!key) return;
        // A chips host contains its own add-class <input>, which is a staging field, not the
        // value — letting the generic branch below read it would write every keystroke into
        // `extraClasses`. Chips commit through writeChips() on Enter/remove instead.
        if (type === 'chips') return;

        let raw;
        if (type === 'toggle') {
            if (!isChange) return; // avoid double-processing the checkbox's paired input+change
            const input = el.querySelector('.hb-toggle__input');
            raw = !!(input && input.checked);
        } else if (type === 'checkbox') {
            if (!isChange) return; // same input+change pairing as toggle
            const checked = !!el.querySelector('.hb-checkbox__input')?.checked;
            const on = el.getAttribute('data-hb-control-on');
            const off = el.getAttribute('data-hb-control-off');
            // With on/off declared, write those strings — the model value is a CSS keyword the
            // sanitizer has to accept (`position-mode` etc.) and `false` is not one. Without
            // them it is a plain boolean attribute, and classNames predicates compare with
            // ===, so an actual boolean is required rather than 'true'.
            raw = (on === null && off === null) ? checked : (checked ? (on ?? 'true') : (off ?? ''));
        } else if (type === 'select') {
            if (event.target !== el) return; // the select's own custom 'change', dispatched on its root
            raw = el.dataset.value;
            syncColorPreview(el, raw);
        } else if (type === 'combobox') {
            // Same shape as select: ui/combobox dispatches its own `change` on its root carrying
            // the committed value. Reading its inner <input> instead would catch every keystroke
            // of a search that may never be committed.
            if (event.target !== el) return;
            raw = el.dataset.value;
        } else if (type === 'segmented') {
            // ui/segmented is a tablist (ui/partials/tablist-script): clicking a tab flips
            // aria-selected and dispatches a bubbling `change` from the tablist root. Read the
            // selected tab rather than event.detail so a programmatic activate() writes too.
            if (event.target !== el) return;
            raw = el.querySelector('[data-hb-tab][aria-selected="true"]')?.dataset.hbTab ?? '';
        } else {
            const input = el.matches('input, textarea') ? el : el.querySelector('input, textarea');
            if (!input) return;
            // A field bound to a theme variable shows the token's NAME but must write its
            // reference. data-hb-var-bound holds the reference and is cleared the moment the
            // user edits the text (see the input listener below), so a literal writes literally.
            raw = el.dataset.hbVarBound || input.value;
            if (type === 'range') {
                const readout = el.closest('.hb-icol')?.querySelector('[data-hb-range-readout]');
                if (readout) readout.textContent = raw;
            }
            if (type === 'number' || type === 'range') raw = raw === '' ? '' : Number(raw);
        }

        if (kind === 'supports') {
            // Style controls are keyed by dotted paths into `supports` (e.g. "color.text"), which
            // setAttribute cannot address — setSupport is its counterpart, and like setAttribute it
            // owns the re-render and both events, so the model has exactly one write path per branch.
            // The active State tab prefixes the path; on `default` it is unchanged.
            window.hbEditor.setSupport(id, hbStatePath(el.closest('.hb-blockstyle'), key), raw);
            return;
        }

        // setAttribute() itself dispatches hb:block-updated (see block-runtime.blade.php), which
        // the listener above already re-syncs/refreshes conditionals from — no need to duplicate
        // that here.
        window.hbEditor.setAttribute(id, key, coerceAttrValue(contract, key, raw));
    }
    document.addEventListener('input', (event) => handleControlEvent(event, false));
    document.addEventListener('change', (event) => handleControlEvent(event, true));

