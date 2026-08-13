    // Anchor field (Content → General): presentation-only side effects layered on top of the
    // generic write path above, never a second way to reach the model.
    //   - duplicate-id awareness: a subtle warning while the typed value collides with another
    //     block's anchor (walked live off window.hbEditor.getDoc() — no server round trip).
    //   - a gentle shape guard on blur: strip spaces to dashes, drop characters the server's
    //     `/^[A-Za-z][\w-]*$/` (PostSettingsController) would reject, and drop a non-letter lead.
    //     Never runs mid-keystroke, so it can't fight typing.
    function anchorControlInput(event) {
        const host = event.target.closest ? event.target.closest('[data-hb-control="anchor"]') : null;
        if (!host) return null;
        const input = host.matches('input, textarea') ? host : host.querySelector('input, textarea');
        return input === event.target ? input : null;
    }

    function normalizeAnchorValue(raw) {
        let value = String(raw ?? '').trim().replace(/\s+/g, '-').replace(/[^\w-]/g, '');
        return value.replace(/^[^A-Za-z]+/, '');
    }

    // Same shape toc-dialog.blade.php's collectHeadings() walks — every block model in the doc,
    // innerBlocks included, minus the one currently being edited.
    function anchorIsDuplicate(value, ownId) {
        if (!value || !window.hbEditor || typeof window.hbEditor.getDoc !== 'function') return false;
        let found = false;
        const walk = (blocks) => {
            (blocks || []).forEach((block) => {
                if (found || !block) return;
                if (block.id !== ownId && block.attributes && block.attributes.anchor === value) { found = true; return; }
                if (Array.isArray(block.innerBlocks)) walk(block.innerBlocks);
            });
        };
        walk(window.hbEditor.getDoc().blocks);
        return found;
    }

    function setAnchorWarning(host, warn) {
        host.classList.toggle('hb-input--warning', !!warn);
        const notice = host.closest('.hb-icol')?.querySelector('[data-hb-anchor-warning]');
        if (notice) notice.hidden = !warn;
    }

    document.addEventListener('input', (event) => {
        const input = anchorControlInput(event);
        if (!input || !window.hbEditor) return;
        const host = input.closest('[data-hb-control="anchor"]');
        setAnchorWarning(host, anchorIsDuplicate(input.value.trim(), window.hbEditor.getSelectedId()));
    });

    // 'focusout' (not 'blur') so one delegated listener can catch every anchor field, matching
    // the pattern the focusin/focusout pair above already uses — 'blur' does not bubble.
    document.addEventListener('focusout', (event) => {
        const input = anchorControlInput(event);
        if (!input) return;
        const normalized = normalizeAnchorValue(input.value);
        if (normalized !== input.value) {
            input.value = normalized;
            // Replays through the real write path (handleControlEvent) rather than calling
            // setAttribute directly, so var-binding bookkeeping and hb:block-updated stay
            // exactly as they would for a manual edit — same technique as commitLinkedSides.
            input.dispatchEvent(new Event('input', { bubbles: true }));
            input.dispatchEvent(new Event('change', { bubbles: true }));
        }
        const host = input.closest('[data-hb-control="anchor"]');
        if (window.hbEditor) setAnchorWarning(host, anchorIsDuplicate(normalized, window.hbEditor.getSelectedId()));
    });

    // Style compositions with local visual state above the reusable inputs (3x3 flex target,
    // gap radios, linked padding, expandable side grids, layer stacks) — keep their transitions
    // inside the mounted sidebar so selecting another block never freezes the presentation.
    function mountedStyleRoot(target) {
        const root = target.closest('.hb-blockstyle');
        return root && !root.closest('[hidden]') ? root : null;
    }

    function styleFieldInput(field) {
        return field?.matches('input') ? field : field?.querySelector('input');
    }

    function styleFields(root, selector) {
        return Array.from(root.querySelectorAll(selector));
    }

    function visibleStyleFields(root, group) {
        return styleFields(root, `[data-hb-style-side-value="${group}"]`)
            .filter((field) => !field.closest('[hidden]'));
    }

    function syncStyleLinkedValue(root, group) {
        const all = root.querySelector(`[data-hb-style-all-value="${group}"]`);
        const allInput = styleFieldInput(all);
        const sides = visibleStyleFields(root, group).map(styleFieldInput).filter(Boolean);
        if (!all || !allInput || !sides.length) return;

        const values = sides.map((input) => input.value);
        const mixed = values.some((value) => value !== values[0]);
        all.dataset.hbStyleMixed = mixed ? 'true' : 'false';
        allInput.classList.toggle('hb-field__value--mixed', mixed);
        allInput.setAttribute('aria-label', mixed ? 'Mixed values' : 'All values');
        allInput.value = mixed ? 'Mixed' : values[0];
    }

    function setStyleLinkedValue(root, group, value) {
        styleFields(root, `[data-hb-style-side-value="${group}"]`).forEach((field) => {
            const input = styleFieldInput(field);
            if (input) input.value = value;
        });
        const all = root.querySelector(`[data-hb-style-all-value="${group}"]`);
        const allInput = styleFieldInput(all);
        if (!all || !allInput) return;
        all.dataset.hbStyleMixed = 'false';
        allInput.classList.remove('hb-field__value--mixed');
        allInput.setAttribute('aria-label', 'All values');
        allInput.value = value;
    }

    // Aggregates whose per-side fields carry their OWN data-hb-control (Stroke's
    // stroke-sides, Appearance's appearance-corners) commit by replaying the shared
    // control path on each side — no second write path, so var-binding and state tabs
    // keep working. Spacing is the exception: its sides commit as one object through
    // commitSpacingGroup(), which is why it isn't routed here.
    function commitLinkedSides(root, group) {
        styleFields(root, `[data-hb-style-side-value="${group}"]`).forEach((field) => {
            if (!field.hasAttribute('data-hb-control')) return;
            const input = styleFieldInput(field);
            if (!input) return;
            input.dispatchEvent(new Event('input', { bubbles: true }));
            input.dispatchEvent(new Event('change', { bubbles: true }));
        });
    }

    // The non-spacing linked groups, kept in one place so sync and commit agree.
    const HB_LINKED_GROUPS = ['stroke-sides', 'appearance-corners'];

    function closeStylePopups(root, except = null) {
        root?.querySelectorAll('[data-hb-style-popup]').forEach((popup) => {
            if (popup !== except) popup.hidden = true;
        });
        root?.querySelectorAll('[data-hb-style-color-trigger], [data-hb-style-popup-trigger], [data-hb-style-effect-trigger], [data-hb-style-var-trigger], [data-cp-gradient-stop-select]').forEach((trigger) => {
            if (!except || !except.contains(trigger)) trigger.setAttribute('aria-expanded', 'false');
        });
    }

    // Shared "anchor a floating .hb-pop popup to its trigger, clamped inside the viewport"
    // math — used by both showStylePopup() (which also closes every OTHER popup, since those
    // are mutually exclusive menus) and showNestedStylePopup() below (which deliberately does
    // NOT, because a gradient stop's colour editor is meant to stay open alongside the gradient
    // popup it belongs to, not replace it).
    function positionStylePopup(popup, trigger) {
        const rect = trigger.getBoundingClientRect();
        const width = popup.offsetWidth;
        const height = popup.offsetHeight;
        const left = Math.max(8, Math.min(window.innerWidth - width - 8, rect.right - width));
        const below = rect.bottom + 8;
        const top = below + height <= window.innerHeight - 8
            ? below
            : Math.max(8, rect.top - height - 8);
        popup.style.left = `${left}px`;
        popup.style.top = `${top}px`;
    }

    function showStylePopup(root, name, trigger) {
        const popup = root.querySelector(`[data-hb-style-popup="${name}"]`);
        if (!popup) return;
        const wasOpen = !popup.hidden;
        closeStylePopups(root, popup);
        popup.hidden = wasOpen;
        trigger.setAttribute('aria-expanded', wasOpen ? 'false' : 'true');
        if (wasOpen) return;
        positionStylePopup(popup, trigger);
    }

    // Opens (or repositions) a popup WITHOUT closing its sibling popups — the gradient-stop
    // editor's own trigger lives INSIDE the "color" popup it must stay stacked alongside.
    function showNestedStylePopup(root, name, trigger) {
        const popup = root.querySelector(`[data-hb-style-popup="${name}"]`);
        if (!popup) return;
        popup.hidden = false;
        trigger.setAttribute('aria-expanded', 'true');
        positionStylePopup(popup, trigger);
    }

    function setStyleFieldPresentation(field, values, label) {
        const input = styleFieldInput(field);
        if (!field || !input || !values.length) return;
        const mixed = values.some((value) => value !== values[0]);
        field.dataset.hbStyleMixed = mixed ? 'true' : 'false';
        input.classList.toggle('hb-field__value--mixed', mixed);
        input.setAttribute('aria-label', mixed ? `Mixed ${label}` : label);
        input.value = mixed ? 'Mixed' : values[0];
    }

    function paddingSideInputs(root) {
        const names = ['left', 'right', 'top', 'bottom'];
        return Object.fromEntries(names.map((name) => [
            name,
            styleFieldInput(root.querySelector(`[data-hb-style-padding-side="${name}"]`)),
        ]));
    }

    function syncPaddingControls(root) {
        const sides = paddingSideInputs(root);
        if (Object.values(sides).some((input) => !input)) return;
        setStyleFieldPresentation(
            root.querySelector('[data-hb-style-all-value="padding"]'),
            [sides.left.value, sides.right.value, sides.top.value, sides.bottom.value],
            'all padding values',
        );
        setStyleFieldPresentation(
            root.querySelector('[data-hb-style-padding-axis="horizontal"]'),
            [sides.left.value, sides.right.value],
            'horizontal padding values',
        );
        setStyleFieldPresentation(
            root.querySelector('[data-hb-style-padding-axis="vertical"]'),
            [sides.top.value, sides.bottom.value],
            'vertical padding values',
        );
    }

    function setPaddingAxisValue(root, axis, value) {
        const sides = paddingSideInputs(root);
        const names = axis === 'horizontal' ? ['left', 'right'] : ['top', 'bottom'];
        names.forEach((name) => { if (sides[name]) sides[name].value = value; });
        syncPaddingControls(root);
    }

    function setPaddingMode(root, index) {
        const mode = ['one', 'two', 'four'][index] || 'four';
        root.dataset.hbStylePaddingMode = mode;
        root.querySelectorAll('[data-hb-style-padding-mode]').forEach((row) => {
            row.hidden = row.dataset.hbStylePaddingMode !== mode;
        });
        syncPaddingControls(root);
    }

    // ── Margin: mirrors the four Padding functions above exactly (own attribute vocabulary —
    // data-hb-style-margin-*, not a `group` parameter on the padding ones — so Margin is
    // additive here and Padding's own code path is untouched). ──
    function marginSideInputs(root) {
        const names = ['left', 'right', 'top', 'bottom'];
        return Object.fromEntries(names.map((name) => [
            name,
            styleFieldInput(root.querySelector(`[data-hb-style-margin-side="${name}"]`)),
        ]));
    }

    function syncMarginControls(root) {
        const sides = marginSideInputs(root);
        if (Object.values(sides).some((input) => !input)) return;
        setStyleFieldPresentation(
            root.querySelector('[data-hb-style-all-value="margin"]'),
            [sides.left.value, sides.right.value, sides.top.value, sides.bottom.value],
            'all margin values',
        );
        setStyleFieldPresentation(
            root.querySelector('[data-hb-style-margin-axis="horizontal"]'),
            [sides.left.value, sides.right.value],
            'horizontal margin values',
        );
        setStyleFieldPresentation(
            root.querySelector('[data-hb-style-margin-axis="vertical"]'),
            [sides.top.value, sides.bottom.value],
            'vertical margin values',
        );
    }

    function setMarginAxisValue(root, axis, value) {
        const sides = marginSideInputs(root);
        const names = axis === 'horizontal' ? ['left', 'right'] : ['top', 'bottom'];
        names.forEach((name) => { if (sides[name]) sides[name].value = value; });
        syncMarginControls(root);
    }

    function setMarginMode(root, index) {
        const mode = ['one', 'two', 'four'][index] || 'four';
        root.dataset.hbStyleMarginMode = mode;
        root.querySelectorAll('[data-hb-style-margin-mode]').forEach((row) => {
            row.hidden = row.dataset.hbStyleMarginMode !== mode;
        });
        syncMarginControls(root);
    }

    // ── Spacing → model (2026-08-04) ──────────────────────────────────────────────────
    // The four per-side fields carry their own data-hb-control and write through the shared
    // delegated listener like every other Style control. The "one value" / "horizontal-vertical"
    // fields cannot: one input owning two or four model paths is not something a single
    // data-hb-control can express. Both aggregate modes already fan their value out into the four
    // side INPUTS (setPaddingAxisValue/setStyleLinkedValue, above) — so by the time this runs the
    // side inputs are the source of truth for all three modes, and one write of the whole
    // `spacing.{group}` object covers every mode identically.
    //
    // Writing the object rather than four separate paths is deliberate: setSupport re-renders the
    // block on every call, so four calls per keystroke would rebuild the block's DOM four times
    // while the user types. setSupport's path walker assigns whatever value it is handed, and
    // BlockRenderer resolves `supports.spacing.padding.top` through dataGet, so an object written
    // at `spacing.padding` reads back identically to four scalar writes.
    function commitSpacingGroup(root, group) {
        if (!window.hbEditor) return;
        const id = window.hbEditor.getSelectedId();
        if (!id) return;
        const sides = group === 'padding' ? paddingSideInputs(root) : marginSideInputs(root);
        if (Object.values(sides).some((input) => !input)) return;
        // hbStatePath, like the per-side controls' own writes: on a non-default State tab the
        // aggregate modes must retarget states.<state>.spacing.* too, or "one value" padding
        // authored on Hover would silently write the BASE style instead.
        window.hbEditor.setSupport(id, hbStatePath(root, 'spacing.' + group), {
            top: sides.top.value,
            right: sides.right.value,
            bottom: sides.bottom.value,
            left: sides.left.value,
        });
    }

    // Aggregate fields hold no model path of their own, so syncControls (which walks
    // [data-hb-control]) leaves them untouched on selection. Re-derive them from the freshly
    // synced side inputs, or re-selecting a block would show its real per-side values under a
    // stale "all sides" summary.
    function syncSpacingAggregates(root) {
        // Guarded per group: a contract may declare only one of padding/margin
        // (column has no margin, embed no padding) and the other's DOM is absent.
        if (root.querySelector('[data-hb-style-padding-side]')) syncPaddingControls(root);
        if (root.querySelector('[data-hb-style-margin-side]')) syncMarginControls(root);
        // Stroke weight + corner radius: their per-side fields were just filled from the
        // model by syncControls, so the "all" field summarises them (or reads Mixed).
        HB_LINKED_GROUPS.forEach((group) => {
            if (root.querySelector(`[data-hb-style-side-value="${group}"]`)) syncStyleLinkedValue(root, group);
        });
    }

    // Flex mode segmented (the extracted wrap/column/row control): ONE control, TWO model
    // paths — wrap ≡ direction=row + flex-wrap=wrap; column/row set that direction, wrap off.
    document.addEventListener('change', (event) => {
        const mode = event.target.closest('[data-hb-style-flexmode]');
        if (!mode || event.target !== mode || !event.detail) return;
        if (!mountedStyleRoot(mode) || !window.hbEditor) return;
        const id = window.hbEditor.getSelectedId();
        if (!id) return;
        const value = String(event.detail.value || '');
        if (['wrap', 'column', 'row'].indexOf(value) === -1) return;
        const style = mode.closest('.hb-blockstyle');
        window.hbEditor.setSupport(id, hbStatePath(style, 'layout.direction'), value === 'column' ? 'column' : 'row');
        window.hbEditor.setSupport(id, hbStatePath(style, 'layout.wrap'), value === 'wrap' ? 'wrap' : '');
    });

    // Sync the flex section's bespoke controls (mode segmented, 3×3 grid, spacing radios)
    // from the model — the counterpart of syncControls' generic branches for controls that
    // address two paths at once and therefore carry no data-hb-control.
    function syncFlexControls(root, model) {
        const grid = root.querySelector('[data-hb-style-alignment-grid]');
        const mode = root.querySelector('[data-hb-style-flexmode]');
        const radios = root.querySelectorAll('[data-hb-flex-spacing]');
        if (!grid && !mode && !radios.length) return;
        const style = root.querySelector('.hb-blockstyle') || root.closest('.hb-blockstyle');
        const read = (path) => {
            const value = hbGet(model.supports || {}, hbStatePath(style, path));
            return value == null ? '' : String(value);
        };
        const direction = read('layout.direction');
        const wrap = read('layout.wrap');
        const justify = read('layout.justify');
        const align = read('layout.align');
        if (mode) {
            const value = wrap === 'wrap' ? 'wrap' : direction;
            mode.querySelectorAll('[data-hb-tab]').forEach((tab) => {
                tab.setAttribute('aria-selected', value !== '' && tab.dataset.hbTab === value ? 'true' : 'false');
            });
        }
        if (grid) {
            const spacing = justify === 'space-between' || justify === 'space-around';
            grid.querySelectorAll('[data-hb-style-alignment]').forEach((dot) => {
                const on = align !== '' && dot.dataset.hbFlexAlign === align
                    && (spacing ? dot.dataset.hbFlexJustify === 'center' : (justify !== '' && dot.dataset.hbFlexJustify === justify));
                dot.classList.toggle('hb-agrid__dot--on', on);
                dot.classList.toggle('is-active', on);
                dot.setAttribute('aria-pressed', on ? 'true' : 'false');
            });
        }
        radios.forEach((radio) => {
            const key = radio.dataset.hbFlexSpacing;
            const on = key === 'packed' ? (justify !== 'space-between' && justify !== 'space-around') : justify === key;
            radio.classList.toggle('hb-iradio--on', on);
            radio.classList.toggle('is-active', on);
            radio.setAttribute('aria-checked', on ? 'true' : 'false');
            const dotEl = radio.querySelector('.hb-iradio__dot');
            if (dotEl) dotEl.classList.toggle('hb-iradio__dot--on', on);
        });
    }

    // State tab switch: retarget the panel, re-read every control against the new state, and ask
    // the canvas to force that state's look on the selected block so the edit is visible.
    document.addEventListener('change', (event) => {
        const tabs = event.target.closest('[data-hb-style-state]');
        // Only the state tablist's OWN change may retarget — selects and segmented controls
        // bubble a look-alike change (detail.value) that must never become a "state" — and
        // the value is allowlisted so nothing else can ever be written as one.
        if (!tabs || event.target !== tabs) return;
        const root = mountedStyleRoot(tabs);
        if (!root || !event.detail) return;
        const value = String(event.detail.value || 'default');
        root.dataset.hbStyleState = ['default', 'hover', 'active', 'focus'].indexOf(value) !== -1 ? value : 'default';

        if (!window.hbEditor) return;
        const id = window.hbEditor.getSelectedId();
        const model = id ? window.hbEditor.getModel(id) : null;
        if (model) syncControls(root.closest('[data-hb-block-panel]') || root, model);
        window.hbEditor.previewState?.(id, root.dataset.hbStyleState);
    });

