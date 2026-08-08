{{-- live/block-runtime — the editor's block model. Feeds on the live registry (client block
     contracts) the controller passes in, and brings the canvas to life: insert → render → select →
     edit, plus a public runtime API other editor components build against.

     A tiny document model + a render.template walk that mirrors the PHP BlockRenderer
     (element / text / rich-text nodes, {{token}} substitution, dynamic-tag allow-list,
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
     hb:block-updated), moveBlock, removeBlock, selectById, reRenderBlock. Integration events on
     `document`: hb:block-selected / hb:block-deselected (detail carries the model + contract),
     hb:block-updated (setAttribute), hb:blocks-changed (insert/remove/move/rich-text edit — the
     Navigator listens for it), and hb:pick-image (cancelable, fired by the image block's empty-state
     placeholder — no consumer is wired yet; the media dialog will own it). setAttribute/
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

     Nesting (2026-08-06): container children render inside display:contents .hb-blk--nested
     wrappers — selectable, editable, movable via moveById, inserted via the in-container
     appender or by inserting while a container is selected. Undo/redo history lives here too
     (snapshot-based; see the history block). --}}
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

    // Where a block lives: its siblings array, its index there, and the owning parent
    // model (null at top level). The nesting-aware counterpart of indexOf().
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
        // Seed the contract's innerBlocks.template ([name, attributes?] entries) — how a
        // fresh `columns` arrives already holding its two columns.
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
    // Split on a delimiter only at paren-depth 0, keeping rgba(0, 0, 0, .2) etc. intact.
    function splitTopLevel(value, delimiter) {
        const parts = []; let current = ''; let depth = 0;
        const s = String(value);
        for (let i = 0; i < s.length; i++) {
            const ch = s[i];
            if (ch === '(') depth++;
            else if (ch === ')') depth = Math.max(0, depth - 1);
            if (depth === 0 && ch === delimiter) { parts.push(current); current = ''; continue; }
            current += ch;
        }
        parts.push(current);
        return parts.map((p) => p.trim());
    }
    function isSafeShadowLayer(layer) {
        const normalized = String(layer).trim().replace(/\s+/g, ' ');
        if (!normalized) return false;
        const tokens = splitTopLevel(normalized, ' ').filter((t) => t !== '');
        if (!tokens.length) return false;
        let inset = 0; const colors = []; const lengths = [];
        for (const token of tokens) {
            if (token.toLowerCase() === 'inset') { inset++; continue; }
            if (isSafeColorToken(token)) { colors.push(token); continue; }
            lengths.push(token);
        }
        if (inset > 1 || colors.length !== 1) return false;
        if (lengths.length < 2 || lengths.length > 4) return false;
        return lengths.every(isSafeLengthSignedValue);
    }
    function isSafeShadowValue(value) {
        if (value === 'none') return true;
        const layers = splitTopLevel(value, ',');
        if (!layers.length) return false;
        return layers.every(isSafeShadowLayer);
    }
    // A bare number carries no CSS unit and would fail its sanitizer; resolve the implied
    // unit (px for lengths, deg for angles, % for 0-100 opacity). Lockstep with
    // BlockRenderer::normalizeCssNumber().
    function normalizeCssNumber(value, sanitizer) {
        if (!/^-?\d+(\.\d+)?$/.test(value)) return value;
        if (sanitizer === 'size-value' || sanitizer === 'length-signed') return value + 'px';
        if (sanitizer === 'angle') return value + 'deg';
        if (sanitizer === 'opacity' && Number(value) > 1) return value + '%';
        return value;
    }
    // Lockstep with BlockRenderer::cssValueValid() — every sanitizer kind gets its own explicit
    // branch; the permissive fallback is only for legacy free-text kinds.
    function cssValueValid(value, sanitizer) {
        if (!value) return false;
        if (sanitizer === 'color-token') return isSafeColorToken(value);
        if (sanitizer === 'color-token-or-transparent') return value === 'transparent' || isSafeColorToken(value);
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
        if (sanitizer === 'opacity') return /^(0|1|0?\.\d{1,3}|(100|[1-9]?\d)%)$/.test(value);
        if (sanitizer === 'angle') return /^-?\d{1,3}(\.\d+)?deg$/i.test(value);
        if (sanitizer === 'length-signed') return isSafeLengthSignedValue(value);
        if (sanitizer === 'shadow') return isSafeShadowValue(value);
        if (sanitizer === 'text-align') return ['left', 'center', 'right', 'justify'].indexOf(value) >= 0;
        if (sanitizer === 'align-3') return ['start', 'center', 'end'].indexOf(value) >= 0;
        if (sanitizer === 'position-mode') return ['static', 'relative', 'absolute'].indexOf(value) >= 0;
        if (sanitizer === 'flex-direction') return ['row', 'column', 'row-reverse', 'column-reverse'].indexOf(value) >= 0;
        if (sanitizer === 'flex-justify') return ['start', 'center', 'end', 'space-between', 'space-around'].indexOf(value) >= 0;
        if (sanitizer === 'flex-align') return ['start', 'center', 'end', 'stretch'].indexOf(value) >= 0;
        if (sanitizer === 'flex-wrap') return ['wrap', 'nowrap', 'wrap-reverse'].indexOf(value) >= 0;
        if (sanitizer === 'overflow') return ['visible', 'hidden', 'clip'].indexOf(value) >= 0;
        return /^[a-z0-9\s().,%_\/-]+$/i.test(value);
    }
    // Which interaction state the canvas is forcing, per block id. Set by previewState() when the
    // inspector's State tab changes; `default` (or absent) means the base values. This mirrors
    // BlockRenderer::stateStylesCss()'s `.hb-state-preview-<state>` hook, which exists precisely
    // so an editor can force a state's look while it is being edited.
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
            const sanitizer = String(definition.sanitize || 'text');
            value = normalizeCssNumber(String(value).trim(), sanitizer);
            const fallback = normalizeCssNumber(definition.default == null ? '' : String(definition.default).trim(), sanitizer);
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

    // LOCKSTEP with BlockRenderer::embedSrcFor()/embedFileSrcFor() — same rules in the same
    // order, same normalization, same fail-closed ''. The canvas must preview exactly what
    // the published page renders; a divergence means the editor lies about what embeds.
    // The two final gates below mirror EMBED_SRC_PATTERN / EMBED_FILE_SRC_PATTERN.
    const EMBED_SRC_PATTERN = /^https:\/\/(?:www\.youtube(?:-nocookie)?\.com\/embed\/|player\.vimeo\.com\/video\/|www\.dailymotion\.com\/embed\/video\/|www\.loom\.com\/embed\/|fast\.wistia\.net\/embed\/iframe\/|streamable\.com\/e\/|www\.tiktok\.com\/embed\/v2\/|customer-[a-z0-9]{1,40}\.cloudflarestream\.com\/)[A-Za-z0-9_/?=&.-]+$/;
    const EMBED_FILE_SRC_PATTERN = /^https:\/\/[A-Za-z0-9](?:[A-Za-z0-9.-]{0,251}[A-Za-z0-9])?(?::[0-9]{1,5})?\/[A-Za-z0-9._~%!$&()*+,;=:/-]*\.(?:mp4|webm|ogg|ogv|mov)(?:\?[A-Za-z0-9._~%!$&()*+,;=:/?-]*)?(?:#[A-Za-z0-9._~%!$&()*+,;=:/?-]*)?$/i;
    const EMBED_RULES = [
        // YouTube — watch / shorts / live / v / embed / youtu.be, on www, m and music.
        { re: /^(?:(?:https?:)?\/\/)?(?:www\.|m\.|music\.)?youtube\.com\/watch\?(?:[^#]*&)?v=([A-Za-z0-9_-]{5,20})(?:[&#].*)?$/i, out: 'yt' },
        { re: /^(?:(?:https?:)?\/\/)?(?:www\.|m\.|music\.)?youtube\.com\/shorts\/([A-Za-z0-9_-]{5,20})(?:[/?#].*)?$/i, out: 'yt' },
        { re: /^(?:(?:https?:)?\/\/)?(?:www\.|m\.|music\.)?youtube\.com\/live\/([A-Za-z0-9_-]{5,20})(?:[/?#].*)?$/i, out: 'yt' },
        { re: /^(?:(?:https?:)?\/\/)?(?:www\.|m\.|music\.)?youtube\.com\/v\/([A-Za-z0-9_-]{5,20})(?:[/?#].*)?$/i, out: 'yt' },
        { re: /^(?:(?:https?:)?\/\/)?(?:www\.|m\.|music\.)?youtube(?:-nocookie)?\.com\/embed\/([A-Za-z0-9_-]{5,20})(?:[/?#].*)?$/i, out: 'yt' },
        { re: /^(?:(?:https?:)?\/\/)?(?:www\.)?youtu\.be\/([A-Za-z0-9_-]{5,20})(?:[/?#].*)?$/i, out: 'yt' },

        // Vimeo — group 2 is the optional privacy hash of an unlisted video.
        { re: /^(?:(?:https?:)?\/\/)?(?:www\.)?vimeo\.com\/([0-9]{1,15})(?:\/([A-Za-z0-9]{6,32}))?(?:[/?#].*)?$/i, out: 'vimeo' },
        { re: /^(?:(?:https?:)?\/\/)?player\.vimeo\.com\/video\/([0-9]{1,15})(?:[/?#].*)?$/i, out: 'vimeo' },
        { re: /^(?:(?:https?:)?\/\/)?(?:www\.)?vimeo\.com\/channels\/[A-Za-z0-9_-]{1,64}\/([0-9]{1,15})(?:[/?#].*)?$/i, out: 'vimeo' },
        { re: /^(?:(?:https?:)?\/\/)?(?:www\.)?vimeo\.com\/groups\/[A-Za-z0-9_-]{1,64}\/videos\/([0-9]{1,15})(?:[/?#].*)?$/i, out: 'vimeo' },
        { re: /^(?:(?:https?:)?\/\/)?(?:www\.)?vimeo\.com\/showcase\/[0-9]{1,15}\/video\/([0-9]{1,15})(?:[/?#].*)?$/i, out: 'vimeo' },

        // Dailymotion — the id runs to the first `_` of the SEO slug.
        { re: /^(?:(?:https?:)?\/\/)?(?:www\.)?dailymotion\.com\/video\/([A-Za-z0-9]{5,20})(?:[_/?#].*)?$/i, out: 'dm' },
        { re: /^(?:(?:https?:)?\/\/)?(?:www\.)?dailymotion\.com\/embed\/video\/([A-Za-z0-9]{5,20})(?:[_/?#].*)?$/i, out: 'dm' },
        { re: /^(?:(?:https?:)?\/\/)?dai\.ly\/([A-Za-z0-9]{5,20})(?:[_/?#].*)?$/i, out: 'dm' },

        // Loom.
        { re: /^(?:(?:https?:)?\/\/)?(?:www\.)?loom\.com\/share\/([A-Za-z0-9]{16,64})(?:[/?#].*)?$/i, out: 'loom' },
        { re: /^(?:(?:https?:)?\/\/)?(?:www\.)?loom\.com\/embed\/([A-Za-z0-9]{16,64})(?:[/?#].*)?$/i, out: 'loom' },

        // Wistia — one bounded subdomain label only (no dot in the class).
        { re: /^(?:(?:https?:)?\/\/)?(?:[A-Za-z0-9-]{1,63}\.)?wistia\.com\/medias\/([A-Za-z0-9]{6,20})(?:[/?#].*)?$/i, out: 'wistia' },
        { re: /^(?:(?:https?:)?\/\/)?(?:[A-Za-z0-9-]{1,63}\.)?wistia\.net\/(?:medias|embed\/iframe)\/([A-Za-z0-9]{6,20})(?:[/?#].*)?$/i, out: 'wistia' },
        { re: /^(?:(?:https?:)?\/\/)?wi\.st\/medias\/([A-Za-z0-9]{6,20})(?:[/?#].*)?$/i, out: 'wistia' },

        // Streamable.
        { re: /^(?:(?:https?:)?\/\/)?(?:www\.)?streamable\.com\/(?:e\/)?([A-Za-z0-9]{3,12})(?:[/?#].*)?$/i, out: 'streamable' },

        // TikTok — the numeric video id, never the @handle.
        { re: /^(?:(?:https?:)?\/\/)?(?:www\.|m\.)?tiktok\.com\/@[A-Za-z0-9._-]{1,30}\/video\/([0-9]{5,25})(?:[/?#].*)?$/i, out: 'tiktok' },
        { re: /^(?:(?:https?:)?\/\/)?(?:www\.)?tiktok\.com\/embed\/v2\/([0-9]{5,25})(?:[/?#].*)?$/i, out: 'tiktok' },

        // Cloudflare Stream — group 1 the customer subdomain, group 2 the video uid.
        { re: /^(?:(?:https?:)?\/\/)?customer-([A-Za-z0-9]{1,40})\.cloudflarestream\.com\/([A-Za-z0-9]{8,64})\/(?:watch|iframe)(?:[/?#].*)?$/i, out: 'cfstream' },
    ];
    // A pasted start offset in whole seconds (`t=`/`start=`, as 90 | 90s | 1m30s | 1h2m3s).
    // Captured loosely, validated strictly: only an int ever reaches the built src.
    function embedStartSeconds(url) {
        const m = /[?&#](?:t|start)=([A-Za-z0-9]{1,16})/i.exec(url);
        if (!m) return 0;
        const value = m[1].toLowerCase();
        let seconds;
        if (/^[0-9]{1,6}$/.test(value)) seconds = parseInt(value, 10);
        else {
            const p = /^(?:([0-9]{1,3})h)?(?:([0-9]{1,3})m)?(?:([0-9]{1,3})s)?$/.exec(value);
            if (!p || !((p[1] || '') + (p[2] || '') + (p[3] || ''))) return 0;
            seconds = (parseInt(p[1] || 0, 10) * 3600) + (parseInt(p[2] || 0, 10) * 60) + parseInt(p[3] || 0, 10);
        }
        return (seconds > 0 && seconds <= 86400) ? seconds : 0;
    }
    // The Vimeo privacy hash carried in the query rather than the path.
    function vimeoQueryHash(url) {
        const m = /[?&]h=([A-Za-z0-9]{6,32})(?:[&#]|$)/i.exec(url);
        return m ? m[1] : '';
    }
    function embedClean(url) {
        // Browsers strip C0 controls + DEL while resolving URLs — match that, or a URL the
        // browser happily loads would be rejected here.
        return String(url == null ? '' : url).trim().replace(/[\x00-\x1F\x7F]+/g, '').trim();
    }
    function embedSrcFor(url) {
        const clean = embedClean(url);
        if (!clean) return '';
        let src = '';
        for (let i = 0; i < EMBED_RULES.length; i++) {
            const m = EMBED_RULES[i].re.exec(clean);
            if (!m) continue;
            const start = embedStartSeconds(clean);
            const hash = (m[2] || '') !== '' ? m[2] : vimeoQueryHash(clean);
            const out = EMBED_RULES[i].out;
            if (out === 'yt') src = 'https://www.youtube-nocookie.com/embed/' + m[1] + (start > 0 ? '?start=' + start : '');
            else if (out === 'vimeo') src = 'https://player.vimeo.com/video/' + m[1] + (hash !== '' ? '?h=' + hash : '');
            else if (out === 'dm') src = 'https://www.dailymotion.com/embed/video/' + m[1];
            else if (out === 'loom') src = 'https://www.loom.com/embed/' + m[1];
            else if (out === 'wistia') src = 'https://fast.wistia.net/embed/iframe/' + m[1];
            else if (out === 'streamable') src = 'https://streamable.com/e/' + m[1];
            else if (out === 'tiktok') src = 'https://www.tiktok.com/embed/v2/' + m[1];
            else if (out === 'cfstream') src = 'https://customer-' + String(m[1]).toLowerCase() + '.cloudflarestream.com/' + m[2] + '/iframe';
            break;
        }
        return EMBED_SRC_PATTERN.test(src) ? src : '';
    }
    // A SELF-HOSTED video file is a media element, not an iframe. Nothing is normalized:
    // a media URL is opaque (signed CDN links carry required query params), so this is a
    // pure allow-list decision.
    function embedFileSrcFor(url) {
        const clean = embedClean(url);
        return clean !== '' && EMBED_FILE_SRC_PATTERN.test(clean) ? clean : '';
    }
    function alignmentValuesFor(name) {
        const values = REGISTRY[name] && REGISTRY[name].supports && REGISTRY[name].supports.align;
        if (!Array.isArray(values)) return [];
        return values.filter((value, index) => ['left', 'center', 'right'].indexOf(value) >= 0 && values.indexOf(value) === index);
    }

    // ── the template walk ──────────────────────────────────────
    // Depth cap in LOCKSTEP with BlockRenderer::MAX_NESTING_DEPTH — the canvas and the
    // published page must drop the same over-deep subtrees.
    const MAX_NESTING_DEPTH = 20;
    function renderNode(node, model, contract, isRoot, depth) {
        depth = depth || 0;
        if (!node || typeof node !== 'object') return null;
        const cls = node.class || '';
        if ((typeof cls === 'string' && cls.indexOf('__picker') !== -1) || (node.attributes && node.attributes['data-image-picker'])) return null;
        const type = node.type || null;

        if (type === 'text') return document.createTextNode(subst(node.content || '', model));

        if (type === 'rich-text') {
            const span = document.createElement('span');
            if (node.class) span.className = subst(node.class, model);
            const val = model.attributes[node.attribute];
            // Editable at EVERY depth: a nested child renders inside its own .hb-blk--nested
            // wrapper, so closest('.hb-blk[data-block]') resolves to the CHILD's model.
            span.classList.add('hb-ce');
            span.setAttribute('contenteditable', 'true');
            span.spellcheck = true;
            span.setAttribute('data-hb-rt', node.attribute || '');
            span.setAttribute('data-ph', 'Write something…');
            span.innerHTML = (val == null ? '' : String(val));
            return span;
        }

        // inner-blocks: each child renders through its OWN contract (same recursion as
        // BlockRenderer::renderInnerBlocks() on the published page), wrapped in a
        // display:contents .hb-blk--nested shell so it is selectable/editable while the
        // container's flex layout still sees the real child root as its item.
        if (type === 'inner-blocks') {
            const frag = document.createDocumentFragment();
            const inner = Array.isArray(model.innerBlocks) ? model.innerBlocks : [];
            for (let i = 0; i < inner.length; i++) {
                if (depth >= MAX_NESTING_DEPTH) break;
                const el = renderBlockEl(inner[i], depth + 1);
                if (el) frag.appendChild(el);
            }
            // Editor-only affordance — an empty container shows a click-to-add target.
            // The published page renders nothing here (canvas chrome, like .hb-appender).
            if (!inner.length && depth < MAX_NESTING_DEPTH) {
                const add = document.createElement('button');
                add.type = 'button';
                add.className = 'hb-inner-appender';
                add.setAttribute('data-hb-inner-appender', model.id);
                const label = (wrapEl() && wrapEl().dataset.hbAddLabel) || 'Add block';
                add.textContent = '+ ' + label;
                frag.appendChild(add);
            }
            return frag;
        }

        const el = document.createElement(resolveTag(node.tag || 'div', model, contract));
        if (node.class) { const c = subst(node.class, model); if (c) el.className = c; }
        if (isRoot && contract && contract.style) {
            // style.className carries the capability markers (e.g. hb-supports) that gate the
            // SupportsStyle stylesheet; BlockRenderer::resolveClass() applies it server-side.
            String(contract.style.className || '').split(/\s+/).forEach(function (t) { if (t) el.classList.add(t); });
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
                // The `embed` attribute-object key (its value is a template expression for
                // the pasted URL) — normalize to the privacy-enhanced embed form; fail
                // closed by omitting the attribute entirely.
                if ('embed' in raw) {
                    const src = embedSrcFor(subst(raw.embed, model));
                    if (src !== '') el.setAttribute(an, src);
                    continue;
                }
                // Self-hosted media: the same shape for the <video> element's src.
                if ('embedFile' in raw) {
                    const file = embedFileSrcFor(subst(raw.embedFile, model));
                    if (file !== '') el.setAttribute(an, file);
                    continue;
                }
                const omit = raw.omitWhenEmpty === true || raw.omitEmpty === true; raw = subst(raw.value || '', model); if (omit && raw === '') continue;
            } else { raw = subst(raw, model); }
            if (an === 'src' || an === 'href' || an === 'srcset' || an === 'poster') {
                raw = safeUrl(raw);
                if (!raw && (an === 'src' || an === 'srcset')) continue;
            }
            el.setAttribute(an, raw);
        }
        const kids = node.children || [];
        for (let i = 0; i < kids.length; i++) { const ch = renderNode(kids[i], model, contract, false, depth); if (ch) el.appendChild(ch); }
        return el;
    }

    function renderBlockEl(model, depth) {
        const c = REGISTRY[model.name];
        if (!c || !c.template) return null;
        const root = renderNode(c.template, model, c, true, depth || 0);
        if (!root) return null;
        const wrap = document.createElement('div');
        wrap.className = (depth || 0) > 0 ? 'hb-blk hb-blk--nested' : 'hb-blk';
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
    /** True when `container`'s contract accepts a child of `name`. */
    function containerAllows(containerModel, name) {
        const c = containerModel ? REGISTRY[containerModel.name] : null;
        if (!c || !c.innerBlocks || !c.innerBlocks.enabled) return false;
        const allowed = c.innerBlocks.allowedBlocks;
        return allowed === '*' || (Array.isArray(allowed) && allowed.indexOf(name) !== -1);
    }

    // atIndex is optional — omitted (or out of range) appends, exactly like before it existed.
    // With a CONTAINER selected and no explicit index, the new block lands INSIDE it — the
    // palette and appender read as "add here" while a group/column is active.
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

    // ── selection → toolbar + inspector ────────────────────────
    function templateHasRichText(node) {
        if (!node || typeof node !== 'object') return false;
        if (node.type === 'rich-text') return true;
        const kids = node.children || [];
        for (let i = 0; i < kids.length; i++) { if (templateHasRichText(kids[i])) return true; }
        return false;
    }

    // The id of whichever block holds `id` in its innerBlocks, or null when it sits at the top
    // level. Walks the tree rather than assuming the document is flat: it IS flat today (nothing
    // nests yet), so this returns null for every block and the select-parent button stays hidden —
    // which is the correct answer now and stays correct once containers exist, instead of a
    // hardcoded false that would have to be found and changed later.
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
        // Select-parent is only meaningful for a block that HAS a parent; save-as-reusable-block
        // only for a container. Both were rendered unconditionally and inert.
        show(tb.querySelector('[data-tb-action="select-parent"]'), parentIdOf(model.id) !== null);
        show(tb.querySelector('[data-tb-action="save"]'), !!(c.innerBlocks && c.innerBlocks.enabled));
    }

    // Where the floating toolbar anchors for a selected block. A nested wrapper is
    // display:contents (flex containers must see the real child root as their item) so it
    // cannot anchor an absolute — and anchoring to the child's own ROOT put the 32px bar
    // *inside* the container's content box, covering sibling text and swallowing the clicks
    // meant for it (a nested child then read as "not editable"). So a nested selection
    // anchors to its TOP-LEVEL ancestor, exactly where a top-level block's toolbar sits:
    // never over container content, and the bar still reflects the selected child.
    function toolbarHost(blk) {
        if (!blk.classList.contains('hb-blk--nested')) return blk;
        let top = blk, parent = blk.parentElement ? blk.parentElement.closest('.hb-blk') : null;
        while (parent) {
            top = parent;
            parent = parent.parentElement ? parent.parentElement.closest('.hb-blk') : null;
        }
        return top;
    }
    function dockToolbar(blk, model) {
        const tb = document.querySelector('[data-hb-block-toolbar]');
        if (!tb) return;
        gateToolbar(tb, model);
        tb.hidden = false;
        const host = toolbarHost(blk);
        host.insertBefore(tb, host.firstChild);
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
        const id = selected.getAttribute('data-block');
        selected.classList.remove('is-selected');
        selected = null;
        stowToolbar();
        switchInspector(0); // back to Post tab
        // A forced state preview is an editing aid for the SELECTED block only. Left behind, the
        // block keeps painting its hover/active look after the user moves on — the canvas then
        // shows styling the default state does not have, which reads as "it worked live but was
        // gone after reload".
        if (previewStates[id]) { delete previewStates[id]; reRenderBlock(id); }
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
        // A re-rendered PARENT swallows its selected child's DOM — re-select it after.
        const selectedId = selected && old.contains(selected) && selected !== old
            ? selected.getAttribute('data-block') : null;

        const nested = old.classList.contains('hb-blk--nested');
        const next = renderBlockEl(model, nested ? 1 : 0);
        if (!next) return false;
        if (!old.parentNode) return false;
        // The toolbar must survive the swap — it lives inside `old` while docked.
        if (tb && old.contains(tb)) stowToolbar();
        old.parentNode.replaceChild(next, old);

        if (wasSelected) {
            selected = next;
            next.classList.add('is-selected');
            if (tbWasDocked) { dockToolbar(next, model); }
        } else if (selectedId) {
            selected = null; // the old child element is gone; re-select its fresh DOM
            selectById(selectedId);
        }

        restoreCaret(next, caret);
        return true;
    }

    // A container contract that declares a `columns` count attribute (heisenberg/columns) keeps
    // its innerBlocks length in lockstep with it: raising the count appends fresh children of the
    // first allowed block, lowering it drops trailing children (undo restores them). A blank
    // mid-edit value ("" while the user retypes) leaves the children alone — the blocks-changed
    // sync below writes the real length back, so the model never saves a non-integer count.
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

    // The reverse edge of the lockstep: any structural change (delete/duplicate/drag of a child,
    // hydration of a saved document) rewrites the count attribute from the real children length,
    // so the inspector's field can never show a stale number.
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
        model.attributes[key] = value;
        if (key === 'columns') reconcileColumnsCount(model);
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
        // The model gate for interaction states: only the states the renderer can compile
        // may ever be written, no matter which surface (inspector, toolbar, code view)
        // asked — a bogus "state" would serialize, save, and then never emit any CSS.
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

    // Force a block to render as it would in one of its interaction states, so an override being
    // authored in the inspector is visible on the canvas. Re-rendering is enough:
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

    // Insert a NEW block into a container at an optional child index — the palette-drop,
    // inner-appender and quick-inserter path. Refuses children the contract doesn't allow.
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
        if (selected === el) deselect(); // stow the toolbar while the node is still in the document
        loc.list.splice(loc.index, 1);
        if (loc.parent) reRenderBlock(loc.parent.id);
        else if (el && el.parentNode) el.parentNode.removeChild(el);
        document.dispatchEvent(new CustomEvent('hb:blocks-changed'));
        return true;
    }

    // Nested-aware sibling move (the toolbar's up/down): swaps within whichever
    // siblings array the block lives in.
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

    // Nested-aware duplicate: deep-clones the model (normalizeModel assigns fresh ids
    // through the whole subtree and re-merges contract defaults) as the next sibling.
    function duplicateBlock(id) {
        const loc = locateBlock(id);
        const source = loc ? loc.list[loc.index] : null;
        if (!source) return null;
        const copy = normalizeModel(JSON.parse(JSON.stringify(source)));
        if (!copy) return null;
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
            // Nested blocks reorder via the toolbar's up/down (moveById) — their
            // display:contents wrappers have no geometry for the drop-line math.
            if (blk.classList.contains('hb-blk--nested')) return;
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
                const found = resolveDropItem(wrap, ':scope > .hb-blk', blk, ev.clientX, ev.clientY);
                if (!found) return;
                if (hover && hover.el === found.el && hover.below === found.below) return;
                hover = found;
                clearDropMarks(wrap, ':scope > .hb-blk');
                found.el.classList.add(found.below ? 'is-drop-after' : 'is-drop-before');
            }
            function cleanup() {
                grip.removeEventListener('pointermove', onMove);
                grip.removeEventListener('pointerup', onUp);
                grip.removeEventListener('pointercancel', onCancel);
                blk.classList.remove('is-dragging');
                clearDropMarks(wrap, ':scope > .hb-blk');
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

    // ── container edge-resize ──────────────────────────────────
    // Hovering within RESIZE_BAND px inside a resizable block root's right/bottom edge shows the
    // matching resize cursor (hb-resize-* classes, 35-blocks.css); dragging writes size.width/
    // size.height. A block is resizable when its CONTRACT declares supports.size.width/height
    // (group, columns, column today — any future contract joins automatically). During the drag
    // only the root's style attribute is regenerated from the model (styleDeclarations — cheap,
    // and it keeps pointer capture on the ORIGINAL element, which a reRenderBlock would replace);
    // the setSupport commit on release does the real re-render, events and history step.
    const RESIZE_BAND = 6;
    const RESIZE_MIN = 24;
    // All resize candidates along the .hb-blk chain match first (a columns row and its columns
    // share one bottom edge); the SELECTED block wins when it is among them — so selecting the
    // row and grabbing the shared edge resizes the row, while an unselected hit resizes the
    // innermost block, which is what the pointer is visually closest to.
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
            try { hit.root.setPointerCapture(e.pointerId); } catch (err) { /* older engines */ }
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
                // Commit through the public write path so re-render, events and the history
                // debounce all happen exactly like an inspector edit.
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

    // Palette → canvas — dragging a Components card onto the canvas inserts it at the drop
    // position; a plain click (no movement past the threshold) still appends via the existing click
    // handler in boot(), untouched.
    // The deepest container under the pointer that accepts `name` — walks the .hb-blk
    // chain upward from the hit, so a drop over a paragraph inside a column targets the
    // column, and a drop over bare group space targets the group.
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

    // Child-index resolution inside a container: nested wrappers are display:contents
    // (empty rects), so geometry reads each child's ROOT element instead.
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
            let inside = null;     // { id, index } when hovering INSIDE a container
            let insideEl = null;   // the container root carrying is-drop-inside
            let insideMark = null; // the child root carrying the insertion line
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
                // Containers first: a drop over one lands INSIDE it, at the child slot
                // under the pointer.
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
                    // The paired mouseup (and any click it produces) is retargeted to `card` by
                    // pointer capture even when the drop happened over the canvas — guard the click
                    // handler against treating this as a second, separate insert.
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
    function boot() {
        const wrap = wrapEl();
        // Flag on the element (house convention) so a replaced canvas wrap re-wires on the
        // next hb:refresh; the document-level listeners below carry their own flags.
        if (!wrap || wrap.__hbWired) return;
        wrap.__hbWired = true;

        // Select on mousedown (so the caret still lands where clicked).
        wrap.addEventListener('mousedown', (e) => {
            // The floating toolbar is DOCKED inside a block element — and a NESTED child's bar
            // docks in its top-level ANCESTOR (toolbarHost). Without this guard, pressing any
            // toolbar button read as a click on that ancestor and re-selected the container out
            // from under the nested child the bar was acting for, so the button then applied to
            // the wrong block (or to nothing, once gateToolbar re-gated it).
            if (e.target.closest('.hb-tb')) return;
            const blk = e.target.closest('.hb-blk');
            if (blk) select(blk);
        });

        // An empty container's click-to-add target (renderNode's inner-blocks branch renders
        // it). Dispatches the cancelable hb:quick-insert first — the Gutenberg-style quick
        // inserter (live/quick-inserter.blade.php) claims it by preventDefault and drives
        // insertInto() itself; with no inserter mounted the default is the first allowed block.
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

        // The + appender. Dispatches the cancelable hb:quick-insert first — the quick
        // inserter popup claims it (preventDefault) and offers every block; the fallback
        // stays the classic "write something" paragraph insert.
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
            wireContainerResize();
        }
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot, { once: true });
    else boot();
    document.addEventListener('hb:refresh', boot);

    // ── whole-document swap (code view apply + saved-post hydration) ──
    // Accepts models in the SAVE shape ({name, attributes, supports, innerBlocks}) with or
    // without ids: every model gets a fresh id (stale/duplicate incoming ids could collide
    // with live ones), attributes are merged over the contract's defaults (a saved or
    // hand-written doc may omit attributes added to the contract since), and unknown block
    // names are dropped — same rule as insertBlock returning null.
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
            id: 'hb' + (++blockSeq), name: raw.name, schemaVersion: c.version == null ? null : c.version,
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
    // opts.baseline: this swap IS the document's starting point (saved-post hydration) —
    // history resets so nothing can be undone back past it to an empty canvas.
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

    // ── history (undo/redo) ────────────────────────────────────
    // Snapshot-based: every mutation event schedules a debounced commit (rapid typing
    // coalesces into one step); undo/redo swap serialized states through renderDoc with
    // ids preserved. The stack lives here because doc.blocks lives here.
    const HISTORY_CAP = 100;
    const history = { past: [], future: [], current: '[]', timer: null, restoring: false };
    function historyEmit() {
        document.dispatchEvent(new CustomEvent('hb:history', {
            detail: { canUndo: history.past.length > 0, canRedo: history.future.length > 0 },
        }));
    }
    function historyCommit() {
        if (history.restoring) return;
        const json = JSON.stringify(doc.blocks);
        if (json === history.current) return;
        history.past.push(history.current);
        if (history.past.length > HISTORY_CAP) history.past.shift();
        history.current = json;
        history.future = [];
        historyEmit();
    }
    function historySchedule() {
        if (history.restoring) return;
        clearTimeout(history.timer);
        history.timer = setTimeout(function () { history.timer = null; historyCommit(); }, 400);
    }
    function historyFlush() {
        if (!history.timer) return;
        clearTimeout(history.timer);
        history.timer = null;
        historyCommit();
    }
    function historyReset() {
        clearTimeout(history.timer);
        history.timer = null;
        history.past = [];
        history.future = [];
        history.current = JSON.stringify(doc.blocks);
        historyEmit();
    }
    function historyRestore(json) {
        history.restoring = true;
        renderDoc(JSON.parse(json));
        document.dispatchEvent(new CustomEvent('hb:blocks-changed'));
        history.restoring = false;
        historyEmit();
    }
    function undo() {
        historyFlush();
        if (!history.past.length) return false;
        history.future.push(history.current);
        history.current = history.past.pop();
        historyRestore(history.current);
        return true;
    }
    function redo() {
        historyFlush();
        if (!history.future.length) return false;
        history.past.push(history.current);
        history.current = history.future.pop();
        historyRestore(history.current);
        return true;
    }
    document.addEventListener('hb:blocks-changed', historySchedule);
    document.addEventListener('hb:block-updated', historySchedule);
    // Ctrl/Cmd+Z / Shift+Z / Y. Native undo stays native inside real text fields (inputs,
    // the code textarea); .hb-ce contenteditable is ours — its DOM is rebuilt per keystroke,
    // so native undo is broken there and document history is the correct handler.
    if (!document.__hbHistoryKeys) {
        document.__hbHistoryKeys = true;
        document.addEventListener('keydown', function (e) {
            if (!(e.ctrlKey || e.metaKey)) return;
            const k = (e.key || '').toLowerCase();
            if (k !== 'z' && k !== 'y') return;
            const t = e.target;
            const tag = t && t.tagName ? t.tagName.toLowerCase() : '';
            if (tag === 'input' || tag === 'textarea' || tag === 'select') return;
            if (t && t.isContentEditable && !(t.classList && t.classList.contains('hb-ce'))) return;
            e.preventDefault();
            if (k === 'y' || (k === 'z' && e.shiftKey)) redo(); else undo();
        });
    }

    // The documented public runtime API other editor components (inspector, navigator, media
    // dialog, …) build against. See the file header for the event contract that goes with it.
    window.hbEditor = {
        getDoc: function () { return doc; },
        getSelectedId: function () { return selected ? selected.getAttribute('data-block') : null; },
        getModel: function (id) { return findModel(id); },
        getContract: function (name) { return REGISTRY[name] || null; },
        indexOf: indexOf,
        insertBlock: insertBlock,
        insertInto: insertInto,
        setAttribute: setAttribute,
        setSupport: setSupport,
        moveById: moveById,
        duplicateBlock: duplicateBlock,
        previewState: previewState,
        parentIdOf: function (id) { return parentIdOf(id); },
        moveBlock: moveBlock,
        removeBlock: removeBlock,
        selectById: selectById,
        reRenderBlock: reRenderBlock,
        replaceDoc: replaceDoc,
        undo: undo,
        redo: redo,
        canUndo: function () { return history.past.length > 0; },
        canRedo: function () { return history.future.length > 0; },
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
