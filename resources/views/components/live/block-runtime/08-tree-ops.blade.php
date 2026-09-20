{{--
    Section: block-tree mutation API (attributes, supports, move, insert, remove, duplicate).

    Owns: column-count reconciliation for container blocks (reconcileColumnsCount,
    syncColumnsCounts, wired to hb:blocks-changed so a manual innerBlocks edit keeps a `columns`
    attribute honest); setAttribute()/setSupport() (the inspector's write path, resolving the
    locale-suffixed key via resolveAttrKey()); previewState() (toggles a hover/active/focus
    preview class + re-render); moveBlock() (reorder among top-level siblings); insertInto()
    (insert into a specific container's innerBlocks); removeBlock(); moveById() (move one slot
    in either direction, delegating to moveBlock() at the root); modelContains/treeDepth/depthOf
    (tree-shape queries used to reject illegal moves); moveBlockTo() (drag-and-drop's general
    move-anywhere primitive, including into a new container); duplicateBlock() (deep-clones a
    model subtree with freshly reassigned ids); insertPattern()/patternsIndexUrl()/fetchPattern()/
    deletePattern() (the saved-block-library integration); and insertBlockByModel() (push an
    already-built model, used by insertPattern()).

    Depends on: findModel()/locateBlock()/doc/blockSeq (02-doc-model); resolveAttrKey()
    (02-doc-model); reRenderBlock()/selectById()/deselect()/select() (07-toolbar-and-selection);
    renderBlockEl()/findBlockEl() (05-render-tree/06-selection-support); containerAllows()
    (06-selection-support); wrapEl()/appenderEl() (02-doc-model); normalizeModel() — defined later
    in 11-doc-replace-and-translation, used only by duplicateBlock()'s JSON round-trip, which is
    fine since it only runs post-boot.

    Defines for later sections: setAttribute(), setSupport(), previewState(), moveBlock(),
    insertInto(), removeBlock(), moveById(), moveBlockTo(), duplicateBlock(), insertPattern(),
    insertBlockByModel() — all exposed on window.hbEditor in 13-api.
--}}
    function reconcileColumnsCount(model) {
        const c = REGISTRY[model.name];
        if (!c || !c.attributes || !Object.prototype.hasOwnProperty.call(c.attributes, 'columns')) return;
        if (!c.innerBlocks || !c.innerBlocks.enabled) return;
        const allowed = c.innerBlocks.allowedBlocks;
        const childName = Array.isArray(allowed) && allowed.length ? allowed[0] : null;
        if (!childName) return;
        const raw = model.attributes.columns;
        if (raw === '' || raw == null) return;
        let want = parseInt(raw, 10);
        if (!isFinite(want)) return;
        want = Math.max(1, Math.min(6, want));
        model.attributes.columns = want;
        while (model.innerBlocks.length < want) {
            const child = newBlockModel(childName, 1);
            if (!child) break;
            model.innerBlocks.push(child);
        }
        if (model.innerBlocks.length > want) model.innerBlocks.length = want;
    }

    function syncColumnsCounts(list) {
        (list || doc.blocks).forEach(function (m) {
            const c = REGISTRY[m.name];
            if (c && c.attributes && Object.prototype.hasOwnProperty.call(c.attributes, 'columns')
                && c.innerBlocks && c.innerBlocks.enabled) {
                m.attributes.columns = m.innerBlocks.length;
            }
            if (Array.isArray(m.innerBlocks) && m.innerBlocks.length) syncColumnsCounts(m.innerBlocks);
        });
    }
    document.addEventListener('hb:blocks-changed', function () { syncColumnsCounts(); });

    function setAttribute(id, key, value) {
        const model = findModel(id);
        if (!model) return false;
        model.attributes[resolveAttrKey(model.name, key)] = value;
        if (key === 'columns') reconcileColumnsCount(model);
        reRenderBlock(id);
        document.dispatchEvent(new CustomEvent('hb:block-updated', { detail: { id: id, key: key, value: value, model: model } }));
        document.dispatchEvent(new CustomEvent('hb:blocks-changed'));
        return true;
    }

    function setSupport(id, path, value) {
        const model = findModel(id);
        if (!model) return false;
        const parts = String(path || '').split('.');
        if (!parts.length || parts[0] === '') return false;
        if (parts[0] === 'states' && ['hover', 'active', 'focus'].indexOf(parts[1]) === -1) {
            console.warn('hbEditor.setSupport: invalid state "' + parts[1] + '" rejected (' + path + ')');
            return false;
        }
        if (!model.supports || typeof model.supports !== 'object') model.supports = {};
        let node = model.supports;
        for (let i = 0; i < parts.length - 1; i++) {
            if (typeof node[parts[i]] !== 'object' || node[parts[i]] === null) node[parts[i]] = {};
            node = node[parts[i]];
        }
        node[parts[parts.length - 1]] = value;
        reRenderBlock(id);
        document.dispatchEvent(new CustomEvent('hb:block-updated', { detail: { id: id, key: path, value: value, model: model } }));
        document.dispatchEvent(new CustomEvent('hb:blocks-changed'));
        return true;
    }

    function previewState(id, state) {
        const model = findModel(id);
        if (!model) return false;
        const next = String(state || 'default');
        if (next === 'default') delete previewStates[id];
        else previewStates[id] = next;
        reRenderBlock(id);
        const el = document.querySelector('.hb-blk[data-block="' + id + '"] [data-block-id]')
            || document.querySelector('.hb-blk[data-block="' + id + '"]');
        if (el) {
            ['hover', 'active', 'focus'].forEach((s) => el.classList.remove('hb-state-preview-' + s));
            if (next !== 'default') el.classList.add('hb-state-preview-' + next);
        }
        return true;
    }

    function moveBlock(fromIndex, toIndex) {
        const n = doc.blocks.length;
        if (typeof fromIndex !== 'number' || typeof toIndex !== 'number') return false;
        if (fromIndex < 0 || fromIndex >= n || toIndex < 0 || toIndex >= n || fromIndex === toIndex) return false;

        const moved = doc.blocks.splice(fromIndex, 1)[0];
        doc.blocks.splice(toIndex, 0, moved);

        const wrap = wrapEl();
        if (wrap) {
            const app = appenderEl();
            for (let i = 0; i < doc.blocks.length; i++) {
                const el = findBlockEl(doc.blocks[i].id);
                if (!el) continue;
                if (app && app.parentNode === wrap) wrap.insertBefore(el, app); else wrap.appendChild(el);
            }
        }
        document.dispatchEvent(new CustomEvent('hb:blocks-changed'));
        return true;
    }

    function insertInto(containerId, name, atIndex) {
        const owner = findModel(containerId);
        if (!owner || !containerAllows(owner, name)) return null;
        const model = newBlockModel(name, 1);
        if (!model) return null;
        const list = owner.innerBlocks;
        const hasIndex = typeof atIndex === 'number' && atIndex >= 0 && atIndex <= list.length;
        if (hasIndex) list.splice(atIndex, 0, model); else list.push(model);
        reRenderBlock(containerId);
        selectById(model.id);
        const el = findBlockEl(model.id);
        const ce = el && el.querySelector('.hb-ce');
        if (ce) { ce.focus(); placeCaretEnd(ce); }
        document.dispatchEvent(new CustomEvent('hb:blocks-changed'));
        return el;
    }

    function removeBlock(id) {
        const loc = locateBlock(id);
        if (!loc) return false;
        const el = findBlockEl(id);
        if (selected === el) deselect();
        loc.list.splice(loc.index, 1);
        if (loc.parent) reRenderBlock(loc.parent.id);
        else if (el && el.parentNode) el.parentNode.removeChild(el);
        document.dispatchEvent(new CustomEvent('hb:blocks-changed'));
        return true;
    }

    function moveById(id, delta) {
        const loc = locateBlock(id);
        if (!loc) return false;
        const j = loc.index + (delta < 0 ? -1 : 1);
        if (j < 0 || j >= loc.list.length) return false;
        if (!loc.parent) return moveBlock(loc.index, j);
        const moved = loc.list.splice(loc.index, 1)[0];
        loc.list.splice(j, 0, moved);
        reRenderBlock(loc.parent.id);
        selectById(id);
        document.dispatchEvent(new CustomEvent('hb:blocks-changed'));
        return true;
    }

    function modelContains(root, needle) {
        const inner = Array.isArray(root.innerBlocks) ? root.innerBlocks : [];
        for (let i = 0; i < inner.length; i++) {
            if (inner[i].id === needle || modelContains(inner[i], needle)) return true;
        }
        return false;
    }
    function treeDepth(m) {
        const inner = Array.isArray(m.innerBlocks) ? m.innerBlocks : [];
        let d = 1;
        for (let i = 0; i < inner.length; i++) d = Math.max(d, 1 + treeDepth(inner[i]));
        return d;
    }
    function depthOf(id) {
        let d = 0;
        let p = parentIdOf(id);
        while (p) { d++; p = parentIdOf(p); }
        return d;
    }

    function moveBlockTo(id, newParentId, rawIndex) {
        const loc = locateBlock(id);
        if (!loc) return false;
        const model = loc.list[loc.index];
        let list = doc.blocks;
        let owner = null;
        if (newParentId) {
            owner = findModel(newParentId);
            if (!owner || !containerAllows(owner, model.name)) return false;
            if (newParentId === id || modelContains(model, newParentId)) return false;
            if (depthOf(owner.id) + treeDepth(model) >= MAX_NESTING_DEPTH) return false;
            list = owner.innerBlocks;
        } else if (!loc.parent) {
            const toIndex = Math.max(0, Math.min(rawIndex - (loc.index < rawIndex ? 1 : 0), doc.blocks.length - 1));
            return toIndex === loc.index ? false : moveBlock(loc.index, toIndex);
        }
        let index = rawIndex;
        if (loc.list === list && loc.index < index) index--;
        index = Math.max(0, Math.min(index, list.length));
        if (loc.list === list && index === loc.index) return false;
        loc.list.splice(loc.index, 1);
        list.splice(index, 0, model);
        if (loc.parent) reRenderBlock(loc.parent.id);
        if (owner) reRenderBlock(owner.id);
        else {
            const fresh = renderBlockEl(model);
            const wrap = wrapEl();
            if (fresh && wrap) {
                const next = doc.blocks[index + 1];
                const before = next ? findBlockEl(next.id) : null;
                if (before) wrap.insertBefore(fresh, before);
                else { const app = appenderEl(); if (app && app.parentNode === wrap) wrap.insertBefore(fresh, app); else wrap.appendChild(fresh); }
            }
        }
        selectById(id);
        document.dispatchEvent(new CustomEvent('hb:blocks-changed'));
        return true;
    }

    function duplicateBlock(id) {
        const loc = locateBlock(id);
        const source = loc ? loc.list[loc.index] : null;
        if (!source) return null;
        const copy = normalizeModel(JSON.parse(JSON.stringify(source)));
        if (!copy) return null;
        (function reid(m) {
            m.id = 'hb' + (++blockSeq);
            (m.innerBlocks || []).forEach(reid);
        })(copy);
        loc.list.splice(loc.index + 1, 0, copy);
        if (loc.parent) reRenderBlock(loc.parent.id);
        else {
            const el = renderBlockEl(copy);
            const srcEl = findBlockEl(id);
            if (el && srcEl && srcEl.parentNode) srcEl.parentNode.insertBefore(el, srcEl.nextSibling);
        }
        selectById(copy.id);
        document.dispatchEvent(new CustomEvent('hb:blocks-changed'));
        return copy.id;
    }

    function insertPattern(blocks) {
        if (!Array.isArray(blocks) || !blocks.length) return null;
        let firstId = null;
        let model = null;
        for (let i = 0; i < blocks.length; i++) {
            model = normalizeModel(blocks[i]);
            if (!model) continue;
            const el = insertBlockByModel(model);
            if (el && !firstId) firstId = model.id;
        }
        if (firstId) document.dispatchEvent(new CustomEvent('hb:blocks-changed'));
        return firstId;
    }

    function patternsIndexUrl() {
        const root = document.querySelector('[data-hb-panel-cb]');
        return root ? root.getAttribute('data-hb-patterns-index-url') || '' : '';
    }
    function fetchPattern(id) {
        const url = patternsIndexUrl();
        if (!url || !id) return Promise.resolve(null);
        return fetch(url, { headers: { 'Accept': 'application/json' } }).then((r) => r.ok ? r.json() : null)
            .then((data) => {
                if (!data || !Array.isArray(data.patterns)) return null;
                const found = data.patterns.find((p) => String(p.id) === String(id));
                return found || null;
            }).catch(() => null);
    }
    function deletePattern(id, btn) {
        const root = document.querySelector('[data-hb-panel-cb]');
        const url = root ? root.getAttribute('data-hb-patterns-destroy-url') || '' : '';
        if (!url || !id) return;
        if (typeof window.confirm === 'function') {
            const ok = window.confirm(root?.getAttribute('data-hb-pattern-delete-confirm') || 'Delete this saved block?');
            if (!ok) return;
        }
        if (btn) btn.disabled = true;
        const csrf = document.querySelector('meta[name="csrf-token"]');
        const headers = { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' };
        if (csrf && csrf.content) headers['X-CSRF-TOKEN'] = csrf.content;
        fetch(url, { method: 'DELETE', headers: headers, body: JSON.stringify({ id: id }) })
            .then((r) => r.ok ? r.json() : null)
            .then(() => {
                document.dispatchEvent(new CustomEvent('hb:patterns-changed'));
            })
            .catch(() => {})
            .finally(() => { if (btn) btn.disabled = false; });
    }

    function insertBlockByModel(model) {
        const wrap = wrapEl();
        const app = appenderEl();
        doc.blocks.push(model);
        const el = renderBlockEl(model);
        if (!el) {
            const i = indexOf(model.id);
            if (i !== -1) doc.blocks.splice(i, 1);
            return null;
        }
        if (app && app.parentNode === wrap) wrap.insertBefore(el, app);
        else wrap.appendChild(el);
        select(el);
        return el;
    }

