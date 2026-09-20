{{--
    Section: canvas block drag-and-drop.

    Owns: the generic drop-target resolvers shared by both drag sources (resolveDropItem,
    clearDropMarks); the canvas auto-scroll loop (autoScrollRAF/autoScrollY, autoScrollTick,
    startAutoScroll/stopAutoScroll); overCanvas(); and wireCanvasBlockDrag() — the pointer-event
    pipeline for dragging an EXISTING block (by its toolbar grip or its body) to reorder it or
    drop it inside another container, including the "hovering over a nested container" inside-drop
    affordance.

    Depends on: wrapEl() (02-doc-model); selected (02-doc-model, read-only here); findModel()
    (02-doc-model); moveBlockTo() (08-tree-ops); indexOf() (07-toolbar-and-selection);
    containerAt()/resolveInsideDrop() — defined later in 10-palette-and-boot, but only referenced
    inside event handlers that run after boot.

    Defines for later sections: resolveDropItem(), clearDropMarks(), autoScrollRAF/autoScrollY
    (let, mutated by the resize/palette drag handlers in 10-palette-and-boot too),
    startAutoScroll(), stopAutoScroll(), overCanvas(), wireCanvasBlockDrag() — the resize handling
    in 10-palette-and-boot's wireContainerResize() and the boot() wiring both call into this
    section's globals.
--}}
    function resolveDropItem(container, itemSelector, excludeEl, clientX, clientY) {
        if (!container) return null;
        const items = Array.prototype.filter.call(container.querySelectorAll(itemSelector), (it) => it !== excludeEl);
        if (!items.length) return null;
        const hitEl = document.elementFromPoint(clientX, clientY);
        const hit = hitEl && hitEl.closest ? hitEl.closest(itemSelector) : null;
        if (hit && hit !== excludeEl && container.contains(hit)) {
            const r = hit.getBoundingClientRect();
            return { el: hit, below: clientY > r.top + r.height / 2 };
        }
        const firstR = items[0].getBoundingClientRect();
        if (clientY <= firstR.top + firstR.height / 2) return { el: items[0], below: false };
        for (let i = 0; i < items.length; i++) {
            const r = items[i].getBoundingClientRect();
            if (clientY < r.top + r.height / 2) return { el: items[i], below: false };
        }
        return { el: items[items.length - 1], below: true };
    }
    function clearDropMarks(container, itemSelector) {
        if (!container) return;
        container.querySelectorAll(itemSelector).forEach((it) => it.classList.remove('is-drop-before', 'is-drop-after'));
    }

    let autoScrollRAF = null;
    let autoScrollY = 0;
    function autoScrollTick() {
        const cv = document.querySelector('.hb-canvas');
        if (!cv) { autoScrollRAF = null; return; }
        const r = cv.getBoundingClientRect();
        const edge = 56, maxSpeed = 18;
        let dy = 0;
        if (autoScrollY < r.top + edge) dy = -maxSpeed * ((r.top + edge - autoScrollY) / edge);
        else if (autoScrollY > r.bottom - edge) dy = maxSpeed * ((autoScrollY - (r.bottom - edge)) / edge);
        if (dy) cv.scrollTop += dy;
        autoScrollRAF = requestAnimationFrame(autoScrollTick);
    }
    function startAutoScroll() { if (!autoScrollRAF) autoScrollRAF = requestAnimationFrame(autoScrollTick); }
    function stopAutoScroll() { if (autoScrollRAF) { cancelAnimationFrame(autoScrollRAF); autoScrollRAF = null; } }

    function overCanvas(x, y) {
        const el = document.elementFromPoint(x, y);
        return !!(el && el.closest && el.closest('.hb-canvas'));
    }

    function wireCanvasBlockDrag() {
        var noDrag = '.hb-ce, a, button, input, textarea, select, label, [data-hb-inner-appender], .hb-img-empty, [data-image-picker], .hb-tb';
        document.addEventListener('pointerdown', (e) => {
            if (e.button != null && e.button !== 0) return;
            const wrap = wrapEl();
            if (!wrap) return;
            const grip = e.target.closest && e.target.closest('.hb-tb__btn--drag');
            let blk = null;
            let pressEl = null;
            if (grip) {
                blk = selected;
                pressEl = grip;
            } else {
                pressEl = e.target.closest && e.target.closest('.hb-blk');
                if (!pressEl || !wrap.contains(pressEl)) return;
                if (e.target.closest(noDrag)) return;
                blk = pressEl;
            }
            if (!blk) return;
            const id = blk.getAttribute('data-block');
            const model = id ? findModel(id) : null;
            if (!model) return;
            e.preventDefault();
            select(blk);
            try { pressEl.setPointerCapture(e.pointerId); } catch (err) { }
            const startX = e.clientX, startY = e.clientY;
            let active = false;
            let hover = null;
            let inside = null;
            let insideEl = null;
            let insideMark = null;

            function clearInsideMarks() {
                if (insideEl) { insideEl.classList.remove('is-drop-inside'); insideEl = null; }
                if (insideMark) { insideMark.classList.remove('is-drop-before', 'is-drop-after'); insideMark = null; }
            }
            function onMove(ev) {
                autoScrollY = ev.clientY;
                if (!active) {
                    if (Math.abs(ev.clientX - startX) + Math.abs(ev.clientY - startY) < 5) return;
                    active = true;
                    blk.classList.add('is-dragging');
                    document.body.classList.add('hb-canvas-drag');
                    startAutoScroll();
                }
                clearDropMarks(wrap, ':scope > .hb-blk');
                clearInsideMarks();
                hover = null;
                inside = null;
                if (!overCanvas(ev.clientX, ev.clientY)) return;
                const target = containerAt(ev.clientX, ev.clientY, model.name);
                if (target && target.blk !== blk && !blk.contains(target.blk)) {
                    const rootEl = target.blk.querySelector(':scope > [data-block-id]') || target.blk;
                    insideEl = rootEl;
                    rootEl.classList.add('is-drop-inside');
                    const slot = resolveInsideDrop(rootEl, ev.clientY);
                    inside = { id: target.model.id, index: slot.index };
                    if (slot.markEl) {
                        insideMark = slot.markEl;
                        insideMark.classList.add(slot.below ? 'is-drop-after' : 'is-drop-before');
                    }
                    return;
                }
                hover = resolveDropItem(wrap, ':scope > .hb-blk', blk, ev.clientX, ev.clientY);
                if (hover) hover.el.classList.add(hover.below ? 'is-drop-after' : 'is-drop-before');
            }
            function cleanup() {
                pressEl.removeEventListener('pointermove', onMove);
                pressEl.removeEventListener('pointerup', onUp);
                pressEl.removeEventListener('pointercancel', onCancel);
                blk.classList.remove('is-dragging');
                document.body.classList.remove('hb-canvas-drag');
                clearDropMarks(wrap, ':scope > .hb-blk');
                clearInsideMarks();
                stopAutoScroll();
            }
            function onUp() {
                if (active) {
                    if (inside) {
                        moveBlockTo(id, inside.id, inside.index);
                    } else if (hover) {
                        const hoverIndex = indexOf(hover.el.getAttribute('data-block'));
                        if (hoverIndex !== -1) moveBlockTo(id, null, hover.below ? hoverIndex + 1 : hoverIndex);
                    }
                }
                cleanup();
            }
            function onCancel() { cleanup(); }
            pressEl.addEventListener('pointermove', onMove);
            pressEl.addEventListener('pointerup', onUp);
            pressEl.addEventListener('pointercancel', onCancel);
        });
    }

    const RESIZE_BAND = 6;
    const RESIZE_MIN = 24;
    function resizeHitAt(target, x, y) {
        const hits = [];
        let blk = target && target.closest ? target.closest('.hb-blk') : null;
        while (blk) {
            const model = findModel(blk.getAttribute('data-block'));
            const c = model ? REGISTRY[model.name] : null;
            const size = c && c.supports ? c.supports.size : null;
            const root = blk.querySelector(':scope > [data-block-id]');
            if (model && size && root) {
                const canW = size.width === true;
                const canH = size.height === true;
                if (canW || canH) {
                    const r = root.getBoundingClientRect();
                    const inX = x >= r.left && x <= r.right;
                    const inY = y >= r.top && y <= r.bottom;
                    const onRight = canW && inY && x <= r.right && r.right - x <= RESIZE_BAND;
                    const onBottom = canH && inX && y <= r.bottom && r.bottom - y <= RESIZE_BAND;
                    if (onRight || onBottom) {
                        hits.push({ blk: blk, model: model, contract: c, root: root, rect: r, w: onRight, h: onBottom });
                    }
                }
            }
            blk = blk.parentElement ? blk.parentElement.closest('.hb-blk') : null;
        }
        if (!hits.length) return null;
        for (let i = 0; i < hits.length; i++) { if (hits[i].blk === selected) return hits[i]; }
        return hits[0];
    }
    function clearResizeCursor() {
        document.querySelectorAll('.hb-resize-ew, .hb-resize-ns, .hb-resize-nwse').forEach(function (el) {
            el.classList.remove('hb-resize-ew', 'hb-resize-ns', 'hb-resize-nwse');
        });
    }
    function wireContainerResize() {
        let resizing = false;
        document.addEventListener('mousemove', (e) => {
            if (resizing) return;
            if (!e.target.closest || !e.target.closest('.hb-page__blocks')) { clearResizeCursor(); return; }
            const hit = resizeHitAt(e.target, e.clientX, e.clientY);
            clearResizeCursor();
            if (hit) hit.root.classList.add(hit.w && hit.h ? 'hb-resize-nwse' : (hit.w ? 'hb-resize-ew' : 'hb-resize-ns'));
        });
        document.addEventListener('pointerdown', (e) => {
            if (e.button != null && e.button !== 0) return;
            if (!e.target.closest || !e.target.closest('.hb-page__blocks') || e.target.closest('.hb-tb')) return;
            const hit = resizeHitAt(e.target, e.clientX, e.clientY);
            if (!hit) return;
            e.preventDefault();
            e.stopPropagation();
            resizing = true;
            select(hit.blk);
            try { hit.root.setPointerCapture(e.pointerId); } catch (err) { }
            const startX = e.clientX, startY = e.clientY;
            const startW = hit.rect.width, startH = hit.rect.height;
            const size = () => {
                if (!hit.model.supports || typeof hit.model.supports !== 'object') hit.model.supports = {};
                if (typeof hit.model.supports.size !== 'object' || hit.model.supports.size === null) hit.model.supports.size = {};
                return hit.model.supports.size;
            };
            const before = { width: size().width, height: size().height };
            let wrote = false;
            function apply(ev) {
                if (hit.w) size().width = Math.max(RESIZE_MIN, Math.round(startW + ev.clientX - startX)) + 'px';
                if (hit.h) size().height = Math.max(RESIZE_MIN, Math.round(startH + ev.clientY - startY)) + 'px';
                wrote = true;
                const declarations = styleDeclarations(hit.model, hit.contract);
                if (declarations) hit.root.setAttribute('style', declarations);
            }
            function cleanup() {
                hit.root.removeEventListener('pointermove', apply);
                hit.root.removeEventListener('pointerup', onUp);
                hit.root.removeEventListener('pointercancel', onCancel);
                resizing = false;
            }
            function onUp() {
                cleanup();
                if (!wrote) return;
                const id = hit.model.id;
                if (hit.w) setSupport(id, 'size.width', size().width);
                if (hit.h) setSupport(id, 'size.height', size().height);
            }
            function onCancel() {
                if (wrote) {
                    size().width = before.width;
                    size().height = before.height;
                    const declarations = styleDeclarations(hit.model, hit.contract);
                    if (declarations) hit.root.setAttribute('style', declarations);
                }
                cleanup();
            }
            hit.root.addEventListener('pointermove', apply);
            hit.root.addEventListener('pointerup', onUp);
            hit.root.addEventListener('pointercancel', onCancel);
        }, true);
    }

    function containerAt(x, y, name) {
        const hit = document.elementFromPoint(x, y);
        let blk = hit && hit.closest ? hit.closest('.hb-blk') : null;
        while (blk) {
            const model = findModel(blk.getAttribute('data-block'));
            if (model && containerAllows(model, name)) return { blk: blk, model: model };
            blk = blk.parentElement ? blk.parentElement.closest('.hb-blk') : null;
        }
        return null;
    }

    function resolveInsideDrop(rootEl, y) {
        const items = Array.prototype.slice.call(rootEl.querySelectorAll(':scope > .hb-blk'));
        const rootOf = (w) => w.querySelector(':scope > [data-block-id]') || w;
        if (!items.length) return { index: 0, markEl: null, below: false };
        for (let i = 0; i < items.length; i++) {
            const r = rootOf(items[i]).getBoundingClientRect();
            if (y < r.top + r.height / 2) return { index: i, markEl: rootOf(items[i]), below: false };
        }
        return { index: items.length, markEl: rootOf(items[items.length - 1]), below: true };
    }

