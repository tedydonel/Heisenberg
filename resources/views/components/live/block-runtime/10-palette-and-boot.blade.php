{{--
    Section: container resize handles, the component-palette drag source, and boot() wiring.

    Owns: the container-resize affordance (RESIZE_BAND/RESIZE_MIN, resizeHitAt, clearResizeCursor,
    wireContainerResize — drag a block's right/bottom edge to set supports.size.width/height);
    containerAt()/resolveInsideDrop() (shared drop-target math also used by 09-drag-drop's canvas
    drag); wirePaletteDrag() (dragging a NEW block in from the components palette or the saved-
    blocks list); and boot() itself — the one-time (per .hb-page__blocks) event wiring for
    click/mousedown/input on the canvas, the insert-block/delete-pattern/saved-block click
    delegates, and the DOMContentLoaded/hb:refresh bootstrap that calls it.

    Depends on: findModel()/wrapEl() (02-doc-model); select()/deselect() (07-toolbar-and-
    selection); setSupport() (08-tree-ops); styleDeclarations() (04-render-support); insertBlock()
    (06-selection-support); insertInto()/removeBlock()/moveBlockTo() (08-tree-ops);
    resolveAttrKey() (02-doc-model); serializedEmailValue()/decorateEmailVariables()
    (01-bootstrap-and-email-variables); captureCaret()/restoreCaret() (06-selection-support);
    autoScrollY/startAutoScroll/stopAutoScroll/overCanvas/resolveDropItem/clearDropMarks
    (09-drag-drop); containerAllows() (06-selection-support); insertPattern()/fetchPattern()/
    deletePattern() (08-tree-ops).

    Defines for later sections: containerAt(), resolveInsideDrop(), boot() (referenced only by
    its own DOMContentLoaded/hb:refresh listeners here, not by later sections).
--}}
    function wirePaletteDrag() {
        function makeGhost(card) {
            const g = document.createElement('div');
            g.className = 'hb-drag-ghost';
            const icon = card.querySelector('.hb-toolcard__icon');
            const label = card.querySelector('.hb-toolcard__label');
            if (icon) g.innerHTML = icon.innerHTML;
            const span = document.createElement('span');
            span.textContent = label ? label.textContent : '';
            g.appendChild(span);
            document.body.appendChild(g);
            return g;
        }
        document.addEventListener('pointerdown', (e) => {
            if (e.button != null && e.button !== 0) return;
            const card = e.target.closest('[data-hb-insert-block]');
            if (!card) return;
            const name = card.getAttribute('data-hb-insert-block');
            if (!name) return;
            try { card.setPointerCapture(e.pointerId); } catch (err) { }
            const startX = e.clientX, startY = e.clientY;
            let active = false;
            let hover = null;
            let inside = null;
            let insideEl = null;
            let insideMark = null;
            let ghost = null;

            function clearInsideMarks() {
                if (insideEl) { insideEl.classList.remove('is-drop-inside'); insideEl = null; }
                if (insideMark) { insideMark.classList.remove('is-drop-before', 'is-drop-after'); insideMark = null; }
            }
            function onMove(ev) {
                if (!active) {
                    if (Math.abs(ev.clientX - startX) + Math.abs(ev.clientY - startY) < 5) return;
                    active = true;
                    ghost = makeGhost(card);
                    startAutoScroll();
                }
                ev.preventDefault();
                autoScrollY = ev.clientY;
                ghost.style.left = ev.clientX + 'px';
                ghost.style.top = ev.clientY + 'px';
                const wrap = wrapEl();
                clearDropMarks(wrap, ':scope > .hb-blk');
                clearInsideMarks();
                hover = null;
                inside = null;
                if (!wrap || !overCanvas(ev.clientX, ev.clientY)) return;
                const target = containerAt(ev.clientX, ev.clientY, name);
                if (target) {
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
                hover = resolveDropItem(wrap, ':scope > .hb-blk', null, ev.clientX, ev.clientY);
                if (hover) hover.el.classList.add(hover.below ? 'is-drop-after' : 'is-drop-before');
            }
            function cleanup() {
                card.removeEventListener('pointermove', onMove);
                card.removeEventListener('pointerup', onUp);
                card.removeEventListener('pointercancel', onCancel);
                if (ghost) { ghost.remove(); ghost = null; }
                clearDropMarks(wrapEl(), ':scope > .hb-blk');
                clearInsideMarks();
                stopAutoScroll();
            }
            function onUp(ev) {
                if (active) {
                    card.__hbDragSuppressClick = true;
                    if (overCanvas(ev.clientX, ev.clientY)) {
                        if (inside) {
                            insertInto(inside.id, name, inside.index);
                        } else if (hover) {
                            const hoverIndex = indexOf(hover.el.getAttribute('data-block'));
                            insertBlock(name, hoverIndex === -1 ? undefined : (hover.below ? hoverIndex + 1 : hoverIndex));
                        } else {
                            insertBlock(name);
                        }
                    }
                }
                cleanup();
            }
            function onCancel() { cleanup(); }
            card.addEventListener('pointermove', onMove);
            card.addEventListener('pointerup', onUp);
            card.addEventListener('pointercancel', onCancel);
        });
    }

    function boot() {
        const wrap = wrapEl();
        if (!wrap || wrap.__hbWired) return;
        wrap.__hbWired = true;

        wrap.addEventListener('mousedown', (e) => {
            if (e.target.closest('.hb-tb')) return;
            const blk = e.target.closest('.hb-blk');
            if (blk) select(blk);
        });

        wrap.addEventListener('click', (e) => {
            const add = e.target.closest('[data-hb-inner-appender]');
            if (!add) return;
            e.stopPropagation();
            const owner = findModel(add.getAttribute('data-hb-inner-appender'));
            if (!owner) return;
            const quick = new CustomEvent('hb:quick-insert', {
                cancelable: true,
                detail: { containerId: owner.id, anchor: add },
            });
            document.dispatchEvent(quick);
            if (quick.defaultPrevented) return;
            let childName = 'heisenberg/paragraph';
            if (!containerAllows(owner, childName)) {
                const c = REGISTRY[owner.name];
                const allowed = c && c.innerBlocks ? c.innerBlocks.allowedBlocks : null;
                if (Array.isArray(allowed) && allowed.length) childName = allowed[0];
                else return;
            }
            insertInto(owner.id, childName);
        });

        wrap.addEventListener('click', (e) => {
            const ph = e.target.closest('.hb-img-empty');
            if (!ph) return;
            const blk = ph.closest('.hb-blk[data-block]');
            if (!blk) return;
            const model = findModel(blk.getAttribute('data-block'));
            if (!model) return;
            document.dispatchEvent(new CustomEvent('hb:pick-image', { detail: { id: model.id, model: model }, cancelable: true }));
        });

        wrap.addEventListener('input', (e) => {
            const ce = e.target.closest && e.target.closest('.hb-ce[data-hb-rt]');
            if (!ce) return;
            const blk = ce.closest('.hb-blk[data-block]');
            if (!blk) return;
            const model = findModel(blk.getAttribute('data-block'));
            if (!model) return;
            model.attributes[resolveAttrKey(model.name, ce.getAttribute('data-hb-rt'))] = serializedEmailValue(ce);
            if (document.querySelector('[data-hb-canvas][data-hb-document-type="email"]')) {
                const caret = captureCaret(blk);
                decorateEmailVariables(ce);
                restoreCaret(blk, caret);
            }
            document.dispatchEvent(new CustomEvent('hb:blocks-changed'));
        });

        document.querySelectorAll('[data-hb-insert]').forEach((btn) => {
            if (btn.__hbIns2) return; btn.__hbIns2 = true;
            btn.addEventListener('click', (e) => {
                e.stopPropagation();
                const quick = new CustomEvent('hb:quick-insert', {
                    cancelable: true,
                    detail: { containerId: null, anchor: btn },
                });
                document.dispatchEvent(quick);
                if (!quick.defaultPrevented) insertBlock('heisenberg/paragraph');
            });
        });

        if (!document.__hbInsertBlockWired) {
            document.__hbInsertBlockWired = true;
            document.addEventListener('click', (e) => {
                const card = e.target.closest('[data-hb-insert-block]');
                if (!card) return;
                if (card.__hbDragSuppressClick) { card.__hbDragSuppressClick = false; return; }
                insertBlock(card.getAttribute('data-hb-insert-block'));
            });
            document.addEventListener('click', (e) => {
                const delBtn = e.target.closest('[data-hb-pattern-delete]');
                if (delBtn) {
                    e.stopPropagation();
                    e.preventDefault();
                    deletePattern(delBtn.getAttribute('data-hb-pattern-delete'), delBtn);
                    return;
                }
                const card = e.target.closest('[data-hb-saved-block]');
                if (!card) return;
                if (card.__hbDragSuppressClick) { card.__hbDragSuppressClick = false; return; }
                const id = card.getAttribute('data-hb-saved-block');
                if (!id) return;
                fetchPattern(id).then((pattern) => {
                    if (!pattern) return;
                    insertPattern(pattern.blocks || []);
                }).catch(() => {});
            });
            document.addEventListener('mousedown', (e) => {
                const canvas = e.target.closest('.hb-canvas');
                if (canvas && !e.target.closest('.hb-blk') && !e.target.closest('.hb-tb') && !e.target.closest('.hb-appender')) deselect();
            });
        }

        if (!document.__hbBlockDnd) {
            document.__hbBlockDnd = true;
            wireCanvasBlockDrag();
            wirePaletteDrag();
            wireContainerResize();
        }
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot, { once: true });
    else boot();
    document.addEventListener('hb:refresh', boot);

