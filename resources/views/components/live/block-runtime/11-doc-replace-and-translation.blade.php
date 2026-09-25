{{--
    Section: whole-document replace and AI/translation write paths.

    Owns: normalizeModel() (validates+backfills a raw block object — from a save payload, a
    pattern, or a duplicate — against the current REGISTRY, assigning a fresh id if needed);
    renderDoc()/replaceDoc() (wipe and redraw the whole canvas from a models array, optionally
    resetting undo history as the new baseline); the translation pipeline — translationSegments()
    hands out the text to translate, translateSegments() and foldTranslation() write into an
    EXPLICITLY named target locale (never the home text, never the locale on screen), and
    carryTranslations() keeps translations across a rebuild from code; and
    applyCanvasWrite(), the single entry point for code-authored content (the code view and the
    AI's write_canvas).

    Depends on: REGISTRY/blockSeq (02-doc-model, 01-bootstrap-and-email-variables); wrapEl()/
    appenderEl()/doc (02-doc-model); deselect() (07-toolbar-and-selection); renderBlockEl()
    (05-render-tree); historyReset() — defined later in 12-history-and-locale-switch, called only
    from replaceDoc() at runtime; translatableKeys()/resolveAttrKey() (02-doc-model);
    editingLocale/homeLocale (01-bootstrap-and-email-variables); reRenderBlock()
    (07-toolbar-and-selection).

    Defines for later sections: normalizeModel() (also used by 08-tree-ops's duplicateBlock() and
    insertPattern()), renderDoc(), replaceDoc(), foldTranslation(), applyCanvasWrite() — the last
    three are exposed on window.hbEditor in 13-api, with translationSegments/translateSegments.
--}}
    function docBlockIds() {
        const ids = new Set();
        (function walk(list) {
            (list || []).forEach(function (m) { if (m && m.id) ids.add(m.id); walk(m && m.innerBlocks); });
        })(doc.blocks);
        return ids;
    }
    // Every block id must be unique: canvas lookup, selection and every write resolve a block by
    // id. A raw id is kept only while no other block claims it, and blockSeq is advanced past it —
    // otherwise, after a saved post loads (hb1, hb2 …) with blockSeq still 0, the next insert or
    // duplicate is handed an id already on the page and edits land on the other block.
    // `claimed` defaults to the ids in the current doc; replaceDoc() passes a fresh set because
    // the old doc is being discarded.
    function normalizeModel(raw, claimed) {
        const c = raw && raw.name ? REGISTRY[raw.name] : null;
        if (!c) return null;
        claimed = claimed || docBlockIds();
        let id = (typeof raw.id === 'string' && /^hb\d+$/.test(raw.id) && !claimed.has(raw.id)) ? raw.id : null;
        if (id) blockSeq = Math.max(blockSeq, Number(id.slice(2)));
        else { do { id = 'hb' + (++blockSeq); } while (claimed.has(id)); }
        claimed.add(id);
        const attrs = {}, defs = c.attributes || {};
        for (const k in defs) { if (Object.prototype.hasOwnProperty.call(defs, k)) attrs[k] = defs[k] == null ? '' : defs[k]; }
        const given = raw.attributes || {};
        for (const k in given) {
            if (!Object.prototype.hasOwnProperty.call(given, k)) continue;
            // An explicit null must NOT overwrite the contract's own default. A saved pattern (or
            // an imported/AI-written block) that carries `anchor: null` produced a block the
            // server then refused on save — "blocks.0.attributes.anchor: expected type string" —
            // because these attributes are typed, and null is not a string. The declared default
            // is what "nothing set" means here, so null falls back to it.
            attrs[k] = (given[k] == null && Object.prototype.hasOwnProperty.call(defs, k))
                ? attrs[k]
                : given[k];
        }
        const inner = [];
        (Array.isArray(raw.innerBlocks) ? raw.innerBlocks : []).forEach(function (child) {
            const m = normalizeModel(child, claimed);
            if (m) inner.push(m);
        });
        return {
            id: id,
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
        const claimed = new Set();
        (Array.isArray(blocks) ? blocks : []).forEach(function (raw) {
            const m = normalizeModel(raw, claimed);
            if (m) models.push(m);
        });
        if (!renderDoc(models)) return false;
        if (opts && opts.baseline) historyReset();
        document.dispatchEvent(new CustomEvent('hb:blocks-changed'));
        return true;
    }

    // ── Translations (docs/content-translation.md §0) ────────────────────────────────────────
    // A translation always names its TARGET locale and lands only in `<key>_<target>`: never in
    // the bare (home) text, never in whatever locale the author happens to be viewing. The AI's
    // translate_page writes through translateSegments(); editing another locale's text as code
    // (the code view or write_canvas while that locale is on screen) folds through
    // foldTranslation() into that named locale.

    function foldHasContent(value) {
        if (typeof value === 'string') return value.trim() !== '';
        if (Array.isArray(value)) return value.length > 0;
        return value !== null && value !== undefined;
    }

    // The translated text the incoming tree carries for `key`: its `<key>_<target>` variant if it
    // spelled one out, otherwise the bare key (translated code is written like any other code).
    function foldReadIncoming(attributes, key, target) {
        const suffixed = key + '_' + target;
        if (Object.prototype.hasOwnProperty.call(attributes, suffixed)) return attributes[suffixed];
        return Object.prototype.hasOwnProperty.call(attributes, key) ? attributes[key] : null;
    }
    function foldNode(storedNode, translatedNode, target, mismatches, path) {
        const storedName = storedNode && storedNode.name;
        const translatedName = translatedNode && translatedNode.name;
        if (typeof storedName !== 'string' || storedName !== translatedName) {
            mismatches.push(path + ": block name mismatch ('" + (typeof storedName === 'string' ? storedName : 'null')
                + "' vs '" + (typeof translatedName === 'string' ? translatedName : 'null') + "')");
            return storedNode;
        }
        const translatedAttrs = (translatedNode.attributes && typeof translatedNode.attributes === 'object') ? translatedNode.attributes : {};
        translatableKeys(storedName).forEach(function (key) {
            const value = foldReadIncoming(translatedAttrs, key, target);
            if (!foldHasContent(value)) return;
            storedNode.attributes[key + '_' + target] = value;
        });

        const storedInner = Array.isArray(storedNode.innerBlocks) ? storedNode.innerBlocks : [];
        const translatedInner = Array.isArray(translatedNode.innerBlocks) ? translatedNode.innerBlocks : [];
        if (storedInner.length !== translatedInner.length) {
            mismatches.push(path + ': innerBlocks count differs (post has ' + storedInner.length + ', translated code has ' + translatedInner.length + ')');
            return storedNode;
        }
        storedNode.innerBlocks = storedInner.map(function (child, index) {
            return foldNode(child, translatedInner[index] || {}, target, mismatches, path + '>' + index);
        });
        return storedNode;
    }

    /**
     * Write a translation of the whole document into `target`'s slots, matched to the stored
     * blocks by position. Refused — nothing written — when `target` is not a content locale, is
     * the home locale (that is the source text, not a translation), or the structure differs.
     * Works on a deep copy, so a refused fold leaves the document exactly as it was.
     */
    function foldTranslation(blocks, target) {
        if (CONTENT_LOCALES.indexOf(target) === -1) {
            return { ok: false, error: "'" + target + "' is not one of this site's languages (" + CONTENT_LOCALES.join(', ') + ').' };
        }
        if (target === homeLocale) {
            return { ok: false, error: "'" + target + "' is this post's own language: its text is the source, edit it directly instead of translating into it." };
        }
        const incoming = Array.isArray(blocks) ? blocks : [];
        if (incoming.length !== doc.blocks.length) {
            return { ok: false, error: 'The translation has ' + incoming.length + ' top-level blocks but the page has ' + doc.blocks.length + '. Translate the SAME block sequence — only human-readable text may change.' };
        }
        const mismatches = [];
        const folded = JSON.parse(JSON.stringify(doc.blocks)).map(function (storedNode, index) {
            return foldNode(storedNode, incoming[index] || {}, target, mismatches, 'blocks[' + index + ']');
        });
        if (mismatches.length) {
            return {
                ok: false,
                error: "The translation's structure does not match this post's blocks: " + mismatches.join('; ')
                    + '. Translate the SAME block sequence and structure — only human-readable text may change.',
            };
        }
        doc.blocks = folded;
        doc.blocks.forEach(function (m) { reRenderBlock(m.id); });
        document.dispatchEvent(new CustomEvent('hb:blocks-changed'));
        return { ok: true, locale: target, blocks: doc.blocks.length };
    }

    /**
     * The text a translation is made from: every translatable attribute that has home text, as
     * `{ "<blockId>.<key>": text }`. A translation comes back keyed the same way, so the model only
     * ever writes TEXT — never the page's markup, styles or structure, which is what made a
     * translation slow (the whole document re-typed) and fragile (a structure it could get wrong).
     */
    function translationSegments() {
        const out = {};
        (function walk(list) {
            (list || []).forEach(function (node) {
                const attrs = node.attributes || {};
                translatableKeys(node.name).forEach(function (key) {
                    if (foldHasContent(attrs[key]) && typeof attrs[key] === 'string') out[node.id + '.' + key] = attrs[key];
                });
                walk(node.innerBlocks);
            });
        })(doc.blocks);
        return out;
    }

    /**
     * Write a translation into `target`'s slots from `{ "<blockId>.<key>": text }` — the shape
     * translationSegments() hands out. Only `<key>_<target>` is ever written: never the home text,
     * never the locale on screen. Refused (nothing written) for a locale that is not a content
     * locale or is the home locale; ids that no longer match a translatable attribute are skipped
     * and reported.
     */
    function translateSegments(target, segments) {
        if (CONTENT_LOCALES.indexOf(target) === -1) {
            return { ok: false, error: "'" + target + "' is not one of this site's languages (" + CONTENT_LOCALES.join(', ') + ').' };
        }
        if (target === homeLocale) {
            return { ok: false, error: "'" + target + "' is this post's own language: its text is the source, edit it directly instead of translating into it." };
        }
        let applied = 0;
        const unknown = [];
        Object.keys(segments || {}).forEach(function (id) {
            const dot = id.lastIndexOf('.');
            const model = dot > 0 ? findModel(id.slice(0, dot)) : null;
            const key = dot > 0 ? id.slice(dot + 1) : '';
            const text = segments[id];
            if (!model || !isTranslatableAttr(model.name, key) || typeof text !== 'string' || text.trim() === '') {
                unknown.push(id);
                return;
            }
            model.attributes[key + '_' + target] = text;
            applied++;
        });
        if (applied) {
            doc.blocks.forEach(function (m) { reRenderBlock(m.id); });
            document.dispatchEvent(new CustomEvent('hb:blocks-changed'));
        }
        return { ok: true, locale: target, applied: applied, unknown: unknown };
    }

    /**
     * Rebuilding the document from code (the code view, the AI's write_canvas) starts from blocks
     * that carry only their home text — code never spells out `_<locale>` variants — so every
     * translation used to vanish with the rebuild. This carries each translation over to the block
     * that still has the SAME home text it was translated from: matched by name and text first
     * (so a moved block keeps its translation), then by position for an attribute whose home text
     * is unchanged. A translation of text that was since rewritten is dropped: it no longer
     * translates anything, and the locale reads as untranslated there until it is redone.
     * LOCKSTEP with LocalizedAttributes::carryTranslations().
     */
    function carryTranslations(oldBlocks, newBlocks) {
        const variantsOf = function (node) {
            const out = {};
            const attrs = (node && node.attributes) || {};
            translatableKeys(node && node.name).forEach(function (key) {
                CONTENT_LOCALES.forEach(function (loc) {
                    const k = key + '_' + loc;
                    if (Object.prototype.hasOwnProperty.call(attrs, k) && foldHasContent(attrs[k])) out[k] = { key: key, value: attrs[k] };
                });
            });
            return out;
        };
        const signature = function (node) {
            const attrs = (node && node.attributes) || {};
            return (node && node.name) + '|' + JSON.stringify(translatableKeys(node && node.name).map(function (key) { return attrs[key] == null ? '' : attrs[key]; }));
        };
        const bySignature = {};
        const byPath = {};
        (function index(list, path) {
            (list || []).forEach(function (node, i) {
                const at = path + '/' + i;
                if (Object.keys(variantsOf(node)).length) {
                    (bySignature[signature(node)] = bySignature[signature(node)] || []).push(node);
                    byPath[at] = node;
                }
                index(node && node.innerBlocks, at);
            });
        })(oldBlocks, '');
        const used = new Set();
        (function carry(list, path) {
            (list || []).forEach(function (node, i) {
                const at = path + '/' + i;
                const attrs = node.attributes || (node.attributes = {});
                const exact = (bySignature[signature(node)] || []).find(function (old) { return !used.has(old); });
                const source = exact || (byPath[at] && !used.has(byPath[at]) && byPath[at].name === node.name ? byPath[at] : null);
                if (source) {
                    used.add(source);
                    const variants = variantsOf(source);
                    Object.keys(variants).forEach(function (k) {
                        if (Object.prototype.hasOwnProperty.call(attrs, k)) return; // the new code spelled it out
                        const key = variants[k].key;
                        // Only onto the text it translates: unchanged home text for this attribute.
                        const was = (source.attributes || {})[key];
                        if (exact || JSON.stringify(was == null ? '' : was) === JSON.stringify(attrs[key] == null ? '' : attrs[key])) {
                            attrs[k] = variants[k].value;
                        }
                    });
                }
                carry(node.innerBlocks, at);
            });
        })(newBlocks, '');
        return newBlocks;
    }

    /**
     * The one write path for AI- or code-authored content.
     * - Editing the HOME locale: append the blocks, or replace the document keeping every
     *   translation whose home text survived the rewrite (carryTranslations()).
     * - Editing another locale: the code is THAT locale's text for the same blocks, so it folds
     *   into that locale explicitly — never into the home text. Appending new blocks is a
     *   structural change, which belongs to the home locale, so it is refused.
     */
    function applyCanvasWrite(blocks, mode) {
        const incoming = Array.isArray(blocks) ? blocks : [];
        if (!incoming.length) return { ok: false, error: 'no blocks' };
        const append = mode !== 'replace';
        if (editingLocale !== homeLocale) {
            if (append) return { ok: false, translating: true, refusedAppend: true };
            return Object.assign({ translating: true }, foldTranslation(incoming, editingLocale));
        }
        replaceDoc(append ? doc.blocks.concat(incoming) : carryTranslations(doc.blocks, incoming));
        return { ok: true, translating: false, appliedCount: incoming.length };
    }
