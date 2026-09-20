{{--
    Section: floating toolbar positioning + block selection.

    Owns: the floating block toolbar's viewport-aware positioning (TB_GAP, blockBox,
    positionToolbar, the ResizeObserver-driven followSelected/tbFollow, and the scroll/resize/
    hb:blocks-changed listeners that keep it docked); dockToolbar()/stowToolbar() (mount/unmount
    the toolbar next to the selected block); switchInspector() and updateInspector() (drive the
    right-hand inspector panel's tab and header); and the core selection state machine
    (select(), deselect(), indexOf(), selectById(), reRenderBlock() — which re-renders a block in
    place while preserving caret position and selection/toolbar state across the swap).

    Depends on: findModel()/findBlockEl() (02-doc-model/06-selection-support); gateToolbar()
    (06-selection-support); renderBlockEl() (05-render-tree); captureCaret()/restoreCaret()
    (06-selection-support); previewStates (04-render-support).

    Defines for later sections: select(), deselect(), indexOf(), selectById(), reRenderBlock() —
    all of 08-tree-ops's mutators end by calling reRenderBlock()/selectById() to reflect a model
    change back into the DOM.
--}}
    const TB_GAP = 2;

    function blockBox(blk) {
        return (blk.querySelector(':scope > [data-block-id]') || blk).getBoundingClientRect();
    }

    function positionToolbar() {
        const tb = document.querySelector('[data-hb-block-toolbar]');
        if (!tb || tb.hidden || !selected || !selected.isConnected) return;
        const canvas = document.querySelector('.hb-canvas');
        const view = canvas
            ? canvas.getBoundingClientRect()
            : { top: 0, left: 0, right: window.innerWidth, bottom: window.innerHeight };
        const box = blockBox(selected);
        const h = tb.offsetHeight || 32;
        const w = tb.offsetWidth || 0;

        let top = box.top - h - TB_GAP;
        if (top < view.top) top = Math.min(box.bottom + TB_GAP, view.bottom - h);
        const left = Math.max(view.left, Math.min(box.left, view.right - w));

        tb.style.top = Math.round(top) + 'px';
        tb.style.left = Math.round(left) + 'px';
        tb.style.visibility = (box.bottom < view.top || box.top > view.bottom) ? 'hidden' : '';
    }

    let tbFollow = null;
    function followSelected(blk) {
        if (tbFollow) tbFollow.disconnect();
        if (typeof ResizeObserver === 'undefined') return;
        tbFollow = new ResizeObserver(positionToolbar);
        tbFollow.observe(blk.querySelector(':scope > [data-block-id]') || blk);
    }
    if (!document.__hbTbFollow) {
        document.__hbTbFollow = true;
        document.addEventListener('scroll', positionToolbar, true);
        window.addEventListener('resize', positionToolbar);
        document.addEventListener('hb:blocks-changed', positionToolbar);
    }

    function dockToolbar(blk, model) {
        const tb = document.querySelector('[data-hb-block-toolbar]');
        if (!tb) return;
        gateToolbar(tb, model);
        tb.hidden = false;
        tb.classList.add('hb-tb--float');
        const layer = document.querySelector('.hb-canvas') || document.body;
        if (tb.parentElement !== layer) layer.appendChild(tb);
        positionToolbar();
        followSelected(blk);
    }
    function stowToolbar() {
        const tb = document.querySelector('[data-hb-block-toolbar]');
        const holder = document.querySelector('.hb-blk-toolbar-holder');
        if (tbFollow) { tbFollow.disconnect(); tbFollow = null; }
        if (!tb || !holder) return;
        tb.hidden = true;
        tb.classList.remove('hb-tb--float');
        tb.style.top = tb.style.left = tb.style.visibility = '';
        holder.appendChild(tb);
    }

    function switchInspector(index) {
        const inspector = document.querySelector('[data-hb-inspector]');
        if (!inspector) return;
        const tl = inspector.querySelector('[data-hb-tablist]');
        const tabs = tl ? tl.querySelectorAll('[data-hb-tab]') : [];
        if (tl && tl.__hbTablist && tabs[index]) tl.__hbTablist.activate(tabs[index], false);
    }

    function updateInspector(model) {
        const inspector = document.querySelector('[data-hb-inspector]');
        if (!inspector) return;
        const c = REGISTRY[model.name] || {};
        const nameEl = inspector.querySelector('.hb-inspector__name');
        const descEl = inspector.querySelector('.hb-inspector__desc');
        if (nameEl) nameEl.textContent = c.title || model.name;
        if (descEl) descEl.textContent = c.description || '';
        switchInspector(1);
    }

    function select(blk) {
        if (selected === blk) return;
        deselect();
        selected = blk;
        blk.classList.add('is-selected');
        const model = findModel(blk.getAttribute('data-block'));
        if (!model) return;
        dockToolbar(blk, model);
        updateInspector(model);
        document.dispatchEvent(new CustomEvent('hb:block-selected', {
            detail: { id: model.id, name: model.name, model: model, contract: REGISTRY[model.name] || null },
        }));
    }
    function deselect() {
        if (!selected) return;
        const id = selected.getAttribute('data-block');
        selected.classList.remove('is-selected');
        selected = null;
        stowToolbar();
        switchInspector(0);
        if (previewStates[id]) { delete previewStates[id]; reRenderBlock(id); }
        document.dispatchEvent(new CustomEvent('hb:block-deselected', { detail: {} }));
    }

    function indexOf(id) {
        for (let i = 0; i < doc.blocks.length; i++) { if (doc.blocks[i].id === id) return i; }
        return -1;
    }

    function selectById(id) {
        const el = findBlockEl(id);
        if (!el) return false;
        select(el);
        return true;
    }

    function reRenderBlock(id) {
        const old = findBlockEl(id);
        const model = findModel(id);
        if (!old || !model) return false;

        const caret = captureCaret(old);
        const wasSelected = selected === old;
        const selectedId = selected && old.contains(selected) && selected !== old
            ? selected.getAttribute('data-block') : null;

        const nested = old.classList.contains('hb-blk--nested');
        const next = renderBlockEl(model, nested ? 1 : 0);
        if (!next) return false;
        if (!old.parentNode) return false;
        old.parentNode.replaceChild(next, old);

        if (wasSelected) {
            selected = next;
            next.classList.add('is-selected');
            dockToolbar(next, model);
        } else if (selectedId) {
            selected = null;
            selectById(selectedId);
        }

        restoreCaret(next, caret);
        return true;
    }

