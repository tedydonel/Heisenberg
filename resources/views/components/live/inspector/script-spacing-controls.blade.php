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

    document.addEventListener('focusout', (event) => {
        const input = anchorControlInput(event);
        if (!input) return;
        const normalized = normalizeAnchorValue(input.value);
        if (normalized !== input.value) {
            input.value = normalized;
            input.dispatchEvent(new Event('input', { bubbles: true }));
            input.dispatchEvent(new Event('change', { bubbles: true }));
        }
        const host = input.closest('[data-hb-control="anchor"]');
        if (window.hbEditor) setAnchorWarning(host, anchorIsDuplicate(normalized, window.hbEditor.getSelectedId()));
    });

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

    function commitLinkedSides(root, group) {
        styleFields(root, `[data-hb-style-side-value="${group}"]`).forEach((field) => {
            if (!field.hasAttribute('data-hb-control')) return;
            const input = styleFieldInput(field);
            if (!input) return;
            input.dispatchEvent(new Event('input', { bubbles: true }));
            input.dispatchEvent(new Event('change', { bubbles: true }));
        });
    }

    const HB_LINKED_GROUPS = ['stroke-sides', 'appearance-corners'];

    function closeStylePopups(root, except = null) {
        root?.querySelectorAll('[data-hb-style-popup]').forEach((popup) => {
            if (popup !== except) popup.hidden = true;
        });
        root?.querySelectorAll('[data-hb-style-color-trigger], [data-hb-style-popup-trigger], [data-hb-style-effect-trigger], [data-hb-style-var-trigger], [data-cp-gradient-stop-select]').forEach((trigger) => {
            if (!except || !except.contains(trigger)) trigger.setAttribute('aria-expanded', 'false');
        });
    }

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

    function commitSpacingGroup(root, group) {
        if (!window.hbEditor) return;
        const id = window.hbEditor.getSelectedId();
        if (!id) return;
        const sides = group === 'padding' ? paddingSideInputs(root) : marginSideInputs(root);
        if (Object.values(sides).some((input) => !input)) return;
        window.hbEditor.setSupport(id, hbStatePath(root, 'spacing.' + group), {
            top: sides.top.value,
            right: sides.right.value,
            bottom: sides.bottom.value,
            left: sides.left.value,
        });
    }

    function syncSpacingAggregates(root) {
        if (root.querySelector('[data-hb-style-padding-side]')) syncPaddingControls(root);
        if (root.querySelector('[data-hb-style-margin-side]')) syncMarginControls(root);
        HB_LINKED_GROUPS.forEach((group) => {
            if (root.querySelector(`[data-hb-style-side-value="${group}"]`)) syncStyleLinkedValue(root, group);
        });
    }

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

    document.addEventListener('change', (event) => {
        const tabs = event.target.closest('[data-hb-style-state]');
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

