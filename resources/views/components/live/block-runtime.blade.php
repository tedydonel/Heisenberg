{{-- live/block-runtime — the editor's block model. Feeds on the live registry (client block
     contracts) the controller passes in, and brings the canvas to life: insert → render → select →
     edit, plus a public runtime API other editor components build against.

     It ports the pure core of the builder's contract-driven runtime (resources/js/builder/
     04-render-contract.js): a tiny document model + a render.template walk that mirrors the PHP
     BlockRenderer (element / text / rich-text nodes, {{token}} substitution, dynamic-tag allow-list,
     style-variable resolution, the same cssValueValid grammars). Each block renders into a `.hb-blk`
     wrapper carrying data-block / data-block-name (so the Navigator lists it) and, for headings,
     data-level (so the Outline nests it). Selecting a block docks the floating toolbar above it,
     gated by the block's own `supports`, and points the inspector's Block tab at it. Every block
     model carries a `schemaVersion` (copied from the contract's `version`), matching what
     BlocksPayloadService will require of a save payload.

     Insertion: the Components panel cards (data-hb-insert-block) add structured blocks; the canvas
     + appender adds a paragraph, the default text block. Both call one insertBlock().

     Public API — `window.hbEditor` (see the object literal at the bottom of the IIFE for the
     authoritative shape): getDoc/getSelectedId/getModel/getContract/indexOf, insertBlock (now
     index-aware), setAttribute (writes an attribute, re-renders that block in place, fires
     hb:block-updated), moveBlock, removeBlock, selectById, reRenderBlock. The two legacy globals
     (window.hbEditorInsertBlock, window.__hbEditorDoc) keep working unchanged. Integration events on
     `document`: hb:block-selected / hb:block-deselected (detail carries the model + contract),
     hb:block-updated (setAttribute), hb:blocks-changed (insert/remove/move/rich-text edit — the
     Navigator listens for it), and hb:pick-image (cancelable, fired by the image block's empty-state
     placeholder — no media dialog lives here, another component owns that). setAttribute/
     reRenderBlock preserve rich-text caret position across the re-render (capture the offset within
     the focused `.hb-ce`, rebuild, restore by walking text nodes).

     Drag & drop (Pointer Events + setPointerCapture — no HTML5 DnD, which fights contenteditable):
     two gestures here, both committed through moveBlock/insertBlock so the model stays authoritative.
     (1) Canvas reorder starts ONLY from the docked block toolbar's drag handle (.hb-tb__btn--drag) —
     never from a mousedown on the block or its .hb-ce regions — so normal text selection and caret
     placement inside rich text are never at risk. The dragged block dims in place (.is-dragging);
     .is-drop-before/.is-drop-after render the flat insertion line (see 35-blocks.css); the canvas
     auto-scrolls near its top/bottom edge while a drag is active. (2) Palette → canvas: a
     [data-hb-insert-block] card can be dragged onto the canvas (a small ghost chip follows the
     pointer) to insertBlock(name, atIndex) at the drop position; a plain click still appends via the
     existing click handler, unchanged (a `__hbDragSuppressClick` flag only guards the rare case where
     a real drag is released back over the source card, since pointer capture retargets the paired
     mouseup — and so the click — there). See wireCanvasBlockDrag/wirePaletteDrag below. The
     Navigator's own List View drag (its `.grab` grip) is self-contained in panel-navigator.blade.php
     and commits through this same moveBlock, so canvas and Navigator can never disagree about order.

     Deliberately NOT ported yet (separable follow-ups): undo/redo history, server persistence,
     inner-block nesting (columns), animation playback, and the inspector's live Style/Advanced
     control rendering (the header reflects the selection; controls call window.hbEditor.setAttribute
     to write values back — building those controls is the next step). --}}
@props(['registry' => [], 'blocksCss' => '', 'registryHash' => ''])

{{-- Each block is styled by its own contract stylesheet (resources/blocks/<slug>/<slug>.css),
     concatenated server-side and embedded here so a block stays self-contained. --}}
<style id="hb-blocks-css">{!! $blocksCss !!}</style>

<script>
    window.__hbEditor = Object.assign(window.__hbEditor || {}, {
        registry: @json($registry),
        // Required on every save payload — BlocksPayloadService rejects a payload whose hash
        // no longer matches the live contracts (stale-schema detection), so the client must
        // send back the hash the page was rendered against.
        registryHash: @json($registryHash),
    });
</script>

@once
<script>
(() => {
    const DATA = window.__hbEditor || {};
    const REGISTRY = DATA.registry || {};

    // ── document model ─────────────────────────────────────────
    const doc = { blocks: [] };
    let blockSeq = 0;
    let selected = null; // the selected .hb-blk element (not the model)

    const wrapEl = () => document.querySelector('.hb-page__blocks');
    const appenderEl = () => document.querySelector('.hb-appender');

    function findModel(id) {
        for (let i = 0; i < doc.blocks.length; i++) { if (doc.blocks[i].id === id) return doc.blocks[i]; }
        return null;
    }

    function newBlockModel(name) {
        const c = REGISTRY[name];
        if (!c) return null;
        const attrs = {}, defs = c.attributes || {};
        for (const k in defs) { if (Object.prototype.hasOwnProperty.call(defs, k)) attrs[k] = defs[k] == null ? '' : defs[k]; }
        return {
            id: 'hb' + (++blockSeq), name: name, schemaVersion: c.version == null ? null : c.version,
            attributes: attrs, supports: {}, innerBlocks: [],
        };
    }

    // ── pure helpers (ported 1:1 from the builder render engine) ─
    function truthy(v) { return v !== '' && v !== 'false' && v !== '0'; }
    function dataGet(value, path) {
        const parts = String(path || '').split('.');
        for (let i = 0; i < parts.length; i++) {
            if (!value || typeof value !== 'object' || !Object.prototype.hasOwnProperty.call(value, parts[i])) return null;
            value = value[parts[i]];
        }
        return value;
    }
    function subst(str, model) {
        return String(str == null ? '' : str).replace(/\{\{\s*([a-zA-Z0-9_.]+)\s*\}\}/g, function (_, tok) {
            if (tok === 'id') return model.id;
            if (tok === 'name') return model.name;
            if (tok.indexOf('attributes.') === 0) { const v = model.attributes[tok.slice(11)]; return v == null ? '' : String(v); }
            if (tok.indexOf('supports.') === 0) { const s = dataGet(model.supports || {}, tok.slice(9)); return s == null ? '' : String(s); }
            return '';
        });
    }
    const DYN_TAGS = {};
    'div section article aside main header footer nav figure figcaption details summary blockquote p span ul ol li dl dt dd pre code h1 h2 h3 h4 h5 h6'
        .split(' ').forEach(function (t) { DYN_TAGS[t] = 1; });
    function resolveTag(raw, model, contract) {
        const dynamic = String(raw).indexOf('{{') !== -1;
        const prepared = String(raw).replace(/\{\{\s*attributes\.([a-zA-Z0-9_]+)\s*\}\}/g, function (_, attribute) {
            const definition = contract && contract.attributeDefinitions && contract.attributeDefinitions[attribute];
            const allowed = definition && Array.isArray(definition.enum) ? definition.enum : [];
            if (!allowed.length) return '';
            const value = model.attributes ? model.attributes[attribute] : null;
            return String(allowed.indexOf(value) >= 0 ? value : allowed[0]);
        });
        const tag = subst(prepared, model).trim().toLowerCase();
        if (!/^[a-z][a-z0-9-]*$/.test(tag)) return 'div';
        if (dynamic && !DYN_TAGS[tag]) return 'div';
        return tag;
    }
    function isSafeColorToken(value) {
        return /^var\(--(?:accent-[a-z0-9-]+|ink|faint|paper)\)$/.test(value)
            || /^#[0-9a-f]{3,8}$/i.test(value)
            || /^rgba?\(\s*(25[0-5]|2[0-4]\d|1?\d?\d)\s*,\s*(25[0-5]|2[0-4]\d|1?\d?\d)\s*,\s*(25[0-5]|2[0-4]\d|1?\d?\d)(\s*,\s*(0|1|0?\.\d+))?\s*\)$/i.test(value)
            || /^hsla?\(\s*(360|3[0-5]\d|[12]?\d?\d)\s*,\s*(100|\d?\d)%\s*,\s*(100|\d?\d)%(\s*,\s*(0|1|0?\.\d+))?\s*\)$/i.test(value);
    }
    function isSafeLengthSignedValue(value) { return /^(0|-?\d+(\.\d+)?(px|rem|em|%|vw|vh))$/i.test(value); }
    function cssValueValid(value, sanitizer) {
        if (!value) return false;
        if (sanitizer === 'border-style') return ['none', 'solid', 'dashed', 'dotted'].indexOf(value) >= 0;
        if (sanitizer === 'font-token') return /^var\(--[a-z0-9-]+\)$/i.test(value);
        if (sanitizer === 'size-value') return /^(var\(--[a-z0-9-]+\)|-?\d+(\.\d+)?(px|rem|em|%|vw|vh))$/i.test(value);
        if (sanitizer === 'color-value') {
            if (value === 'transparent') return true;
            return /^var\(--[a-z0-9-]+\)$/i.test(value) || isSafeColorToken(value);
        }
        if (sanitizer === 'font-family') return /^(var\(--[a-z0-9-]+\)|[a-z0-9][a-z0-9 \-]{0,80})$/i.test(value);
        if (sanitizer === 'font-weight') return /^(var\(--[a-z0-9-]+\)|[1-9]00)$/i.test(value);
        if (sanitizer === 'size-token') return /^(0|auto|100%|var\(--[a-z0-9-]+(,\s*var\(--[a-z0-9-]+\))?\)|calc\([a-z0-9\s().,%*\/+-]+\)|-?\d+(\.\d+)?(px|rem|em|vw|%)?)$/i.test(value);
        if (sanitizer === 'integer') return /^-?\d+$/.test(value);
        if (sanitizer === 'length-signed') return isSafeLengthSignedValue(value);
        if (sanitizer === 'text-align') return ['left', 'center', 'right', 'justify'].indexOf(value) >= 0;
        return /^[a-z0-9\s().,%_\/-]+$/i.test(value);
    }
    // Which interaction state the canvas is forcing, per block id. Set by previewState() when the
    // inspector's State tab changes; `default` (or absent) means the base values. This mirrors
    // BlockRenderer::stateStylesCss()'s `.hb-state-preview-<state>` hook, which exists precisely
    // so an editor can force a state's look while it is being edited (TODO 7.3).
    const previewStates = {};

    function styleDeclarations(model, contract) {
        const variables = contract && contract.style && contract.style.variables;
        if (!variables || typeof variables !== 'object') return '';
        const state = previewStates[model.id];
        // Only supports-sourced variables can be overridden per state — the same rule
        // stateDeclarations() enforces server-side, so the canvas cannot preview something the
        // renderer would refuse to emit.
        const overrides = state && state !== 'default'
            ? dataGet(model.supports || {}, 'states.' + state)
            : null;
        const declarations = [];
        for (const name in variables) {
            if (!Object.prototype.hasOwnProperty.call(variables, name)) continue;
            const definition = variables[name];
            if (!definition || typeof definition !== 'object') continue;
            const source = String(definition.source || '');
            let value = null;
            if (source.indexOf('supports.') === 0) {
                if (overrides) value = dataGet(overrides, source.slice(9));
                if (value == null || value === '') value = dataGet(model.supports || {}, source.slice(9));
            }
            else if (source.indexOf('attributes.') === 0) value = dataGet(model.attributes || {}, source.slice(11));
            if (value == null || value === '') value = definition.default == null ? '' : String(definition.default);
            value = String(value).trim();
            const fallback = definition.default == null ? '' : String(definition.default).trim();
            const sanitizer = String(definition.sanitize || 'text');
            const safe = cssValueValid(value, sanitizer) ? value : (cssValueValid(fallback, sanitizer) ? fallback : '');
            if (safe) declarations.push(name + ': ' + safe);
        }
        return declarations.length ? declarations.join('; ') + ';' : '';
    }
    function predicateMatches(predicate, model, contract) {
        if (!predicate || typeof predicate !== 'object') return false;
        const attribute = String(predicate.attribute || '');
        let value;
        if (model.attributes && Object.prototype.hasOwnProperty.call(model.attributes, attribute)) value = model.attributes[attribute];
        else {
            const definition = contract && contract.attributeDefinitions && contract.attributeDefinitions[attribute];
            value = definition && Object.prototype.hasOwnProperty.call(definition, 'default') ? definition.default : null;
        }
        if (Object.prototype.hasOwnProperty.call(predicate, 'equals')) return value === predicate.equals;
        if (Array.isArray(predicate.in)) return predicate.in.indexOf(value) >= 0;
        return false;
    }
    function safeUrl(value) {
        const url = String(value || '').trim();
        if (!url) return '';
        const scheme = /^([a-z][a-z0-9+.-]*):/i.exec(url);
        return !scheme || /^(https?|mailto|tel)$/i.test(scheme[1]) ? url : '';
    }
    function alignmentValuesFor(name) {
        const values = REGISTRY[name] && REGISTRY[name].supports && REGISTRY[name].supports.align;
        if (!Array.isArray(values)) return [];
        return values.filter((value, index) => ['left', 'center', 'right'].indexOf(value) >= 0 && values.indexOf(value) === index);
    }

    // ── the template walk ──────────────────────────────────────
    function renderNode(node, model, contract, isRoot) {
        if (!node || typeof node !== 'object') return null;
        const cls = node.class || '';
        if ((typeof cls === 'string' && cls.indexOf('__picker') !== -1) || (node.attributes && node.attributes['data-image-picker'])) return null;
        const type = node.type || null;

        if (type === 'text') return document.createTextNode(subst(node.content || '', model));

        if (type === 'rich-text') {
            const span = document.createElement('span');
            if (node.class) span.className = subst(node.class, model);
            span.classList.add('hb-ce');
            span.setAttribute('contenteditable', 'true');
            span.spellcheck = true;
            span.setAttribute('data-hb-rt', node.attribute || '');
            span.setAttribute('data-ph', 'Write something…');
            const val = model.attributes[node.attribute];
            span.innerHTML = (val == null ? '' : String(val));
            return span;
        }

        // inner-blocks (columns) not supported in this pass — render nothing.
        if (type === 'inner-blocks') return document.createDocumentFragment();

        const el = document.createElement(resolveTag(node.tag || 'div', model, contract));
        if (node.class) { const c = subst(node.class, model); if (c) el.className = c; }
        if (isRoot && contract && contract.style) {
            const conditional = contract.style.classNames || [];
            for (let ci = 0; ci < conditional.length; ci++) {
                if (conditional[ci] && predicateMatches(conditional[ci].when, model, contract)) el.classList.add(conditional[ci].class);
            }
            const alignment = model.supports && model.supports.align;
            if (alignmentValuesFor(model.name).indexOf(alignment) >= 0) el.classList.add('hb-align-' + alignment);
            const declarations = styleDeclarations(model, contract);
            if (declarations) el.setAttribute('style', declarations);
        }
        const attrs = node.attributes || {};
        for (const an in attrs) {
            if (!Object.prototype.hasOwnProperty.call(attrs, an) || an === 'data-image-picker') continue;
            let raw = attrs[an];
            if (raw && typeof raw === 'object') {
                if ('boolean' in raw) { if (truthy(subst(raw.boolean, model))) el.setAttribute(an, ''); continue; }
                const omit = raw.omitWhenEmpty === true || raw.omitEmpty === true; raw = subst(raw.value || '', model); if (omit && raw === '') continue;
            } else { raw = subst(raw, model); }
            if (an === 'src' || an === 'href' || an === 'srcset' || an === 'poster') {
                raw = safeUrl(raw);
                if (!raw && (an === 'src' || an === 'srcset')) continue;
            }
            el.setAttribute(an, raw);
        }
        const kids = node.children || [];
        for (let i = 0; i < kids.length; i++) { const ch = renderNode(kids[i], model, contract, false); if (ch) el.appendChild(ch); }
        return el;
    }

    function renderBlockEl(model) {
        const c = REGISTRY[model.name];
        if (!c || !c.template) return null;
        const root = renderNode(c.template, model, c, true);
        if (!root) return null;
        const wrap = document.createElement('div');
        wrap.className = 'hb-blk';
        wrap.setAttribute('data-block', model.id);
        wrap.setAttribute('data-block-name', model.name);
        if (model.name.indexOf('heading') !== -1) wrap.setAttribute('data-level', String(model.attributes.level || 2));
        wrap.appendChild(root);
        decorateImageBlock(wrap, model);
        return wrap;
    }

    // Empty image blocks show a click-to-pick placeholder instead of a broken <img>.
    // (Wiring the media dialog to it is a follow-up — for now it's a visible slot.)
    function decorateImageBlock(container, model) {
        if (!container || !model || model.name.indexOf('image') === -1) return;
        const url = model.attributes && model.attributes.url;
        if (url) return;
        const img = container.querySelector('img');
        if (img) img.remove();
        if (container.querySelector('.hb-img-empty')) return;
        const ph = document.createElement('button');
        ph.type = 'button';
        ph.className = 'hb-img-empty';
        ph.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="M21 15l-5-5L5 21"/></svg><span>Select image</span>';
        (container.querySelector('figure') || container).appendChild(ph);
    }

    function placeCaretEnd(el) {
        try {
            const r = document.createRange(); r.selectNodeContents(el); r.collapse(false);
            const s = window.getSelection(); s.removeAllRanges(); s.addRange(r);
        } catch (e) { /* noop */ }
    }

    function findBlockEl(id) {
        const wrap = wrapEl();
        return wrap ? wrap.querySelector('.hb-blk[data-block="' + id + '"]') : null;
    }

    // Save/restore caret position across a full re-render (setAttribute / reRenderBlock rebuild the
    // block's DOM from scratch, which would otherwise drop focus and reset the cursor to the start).
    // Captured as a character offset within the focused .hb-ce, keyed by its rich-text attribute name
    // so it can be relocated after the block is rebuilt.
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
            const len = node.textContent.length;
            if (remaining <= len) { target = node; targetOffset = remaining; break; }
            remaining -= len;
            node = walker.nextNode();
        }
        try {
            const range = document.createRange();
            const sel = window.getSelection();
            if (target) range.setStart(target, targetOffset); else range.selectNodeContents(ce);
            range.collapse(true);
            sel.removeAllRanges();
            sel.addRange(range);
        } catch (e) { /* noop */ }
    }

    // ── insert ─────────────────────────────────────────────────
    // atIndex is optional — omitted (or out of range) appends, exactly like before it existed.
    function insertBlock(name, atIndex) {
        const model = newBlockModel(name);
        if (!model) return null;
        const wrap = wrapEl();
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
            const siblings = wrap ? wrap.querySelectorAll('.hb-blk') : [];
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

    // ── selection → toolbar + inspector ────────────────────────
    function templateHasRichText(node) {
        if (!node || typeof node !== 'object') return false;
        if (node.type === 'rich-text') return true;
        const kids = node.children || [];
        for (let i = 0; i < kids.length; i++) { if (templateHasRichText(kids[i])) return true; }
        return false;
    }

    function gateToolbar(tb, model) {
        const c = REGISTRY[model.name] || {};
        const supports = c.supports || {};
        const show = (el, on) => { if (el) el.hidden = !on; };
        show(tb.querySelector('[data-tb-group="format"]'), templateHasRichText(c.template));
        const color = supports.color || {};
        show(tb.querySelector('[data-tb-popover="color"]'), !!(color.text || color.background));
        show(tb.querySelector('[data-tb-popover="align"]'), Array.isArray(supports.align) && supports.align.length > 0);
    }

    function dockToolbar(blk, model) {
        const tb = document.querySelector('[data-hb-block-toolbar]');
        if (!tb) return;
        gateToolbar(tb, model);
        tb.hidden = false;
        blk.insertBefore(tb, blk.firstChild);
    }
    function stowToolbar() {
        const tb = document.querySelector('[data-hb-block-toolbar]');
        const holder = document.querySelector('.hb-blk-toolbar-holder');
        if (tb && holder) { tb.hidden = true; holder.appendChild(tb); }
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
        switchInspector(1); // Block tab
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
        selected.classList.remove('is-selected');
        selected = null;
        stowToolbar();
        switchInspector(0); // back to Post tab
        document.dispatchEvent(new CustomEvent('hb:block-deselected', { detail: {} }));
    }

    // ── public runtime API (window.hbEditor) ──────────────────
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

    // Rebuilds one block's DOM in place from its current model — used after any attribute write.
    // Preserves the current selection (re-docks the toolbar if this was the selected block) and the
    // caret position if the user is mid-edit in one of the block's rich-text fields.
    function reRenderBlock(id) {
        const old = findBlockEl(id);
        const model = findModel(id);
        if (!old || !model) return false;

        const caret = captureCaret(old);
        const wasSelected = selected === old;
        const tb = document.querySelector('[data-hb-block-toolbar]');
        const tbWasDocked = !!(wasSelected && tb && old.contains(tb));

        const next = renderBlockEl(model);
        if (!next) return false;
        if (!old.parentNode) return false;
        old.parentNode.replaceChild(next, old);

        if (wasSelected) {
            selected = next;
            next.classList.add('is-selected');
            if (tbWasDocked) { gateToolbar(tb, model); next.insertBefore(tb, next.firstChild); }
        }

        restoreCaret(next, caret);
        return true;
    }

    function setAttribute(id, key, value) {
        const model = findModel(id);
        if (!model) return false;
        model.attributes[key] = value;
        reRenderBlock(id);
        document.dispatchEvent(new CustomEvent('hb:block-updated', { detail: { id: id, key: key, value: value, model: model } }));
        // The document changed, so anything derived from it must re-read. This matters for
        // attributes the canvas DOM encodes structurally — a heading's `level` drives data-level,
        // which is what the Navigator's Outline nests on. Fired after reRenderBlock so listeners
        // that scan the DOM (panel-navigator) see the updated markup, not the stale markup.
        document.dispatchEvent(new CustomEvent('hb:blocks-changed'));
        return true;
    }

    // The supports counterpart of setAttribute. Style-tab controls are keyed by dotted paths into
    // `supports` (e.g. "color.text", "align"), which setAttribute cannot address — without this,
    // callers had to reach into the model themselves and hand-roll the re-render + events, giving
    // the document two different write paths that could drift. There is one write path per branch.
    function setSupport(id, path, value) {
        const model = findModel(id);
        if (!model) return false;
        const parts = String(path || '').split('.');
        if (!parts.length || parts[0] === '') return false;
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

    // Force a block to render as it would in one of its interaction states, so an override being
    // authored in the inspector is visible on the canvas (TODO 7.3). Re-rendering is enough:
    // styleDeclarations() reads previewStates and merges supports.states.<state> over the base.
    // The `.hb-state-preview-<state>` class rides along for any contract CSS keyed off it — the
    // same hook BlockRenderer::stateStylesCss() emits for the public page.
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

    function removeBlock(id) {
        const i = indexOf(id);
        if (i === -1) return false;
        const el = findBlockEl(id);
        if (selected === el) deselect(); // stow the toolbar while the node is still in the document
        doc.blocks.splice(i, 1);
        if (el && el.parentNode) el.parentNode.removeChild(el);
        document.dispatchEvent(new CustomEvent('hb:blocks-changed'));
        return true;
    }

    // ── drag & drop reorder / insert (Pointer Events, no HTML5 DnD) ────
    // Shared geometry: given a strip of items, find which one the pointer is over and whether the
    // drop should land before or after it (by which half of its box the pointer is in). Falls back
    // to the first/last item so dropping in the empty space past either end of the list — the
    // appender, the gap below the last block — still resolves to something.
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
    // moveBlock's toIndex is a post-removal index (see its own splice, above) — convert a "drop
    // before/after this currently-visible block" hover into that index.
    function moveDropIndex(fromIndex, hoverIndex, below) {
        const desired = below ? hoverIndex + 1 : hoverIndex;
        return desired > fromIndex ? desired - 1 : desired;
    }

    // Auto-scroll the canvas while a drag is active near its top/bottom edge. Shared by both drag
    // gestures below (both hover the canvas); `autoScrollY` is kept current by whichever is running.
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

    // Canvas reorder — the gesture starts ONLY from the docked toolbar's grip (.hb-tb__btn--drag),
    // which exists only inside the currently SELECTED block. Starting there (never from a
    // pointerdown on the block body) is what keeps this from ever hijacking rich-text selection or
    // caret placement: the wrap's own mousedown-to-select listener and the .hb-ce contenteditable
    // regions never see a pointerdown that belongs to a drag.
    function wireCanvasBlockDrag() {
        document.addEventListener('pointerdown', (e) => {
            if (e.button != null && e.button !== 0) return;
            const grip = e.target.closest('.hb-tb__btn--drag');
            if (!grip) return;
            const blk = selected; // the toolbar is only ever docked inside the selected block
            const wrap = wrapEl();
            if (!blk || !wrap) return;
            e.preventDefault(); // also suppresses the compat mousedown/click for this gesture
            try { grip.setPointerCapture(e.pointerId); } catch (err) { /* older engines */ }
            const startY = e.clientY;
            let active = false;
            let hover = null;

            function onMove(ev) {
                autoScrollY = ev.clientY;
                if (!active) {
                    if (Math.abs(ev.clientY - startY) < 4) return;
                    active = true;
                    blk.classList.add('is-dragging');
                    startAutoScroll();
                }
                const found = resolveDropItem(wrap, '.hb-blk', blk, ev.clientX, ev.clientY);
                if (!found) return;
                if (hover && hover.el === found.el && hover.below === found.below) return;
                hover = found;
                clearDropMarks(wrap, '.hb-blk');
                found.el.classList.add(found.below ? 'is-drop-after' : 'is-drop-before');
            }
            function cleanup() {
                grip.removeEventListener('pointermove', onMove);
                grip.removeEventListener('pointerup', onUp);
                grip.removeEventListener('pointercancel', onCancel);
                blk.classList.remove('is-dragging');
                clearDropMarks(wrap, '.hb-blk');
                stopAutoScroll();
            }
            function onUp() {
                if (active && hover) {
                    const fromIndex = indexOf(blk.getAttribute('data-block'));
                    const hoverIndex = indexOf(hover.el.getAttribute('data-block'));
                    if (fromIndex !== -1 && hoverIndex !== -1) {
                        const toIndex = moveDropIndex(fromIndex, hoverIndex, hover.below);
                        if (toIndex !== fromIndex) moveBlock(fromIndex, toIndex);
                    }
                }
                cleanup();
            }
            function onCancel() { cleanup(); }
            grip.addEventListener('pointermove', onMove);
            grip.addEventListener('pointerup', onUp);
            grip.addEventListener('pointercancel', onCancel);
        });
    }

    // Palette → canvas — dragging a Components card onto the canvas inserts it at the drop
    // position; a plain click (no movement past the threshold) still appends via the existing click
    // handler in boot(), untouched.
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
            try { card.setPointerCapture(e.pointerId); } catch (err) { /* older engines */ }
            const startX = e.clientX, startY = e.clientY;
            let active = false;
            let hover = null;
            let ghost = null;

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
                clearDropMarks(wrap, '.hb-blk');
                hover = wrap && overCanvas(ev.clientX, ev.clientY) ? resolveDropItem(wrap, '.hb-blk', null, ev.clientX, ev.clientY) : null;
                if (hover) hover.el.classList.add(hover.below ? 'is-drop-after' : 'is-drop-before');
            }
            function cleanup() {
                card.removeEventListener('pointermove', onMove);
                card.removeEventListener('pointerup', onUp);
                card.removeEventListener('pointercancel', onCancel);
                if (ghost) { ghost.remove(); ghost = null; }
                clearDropMarks(wrapEl(), '.hb-blk');
                stopAutoScroll();
            }
            function onUp(ev) {
                if (active) {
                    // The paired mouseup (and any click it produces) is retargeted to `card` by
                    // pointer capture even when the drop happened over the canvas — guard the click
                    // handler against treating this as a second, separate insert.
                    card.__hbDragSuppressClick = true;
                    if (overCanvas(ev.clientX, ev.clientY)) {
                        if (hover) {
                            const hoverIndex = indexOf(hover.el.getAttribute('data-block'));
                            insertBlock(name, hoverIndex === -1 ? undefined : (hover.below ? hoverIndex + 1 : hoverIndex));
                        } else {
                            insertBlock(name);
                        }
                    }
                    // Dropped outside the canvas entirely — treated as a cancelled drag, no insert.
                }
                cleanup();
            }
            function onCancel() { cleanup(); }
            card.addEventListener('pointermove', onMove);
            card.addEventListener('pointerup', onUp);
            card.addEventListener('pointercancel', onCancel);
        });
    }

    // ── wiring ─────────────────────────────────────────────────
    let wired = false;
    function boot() {
        const wrap = wrapEl();
        if (!wrap || wired) return;
        wired = true;

        // Select on mousedown (so the caret still lands where clicked).
        wrap.addEventListener('mousedown', (e) => {
            const blk = e.target.closest('.hb-blk');
            if (blk) select(blk);
        });

        // An empty image block's click-to-pick placeholder — fire a cancelable event for another
        // component (the media dialog) to handle. No dialog lives here; this just signals intent.
        // setAttribute(id, 'url', …) is how the picker's result comes back into the model.
        wrap.addEventListener('click', (e) => {
            const ph = e.target.closest('.hb-img-empty');
            if (!ph) return;
            const blk = ph.closest('.hb-blk[data-block]');
            if (!blk) return;
            const model = findModel(blk.getAttribute('data-block'));
            if (!model) return;
            document.dispatchEvent(new CustomEvent('hb:pick-image', { detail: { id: model.id, model: model }, cancelable: true }));
        });

        // Rich-text edits flow back into the model (raw innerHTML, like the builder).
        wrap.addEventListener('input', (e) => {
            const ce = e.target.closest && e.target.closest('.hb-ce[data-hb-rt]');
            if (!ce) return;
            const blk = ce.closest('.hb-blk[data-block]');
            if (!blk) return;
            const model = findModel(blk.getAttribute('data-block'));
            if (!model) return;
            model.attributes[ce.getAttribute('data-hb-rt')] = ce.innerHTML;
            document.dispatchEvent(new CustomEvent('hb:blocks-changed'));
        });

        // The + appender adds a paragraph — the default text block. Structured blocks are added
        // from the Components panel; this is the quick "write something" affordance.
        document.querySelectorAll('[data-hb-insert]').forEach((btn) => {
            if (btn.__hbIns2) return; btn.__hbIns2 = true;
            btn.addEventListener('click', (e) => { e.stopPropagation(); insertBlock('heisenberg/paragraph'); });
        });

        // Components panel cards / any [data-hb-insert-block] insert that block directly.
        if (!document.__hbInsertBlockWired) {
            document.__hbInsertBlockWired = true;
            document.addEventListener('click', (e) => {
                const card = e.target.closest('[data-hb-insert-block]');
                if (!card) return;
                // A real palette→canvas drag (wirePaletteDrag) sets this when pointer capture
                // retargets the paired mouseup — and therefore the click — back onto the source
                // card instead of wherever it was actually dropped. Swallow that one click only.
                if (card.__hbDragSuppressClick) { card.__hbDragSuppressClick = false; return; }
                insertBlock(card.getAttribute('data-hb-insert-block'));
            });
            // Click on empty canvas (not a block / toolbar / appender) deselects.
            document.addEventListener('mousedown', (e) => {
                const canvas = e.target.closest('.hb-canvas');
                if (canvas && !e.target.closest('.hb-blk') && !e.target.closest('.hb-tb') && !e.target.closest('.hb-appender')) deselect();
            });
        }

        // Drag & drop — wired once, globally (both listen on `document` regardless of which
        // panel/canvas markup exists at boot time). See the file header for the gesture design.
        if (!document.__hbBlockDnd) {
            document.__hbBlockDnd = true;
            wireCanvasBlockDrag();
            wirePaletteDrag();
        }
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot, { once: true });
    else boot();
    document.addEventListener('hb:refresh', boot);

    // Expose a tiny surface for other components / tests (kept working unchanged).
    window.hbEditorInsertBlock = insertBlock;
    window.__hbEditorDoc = doc;

    // The documented public runtime API other editor components (inspector, navigator, media
    // dialog, …) build against. See the file header for the event contract that goes with it.
    window.hbEditor = {
        getDoc: function () { return doc; },
        getSelectedId: function () { return selected ? selected.getAttribute('data-block') : null; },
        getModel: function (id) { return findModel(id); },
        getContract: function (name) { return REGISTRY[name] || null; },
        indexOf: indexOf,
        insertBlock: insertBlock,
        setAttribute: setAttribute,
        setSupport: setSupport,
        previewState: previewState,
        moveBlock: moveBlock,
        removeBlock: removeBlock,
        selectById: selectById,
        reRenderBlock: reRenderBlock,
        // Builds the exact envelope BlocksPayloadService::validatePayload() expects, so the
        // save wiring never has to reconstruct it (and can't drift from the validator):
        // payload-level schemaVersion is the integer 1 — NOT the per-block contract version
        // string each model already carries in its own `schemaVersion`.
        buildSavePayload: function (extra) {
            return Object.assign({
                schemaVersion: 1,
                registryHash: DATA.registryHash || '',
                blocks: doc.blocks,
            }, extra || {});
        },
    };
})();
</script>
@endonce
