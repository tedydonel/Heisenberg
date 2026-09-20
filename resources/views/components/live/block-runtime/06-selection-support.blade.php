{{--
    Section: caret persistence, block insertion, and toolbar-gating helpers.

    Owns: caret save/restore across a re-render (placeCaretEnd, findBlockEl, captureCaret,
    restoreCaret — the latter has to special-case landing inside an email-variable chip, since
    that span is contenteditable="false"); containerAllows() (can this container accept a block of
    this name, per its innerBlocks.allowedBlocks contract); insertBlock() (append/insert a new
    top-level block, or push into the currently-selected container when it accepts the type);
    templateHasRichText() and parentIdOf() (tree lookups used to gate toolbar buttons); and
    gateToolbar() itself, which shows/hides the floating toolbar's format/color/align/select-
    parent/save buttons based on the selected block's contract.

    Depends on: findModel()/wrapEl()/appenderEl()/doc (02-doc-model); newBlockModel()
    (02-doc-model); renderBlockEl()/MAX_NESTING_DEPTH (05-render-tree); REGISTRY
    (01-bootstrap-and-email-variables). insertBlock() also calls reRenderBlock()/selectById(),
    both defined later in 07-toolbar-and-selection — fine, since these are only invoked after
    the whole script has loaded.

    Defines for later sections: placeCaretEnd(), findBlockEl(), captureCaret(), restoreCaret(),
    containerAllows(), insertBlock(), templateHasRichText(), parentIdOf(), gateToolbar().
--}}
    function placeCaretEnd(el) {
        try {
            const r = document.createRange(); r.selectNodeContents(el); r.collapse(false);
            const s = window.getSelection(); s.removeAllRanges(); s.addRange(r);
        } catch (e) {  }
    }

    function findBlockEl(id) {
        const wrap = wrapEl();
        return wrap ? wrap.querySelector('.hb-blk[data-block="' + id + '"]') : null;
    }


    function captureCaret(blk) {
        const active = document.activeElement;
        if (!active || !blk.contains(active) || !active.classList || !active.classList.contains('hb-ce')) return null;
        const attr = active.getAttribute('data-hb-rt') || '';
        const sel = window.getSelection();
        if (!sel || sel.rangeCount === 0) return { attr: attr, offset: 0 };
        try {
            const range = sel.getRangeAt(0);
            const pre = document.createRange();
            pre.selectNodeContents(active);
            pre.setEnd(range.endContainer, range.endOffset);
            return { attr: attr, offset: pre.toString().length };
        } catch (e) { return { attr: attr, offset: 0 }; }
    }
    function restoreCaret(blk, caret) {
        if (!caret) return;
        const ce = blk.querySelector('.hb-ce[data-hb-rt="' + caret.attr + '"]');
        if (!ce) return;
        ce.focus();
        const walker = document.createTreeWalker(ce, NodeFilter.SHOW_TEXT);
        let remaining = caret.offset, node = walker.nextNode(), target = null, targetOffset = 0;
        while (node) {
            const isInsideChip = node.parentElement && node.parentElement.closest('.hb-email-variable-token');
            const len = node.textContent.length;
            if (remaining <= len) {
                if (isInsideChip) {
                    const chip = node.parentElement.closest('.hb-email-variable-token');
                    let next = chip.nextSibling;
                    if (!next || next.nodeType !== Node.TEXT_NODE) {
                        const spacer = document.createTextNode('\u200B');
                        chip.parentNode.insertBefore(spacer, next);
                        next = spacer;
                    }
                    target = next;
                    targetOffset = next.nodeValue === '\u200B' ? 1 : 0;
                } else {
                    target = node;
                    targetOffset = remaining;
                }
                break;
            }
            remaining -= len;
            node = walker.nextNode();
        }
        try {
            const range = document.createRange();
            const sel = window.getSelection();
            if (target) range.setStart(target, Math.min(targetOffset, target.textContent.length)); else range.selectNodeContents(ce);
            range.collapse(true);
            sel.removeAllRanges();
            sel.addRange(range);
        } catch (e) {  }
    }

    function containerAllows(containerModel, name) {
        const c = containerModel ? REGISTRY[containerModel.name] : null;
        if (!c || !c.innerBlocks || !c.innerBlocks.enabled) return false;
        const allowed = c.innerBlocks.allowedBlocks;
        return allowed === '*' || (Array.isArray(allowed) && allowed.indexOf(name) !== -1);
    }

    function insertBlock(name, atIndex) {
        const model = newBlockModel(name);
        if (!model) return null;
        const wrap = wrapEl();

        if (typeof atIndex !== 'number' && selected) {
            const selModel = findModel(selected.getAttribute('data-block'));
            if (selModel && containerAllows(selModel, name)) {
                selModel.innerBlocks.push(model);
                reRenderBlock(selModel.id);
                selectById(model.id);
                const childEl = findBlockEl(model.id);
                const childCe = childEl && childEl.querySelector('.hb-ce');
                if (childCe) { childCe.focus(); placeCaretEnd(childCe); }
                document.dispatchEvent(new CustomEvent('hb:blocks-changed'));
                return childEl;
            }
        }

        const hasIndex = typeof atIndex === 'number' && atIndex >= 0 && atIndex <= doc.blocks.length;
        if (hasIndex) doc.blocks.splice(atIndex, 0, model); else doc.blocks.push(model);
        const el = renderBlockEl(model);
        if (!el) {
            const i = indexOf(model.id);
            if (i !== -1) doc.blocks.splice(i, 1);
            return null;
        }
        const app = appenderEl();
        let ref = null;
        if (hasIndex) {
            const siblings = wrap ? wrap.querySelectorAll(':scope > .hb-blk') : [];
            ref = siblings[atIndex] || null;
        }
        if (ref) wrap.insertBefore(el, ref);
        else if (app && app.parentNode === wrap) wrap.insertBefore(el, app);
        else wrap.appendChild(el);
        select(el);
        const ce = el.querySelector('.hb-ce');
        if (ce) { ce.focus(); placeCaretEnd(ce); }
        document.dispatchEvent(new CustomEvent('hb:blocks-changed'));
        return el;
    }

    function templateHasRichText(node) {
        if (!node || typeof node !== 'object') return false;
        if (node.type === 'rich-text') return true;
        const kids = node.children || [];
        for (let i = 0; i < kids.length; i++) { if (templateHasRichText(kids[i])) return true; }
        return false;
    }

    function parentIdOf(id, list, parent) {
        const blocks = list || doc.blocks;
        for (let i = 0; i < blocks.length; i++) {
            if (blocks[i].id === id) return parent || null;
            const inner = blocks[i].innerBlocks;
            if (Array.isArray(inner) && inner.length) {
                const found = parentIdOf(id, inner, blocks[i].id);
                if (found) return found;
            }
        }
        return null;
    }

    function gateToolbar(tb, model) {
        const c = REGISTRY[model.name] || {};
        const supports = c.supports || {};
        const show = (el, on) => { if (el) el.hidden = !on; };
        show(tb.querySelector('[data-tb-group="format"]'), templateHasRichText(c.template));
        const color = supports.color || {};
        show(tb.querySelector('[data-tb-popover="color"]'), !!(color.text || color.background));
        show(tb.querySelector('[data-tb-popover="align"]'), Array.isArray(supports.align) && supports.align.length > 0);
        show(tb.querySelector('[data-tb-action="select-parent"]'), parentIdOf(model.id) !== null);
        show(tb.querySelector('[data-tb-action="save"]'), !!(c.innerBlocks && c.innerBlocks.enabled));
    }

