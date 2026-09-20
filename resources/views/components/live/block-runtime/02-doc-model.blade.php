{{--
    Section: locale-aware attribute keys & the block document model.

    Owns: the translatable-attribute helpers that decide which physical attribute key a read/write
    actually hits (translatableKeys, isTranslatableAttr, resolveAttrKey, readAttrKey, readAttr —
    the "_<locale>" suffix convention also mirrored server-side by LocalizedAttributes::write());
    the document model itself (doc, blockSeq, selected — the currently-selected block element);
    the two DOM anchors wrapEl()/appenderEl(); the block-tree lookup helpers findModelIn/findModel
    and locateBlock (which also returns the containing list + parent, used by move/remove); and
    newBlockModel(), which instantiates a fresh block (plus its innerBlocks template seed) from a
    REGISTRY contract.

    Depends on: REGISTRY, homeLocale, editingLocale (01-bootstrap-and-email-variables).

    Defines for later sections: translatableKeys(), isTranslatableAttr(), resolveAttrKey(),
    readAttrKey(), readAttr(), doc, blockSeq (let, mutated throughout), selected (let, mutated by
    select()/deselect() in 07-toolbar-and-selection), wrapEl(), appenderEl(), findModelIn(),
    findModel(), locateBlock(), newBlockModel(). MAX_NESTING_DEPTH is defined later
    (05-render-tree) but newBlockModel() already reads it when seeding innerBlocks — fine, since
    all of this only runs after the whole IIFE (and therefore every partial) has loaded.
--}}
    function translatableKeys(name) {
        const c = REGISTRY[name];
        return c && Array.isArray(c.translatableAttributes) ? c.translatableAttributes : [];
    }
    function isTranslatableAttr(name, key) { return translatableKeys(name).indexOf(key) !== -1; }

    function resolveAttrKey(name, key) {
        return (isTranslatableAttr(name, key) && editingLocale !== homeLocale) ? key + '_' + editingLocale : key;
    }

    function readAttrKey(model, key) {
        if (isTranslatableAttr(model.name, key)) {
            const suffixed = key + '_' + editingLocale;
            if (Object.prototype.hasOwnProperty.call(model.attributes || {}, suffixed)) return suffixed;
        }
        return key;
    }
    function readAttr(model, key) { return (model.attributes || {})[readAttrKey(model, key)]; }

    const doc = { blocks: [] };
    let blockSeq = 0;
    let selected = null;

    const wrapEl = () => document.querySelector('.hb-page__blocks');
    const appenderEl = () => document.querySelector('.hb-appender');

    function findModelIn(list, id) {
        for (let i = 0; i < list.length; i++) {
            if (list[i].id === id) return list[i];
            const inner = list[i].innerBlocks;
            if (Array.isArray(inner) && inner.length) {
                const hit = findModelIn(inner, id);
                if (hit) return hit;
            }
        }
        return null;
    }
    function findModel(id) { return findModelIn(doc.blocks, id); }


    function locateBlock(id, list, parent) {
        const blocks = list || doc.blocks;
        for (let i = 0; i < blocks.length; i++) {
            if (blocks[i].id === id) return { list: blocks, index: i, parent: parent || null };
            const inner = blocks[i].innerBlocks;
            if (Array.isArray(inner) && inner.length) {
                const hit = locateBlock(id, inner, blocks[i]);
                if (hit) return hit;
            }
        }
        return null;
    }

    function newBlockModel(name, depth) {
        const c = REGISTRY[name];
        if (!c) return null;
        const attrs = {}, defs = c.attributes || {};
        for (const k in defs) { if (Object.prototype.hasOwnProperty.call(defs, k)) attrs[k] = defs[k] == null ? '' : defs[k]; }
        const model = {
            id: 'hb' + (++blockSeq), name: name, schemaVersion: c.version == null ? null : c.version,
            attributes: attrs, supports: {}, innerBlocks: [],
        };

        const seed = c.innerBlocks && Array.isArray(c.innerBlocks.template) ? c.innerBlocks.template : [];
        if ((depth || 0) < MAX_NESTING_DEPTH) {
            seed.forEach(function (entry) {
                const childName = Array.isArray(entry) ? entry[0] : entry;
                if (typeof childName !== 'string') return;
                const child = newBlockModel(childName, (depth || 0) + 1);
                if (!child) return;
                const preset = Array.isArray(entry) && entry[1] && typeof entry[1] === 'object' ? entry[1] : null;
                if (preset) { for (const k in preset) { if (Object.prototype.hasOwnProperty.call(preset, k)) child.attributes[k] = preset[k]; } }
                model.innerBlocks.push(child);
            });
        }
        return model;
    }

