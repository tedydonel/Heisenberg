{{--
    Section: whole-document replace and AI/translation write paths.

    Owns: normalizeModel() (validates+backfills a raw block object — from a save payload, a
    pattern, or a duplicate — against the current REGISTRY, assigning a fresh id if needed);
    renderDoc()/replaceDoc() (wipe and redraw the whole canvas from a models array, optionally
    resetting undo history as the new baseline); and the translation-fold pipeline
    (foldReadIncoming, foldHasContent, foldNode, foldNodes, foldTranslation — folds AI-translated
    text back onto the STORED block tree attribute-by-attribute rather than trusting the
    translated tree's shape, so a hallucinated structural change can't corrupt the document) plus
    applyCanvasWrite(), the single entry point the AI tool-call handler uses that dispatches to
    either an append/replace or a foldTranslation() depending on editingLocale.

    Depends on: REGISTRY/blockSeq (02-doc-model, 01-bootstrap-and-email-variables); wrapEl()/
    appenderEl()/doc (02-doc-model); deselect() (07-toolbar-and-selection); renderBlockEl()
    (05-render-tree); historyReset() — defined later in 12-history-and-locale-switch, called only
    from replaceDoc() at runtime; translatableKeys()/resolveAttrKey() (02-doc-model);
    editingLocale/homeLocale (01-bootstrap-and-email-variables); reRenderBlock()
    (07-toolbar-and-selection).

    Defines for later sections: normalizeModel() (also used by 08-tree-ops's duplicateBlock() and
    insertPattern()), renderDoc(), replaceDoc(), foldTranslation(), applyCanvasWrite() — the last
    three are exposed on window.hbEditor in 13-api.
--}}
    function normalizeModel(raw) {
        const c = raw && raw.name ? REGISTRY[raw.name] : null;
        if (!c) return null;
        const attrs = {}, defs = c.attributes || {};
        for (const k in defs) { if (Object.prototype.hasOwnProperty.call(defs, k)) attrs[k] = defs[k] == null ? '' : defs[k]; }
        const given = raw.attributes || {};
        for (const k in given) { if (Object.prototype.hasOwnProperty.call(given, k)) attrs[k] = given[k]; }
        const inner = [];
        (Array.isArray(raw.innerBlocks) ? raw.innerBlocks : []).forEach(function (child) {
            const m = normalizeModel(child);
            if (m) inner.push(m);
        });
        return {
            id: (typeof raw.id === 'string' && /^hb\d+$/.test(raw.id)) ? raw.id : ('hb' + (++blockSeq)),
            name: raw.name, schemaVersion: c.version == null ? null : c.version,
            attributes: attrs, supports: (raw.supports && typeof raw.supports === 'object') ? raw.supports : {},
            innerBlocks: inner,
        };
    }
    function renderDoc(models) {
        const wrap = wrapEl();
        if (!wrap) return false;
        deselect();
        doc.blocks = models;
        wrap.querySelectorAll('.hb-blk').forEach(function (el) { el.remove(); });
        const app = appenderEl();
        models.forEach(function (model) {
            const el = renderBlockEl(model);
            if (!el) return;
            if (app && app.parentNode === wrap) wrap.insertBefore(el, app);
            else wrap.appendChild(el);
        });
        return true;
    }
    function replaceDoc(blocks, opts) {
        const models = [];
        (Array.isArray(blocks) ? blocks : []).forEach(function (raw) {
            const m = normalizeModel(raw);
            if (m) models.push(m);
        });
        if (!renderDoc(models)) return false;
        if (opts && opts.baseline) historyReset();
        document.dispatchEvent(new CustomEvent('hb:blocks-changed'));
        return true;
    }

    function foldReadIncoming(attributes, key) {
        const suffixed = key + '_' + editingLocale;
        if (Object.prototype.hasOwnProperty.call(attributes, suffixed)) return attributes[suffixed];
        return Object.prototype.hasOwnProperty.call(attributes, key) ? attributes[key] : null;
    }
    function foldHasContent(value) {
        if (typeof value === 'string') return value.trim() !== '';
        if (Array.isArray(value)) return value.length > 0;
        return value !== null && value !== undefined;
    }
    function foldNode(storedNode, translatedNode, mismatches, path) {
        const storedName = storedNode && storedNode.name;
        const translatedName = translatedNode && translatedNode.name;
        if (typeof storedName !== 'string' || storedName !== translatedName) {
            mismatches.push(path + ": block name mismatch ('" + (typeof storedName === 'string' ? storedName : 'null')
                + "' vs '" + (typeof translatedName === 'string' ? translatedName : 'null') + "')");
            return storedNode;
        }
        const translatedAttrs = (translatedNode.attributes && typeof translatedNode.attributes === 'object') ? translatedNode.attributes : {};
        translatableKeys(storedName).forEach(function (key) {
            const value = foldReadIncoming(translatedAttrs, key);
            if (!foldHasContent(value)) return;
            storedNode.attributes[resolveAttrKey(storedName, key)] = value;
        });

        const storedInner = Array.isArray(storedNode.innerBlocks) ? storedNode.innerBlocks : [];
        const translatedInner = Array.isArray(translatedNode.innerBlocks) ? translatedNode.innerBlocks : [];
        if (storedInner.length !== translatedInner.length) {
            mismatches.push(path + ': innerBlocks count differs (post has ' + storedInner.length + ', translated code has ' + translatedInner.length + ')');
            return storedNode;
        }
        storedNode.innerBlocks = storedInner.map(function (child, index) {
            return foldNode(child, translatedInner[index] || {}, mismatches, path + '>' + index);
        });
        return storedNode;
    }
    function foldNodes(storedNodes, translatedNodes, mismatches, path) {
        if (storedNodes.length !== translatedNodes.length) {
            mismatches.push(path + ': block count differs (post has ' + storedNodes.length + ', translated code has ' + translatedNodes.length + ')');
            return storedNodes;
        }
        return storedNodes.map(function (storedNode, index) {
            return foldNode(storedNode, translatedNodes[index] || {}, mismatches, path + '[' + index + ']');
        });
    }
    function foldTranslation(blocks) {
        if (editingLocale === homeLocale) {
            return { ok: false, error: 'foldTranslation is only valid while editing a non-home locale.' };
        }
        const incoming = Array.isArray(blocks) ? blocks : [];
        const mismatches = [];
        const folded = foldNodes(doc.blocks, incoming, mismatches, 'blocks');
        if (mismatches.length) {
            return {
                ok: false,
                error: "The translated content's structure does not match this post's blocks: " + mismatches.join('; ')
                    + '. Translate the SAME block sequence and structure — only human-readable text may change.',
            };
        }
        doc.blocks = folded;
        doc.blocks.forEach(function (m) { reRenderBlock(m.id); });
        document.dispatchEvent(new CustomEvent('hb:blocks-changed'));
        return { ok: true, blocks: doc.blocks.length };
    }

    function applyCanvasWrite(blocks, mode) {
        const incoming = Array.isArray(blocks) ? blocks : [];
        if (!incoming.length) return { ok: false, error: 'no blocks' };
        const append = mode !== 'replace';
        if (editingLocale !== homeLocale) {
            if (append) return { ok: false, translating: true, refusedAppend: true };
            return Object.assign({ translating: true }, foldTranslation(incoming));
        }
        replaceDoc((append ? doc.blocks : []).concat(incoming));
        return { ok: true, translating: false, appliedCount: incoming.length };
    }

