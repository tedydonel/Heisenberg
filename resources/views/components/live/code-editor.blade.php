{{-- live/code-editor — the Code view: a shortcode dialect of the block contracts, round-tripping
     with the canvas through window.hbEditor's doc model. One tag per block ([slug …]body[/slug]);
     plain attribute names are contract attributes, dotted names are supports paths — exactly the
     values the inspector and toolbar write (e.g. typography.fontSize, states.hover.color.text).
     `layers` keys are never serialized: they are inspector editing state, re-synthesized from the
     scalar by the layer-list rebuild. Parsing validates against the live registry and reports
     line-numbered errors; the doc is only replaced by a clean parse, so the canvas can never be
     clobbered by half-typed code. Toggled by the footer's [data-hb="code-editor"] chip. --}}
@once
<style>
    .hb-codeview {
        position: absolute; inset: 0; z-index: 5;
        display: flex; flex-direction: column;
        background: var(--hb-code-bg);
        /* A real editor palette (GitHub Light/Dark) — component-local custom properties,
           deliberately independent of the chrome theme tokens (like the color picker's
           documented exceptions): a code surface needs a full token spectrum. */
        --hb-code-bg: #ffffff;
        --hb-code-gutter-bg: #f6f8fa;
        --hb-code-gutter-fg: #8c959f;
        --hb-code-line: rgba(84, 174, 255, .10);
        --hb-code-body: #1f2328;
        --hb-code-tag: #0550ae;
        --hb-code-attr: #953800;
        --hb-code-op: #6e7781;
        --hb-code-str: #0a3069;
        --hb-code-num: #0550ae;
        --hb-code-var: #8250df;
        --hb-code-color: #1a7f37;
        --hb-code-html: #116329;
        --hb-code-state: #cf222e;
    }
    .hb-editor--dark .hb-codeview {
        --hb-code-bg: #0d1117;
        --hb-code-gutter-bg: #161b22;
        --hb-code-gutter-fg: #6e7681;
        --hb-code-line: rgba(56, 139, 253, .14);
        --hb-code-body: #e6edf3;
        --hb-code-tag: #79c0ff;
        --hb-code-attr: #ffa657;
        --hb-code-op: #8b949e;
        --hb-code-str: #a5d6ff;
        --hb-code-num: #79c0ff;
        --hb-code-var: #d2a8ff;
        --hb-code-color: #7ee787;
        --hb-code-html: #7ee787;
        --hb-code-state: #ff7b72;
    }
    .hb-codeview[hidden] { display: none; }
    .hb-editor--codeview .hb-canvas { display: none; }
    .hb-editor--codeview .hb-editor__canvas > .hb-custom-scrollbar { display: none; }

    .hb-codeview__main { flex: 1 1 auto; min-height: 0; display: flex; }
    .hb-codeview__gutter {
        flex: none; width: 52px; overflow: hidden; position: relative;
        background: var(--hb-code-gutter-bg);
        border-right: 1px solid var(--hb-border);
    }
    .hb-editor--dark .hb-codeview__gutter { border-right-color: #21262d; }
    .hb-codeview__nums {
        position: absolute; top: 0; right: 0; padding: 16px 12px 16px 0;
        font-family: var(--hb-font-mono, ui-monospace, SFMono-Regular, Menlo, Consolas, monospace);
        font-size: 12.5px; line-height: 20px; text-align: right;
        color: var(--hb-code-gutter-fg); will-change: transform;
    }
    .hb-codeview__nums span { display: block; }
    .hb-codeview__nums .is-active { color: var(--hb-code-body); }
    .hb-codeview__nums .is-err { color: var(--hb-danger); font-weight: 700; }
    .hb-codeview__editor { flex: 1 1 auto; min-width: 0; position: relative; background: var(--hb-code-bg); }
    /* The current-line band sits under the highlight mirror (real-editor affordance). */
    .hb-codeview__band {
        position: absolute; left: 0; right: 0; top: 0; height: 20px;
        background: var(--hb-code-line);
        pointer-events: none; will-change: transform;
    }
    .hb-codeview__hl, .hb-codeview__input {
        font-family: var(--hb-font-mono, ui-monospace, SFMono-Regular, Menlo, Consolas, monospace);
        font-size: 12.5px; line-height: 20px; tab-size: 2;
        white-space: pre; word-wrap: normal;
        margin: 0; border: 0; padding: 16px;
    }
    .hb-codeview__hl {
        position: absolute; inset: 0; overflow: hidden;
        pointer-events: none; color: var(--hb-code-body);
    }
    .hb-codeview__hl .t { color: var(--hb-code-tag); font-weight: 600; }
    .hb-codeview__hl .a { color: var(--hb-code-attr); }
    .hb-codeview__hl .o { color: var(--hb-code-op); }
    .hb-codeview__hl .s { color: var(--hb-code-str); }
    .hb-codeview__hl .n { color: var(--hb-code-num); }
    .hb-codeview__hl .v { color: var(--hb-code-var); }
    .hb-codeview__hl .c { color: var(--hb-code-color); }
    .hb-codeview__hl .h { color: var(--hb-code-html); }
    .hb-codeview__hl .st { color: var(--hb-code-state); }
    .hb-codeview__input {
        position: absolute; inset: 0; width: 100%; height: 100%;
        overflow: auto; resize: none; outline: none;
        background: transparent; color: transparent;
        caret-color: var(--hb-code-body);
        /* Native scrollbars stay hidden from first paint — the two ui/custom-scrollbar
           instances below are the visible affordance for both axes. */
        scrollbar-width: none;
    }
    .hb-codeview__input::-webkit-scrollbar { display: none; }
    .hb-codeview__input::selection { background: rgba(96, 165, 250, .30); }
    .hb-codeview__input::placeholder { color: var(--hb-text-muted); }

    .hb-codeview__status {
        flex: none; max-height: 140px; overflow-y: auto;
        padding: 10px 16px;
        background: var(--hb-bg-muted);
        border-top: 1px solid var(--hb-border);
        font-family: var(--hb-font-sans, Rubik, sans-serif);
        font-size: var(--hb-fs-sm, 12px);
    }
    .hb-codeview__status[hidden] { display: none; }
    .hb-codeview__status-title { display: block; margin-bottom: 6px; font-weight: 600; color: var(--hb-danger); }
    .hb-codeview__status-list { display: flex; flex-direction: column; gap: 3px; }
    .hb-codeview__err {
        display: inline-flex; gap: 8px; align-items: baseline;
        border: 0; background: none; padding: 0; cursor: pointer; text-align: left;
        font: inherit; color: var(--hb-text-secondary);
    }
    .hb-codeview__err:hover { color: var(--hb-text-primary); }
    .hb-codeview__err b { color: var(--hb-danger); font-weight: 600; flex: none; }
    .hb-codeview__revert {
        margin-top: 8px; border: 0; background: none; padding: 0; cursor: pointer;
        font: inherit; font-weight: 600; color: var(--hb-accent); text-decoration: underline;
    }
</style>
<script>
    (() => {
        const LINE_H = 20;
        // Values are quoted OR bare; a bare value may not contain whitespace/]/" and may
        // not END in a slash, so a tight `…=4/]` still self-closes cleanly.
        const TAG_RE = /\[(\/)?([a-z][a-z0-9-]*)((?:\s+[a-zA-Z0-9_.:-]+\s*=\s*(?:"(?:[^"\\]|\\.)*"|[^\s\]"]*[^\s\]"\/]))*)\s*(\/)?\]/g;
        const ATTR_RE = /([a-zA-Z0-9_.:-]+)\s*=\s*(?:"((?:[^"\\]|\\.)*)"|([^\s\]"]*[^\s\]"\/]))/g;

        const registry = () => (window.__hbEditor || {}).registry || {};
        const slugOf = (name) => name.indexOf('/') >= 0 ? name.split('/')[1] : name;
        const slugToName = () => {
            const map = {};
            Object.keys(registry()).forEach((name) => { map[slugOf(name)] = name; });
            return map;
        };
        const richAttrOf = (contract) => {
            const defs = contract.attributeDefinitions || {};
            for (const key in defs) {
                if (Object.prototype.hasOwnProperty.call(defs, key) && defs[key] && defs[key].type === 'rich-text') return key;
            }
            return null;
        };
        const dataGet = (value, path) => {
            const parts = String(path || '').split('.');
            for (let i = 0; i < parts.length; i++) {
                if (!value || typeof value !== 'object' || !Object.prototype.hasOwnProperty.call(value, parts[i])) return null;
                value = value[parts[i]];
            }
            return value;
        };

        // ── the short dialect ──────────────────────────────────────
        // CSS/Tailwind-familiar short names over the full supports paths. Long form always
        // stays valid (aliases are additive), and a contract attribute of the same name
        // wins over an alias. Real slugs likewise win over tag aliases.
        const ALIASES = {
            color: 'color.text', bg: 'color.background',
            font: 'typography.fontFamily', weight: 'typography.fontWeight',
            'font-size': 'typography.fontSize', 'line-height': 'typography.lineHeight',
            'letter-spacing': 'typography.letterSpacing',
            'text-align': 'typography.textAlign', 'text-valign': 'typography.textAlignVertical',
            w: 'size.width', h: 'size.height',
            'min-w': 'size.minWidth', 'min-h': 'size.minHeight',
            'max-w': 'size.maxWidth', 'max-h': 'size.maxHeight',
            clip: 'size.clip',
            'padding-top': 'spacing.padding.top', 'padding-right': 'spacing.padding.right',
            'padding-bottom': 'spacing.padding.bottom', 'padding-left': 'spacing.padding.left',
            'margin-top': 'spacing.margin.top', 'margin-right': 'spacing.margin.right',
            'margin-bottom': 'spacing.margin.bottom', 'margin-left': 'spacing.margin.left',
            'radius-tl': 'border.radius.topLeft', 'radius-tr': 'border.radius.topRight',
            'radius-br': 'border.radius.bottomRight', 'radius-bl': 'border.radius.bottomLeft',
            'border-width': 'border.width', 'border-color': 'border.color', 'border-style': 'border.style',
            'border-top': 'border.width.top', 'border-right': 'border.width.right',
            'border-bottom': 'border.width.bottom', 'border-left': 'border.width.left',
            gap: 'layout.gap', direction: 'layout.direction', wrap: 'layout.wrap',
            justify: 'layout.justify', 'align-items': 'layout.align',
            position: 'position.mode', x: 'position.x', y: 'position.y', rotate: 'position.rotation',
            opacity: 'appearance.opacity', shadow: 'effects.shadow',
        };
        const REVERSE = {};
        Object.keys(ALIASES).forEach((short) => { REVERSE[ALIASES[short]] = short; });
        const STATES = ['hover', 'active', 'focus'];
        // CSS box shorthands: 1 value = all, 2 = pairs, 4 = each (key order is the CSS order).
        const BOX_SHORTHANDS = {
            padding: { path: 'spacing.padding', keys: ['top', 'right', 'bottom', 'left'] },
            margin: { path: 'spacing.margin', keys: ['top', 'right', 'bottom', 'left'] },
            radius: { path: 'border.radius', keys: ['topLeft', 'topRight', 'bottomRight', 'bottomLeft'] },
        };
        const expandBox = (values, keys) => {
            const [a, b, c] = values;
            if (values.length === 1) return { [keys[0]]: a, [keys[1]]: a, [keys[2]]: a, [keys[3]]: a };
            if (values.length === 2) return { [keys[0]]: a, [keys[1]]: b, [keys[2]]: a, [keys[3]]: b };
            if (values.length === 3) return { [keys[0]]: a, [keys[1]]: b, [keys[2]]: c, [keys[3]]: b };
            return { [keys[0]]: values[0], [keys[1]]: values[1], [keys[2]]: values[2], [keys[3]]: values[3] };
        };
        const collapseBox = (sides, keys) => {
            if (!keys.every((k) => sides[k] !== undefined)) return null;
            const [a, b, c, d] = keys.map((k) => String(sides[k]));
            if (a === b && b === c && c === d) return a;
            if (a === c && b === d) return a + ' ' + b;
            return [a, b, c, d].join(' ');
        };
        // Heading levels ride the tag itself (h1…h6, HTML-familiar); paragraph is p.
        const TAG_SHORT = {
            p: { slug: 'paragraph' },
            h1: { slug: 'heading', attrs: { level: 1 } }, h2: { slug: 'heading', attrs: { level: 2 } },
            h3: { slug: 'heading', attrs: { level: 3 } }, h4: { slug: 'heading', attrs: { level: 4 } },
            h5: { slug: 'heading', attrs: { level: 5 } }, h6: { slug: 'heading', attrs: { level: 6 } },
        };
        const tagFor = (slug, model) => {
            if (slug === 'paragraph') return { tag: 'p', skip: [] };
            if (slug === 'heading') {
                const level = Number(model.attributes && model.attributes.level);
                if (level >= 1 && level <= 6) return { tag: 'h' + level, skip: ['level'] };
            }
            return { tag: slug, skip: [] };
        };
        // Unquoted values keep the code light; anything outside this set gets quotes.
        const UNQUOTED_OK = /^[A-Za-z0-9_.#%(),:+*-]+$/;
        const fmtValue = (v) => UNQUOTED_OK.test(v) ? v : '"' + escAttr(v) + '"';

        // ── body formatting ────────────────────────────────────────
        // Rich-text bodies pretty-print too: block-level boundaries start a new line and
        // long prose word-wraps near BODY_WIDTH. HTML collapses the inserted newlines back
        // to whitespace, so wrapping never changes what renders — and it is idempotent, so
        // the round trip is stable.
        const BODY_WIDTH = 90;
        const breakBlocks = (text) => text.replace(/(<\/(?:div|p|section|blockquote|ul|ol|li|h[1-6])>|<br\s*\/?>)\s*(?=<)/gi, '$1\n');
        const wrapLine = (line, width) => {
            if (line.length <= width) return [line];
            const tokens = line.match(/<[^>]*>|[^<\s]+|\s+/g) || [line];
            const out = [];
            let current = '';
            tokens.forEach((token) => {
                if (/^\s+$/.test(token)) {
                    if (current.length >= width) { out.push(current); current = ''; }
                    else current += token;
                    return;
                }
                current += token;
            });
            if (current.trim() !== '') out.push(current);
            return out.length ? out : [line];
        };
        const formatBody = (body) => breakBlocks(body).split('\n').reduce((lines, line) => lines.concat(wrapLine(line, BODY_WIDTH)), []);

        // ── serializer: doc.blocks → shortcode text ────────────────
        // A tag whose inline form would exceed this width pretty-prints one attribute
        // per line (Prettier-style), closing bracket back at the tag's indent.
        const MAX_TAG_WIDTH = 80;
        // Canonical supports order in serialized code — mirrors the inspector's panel
        // order so the text reads like the panel, with state overrides last. Stable
        // sort, so leaves inside one group keep their relative order.
        const GROUP_ORDER = ['align', 'position', 'layout', 'appearance', 'typography', 'size', 'color', 'spacing', 'border', 'effects', 'animation', 'states'];
        const groupRank = (path) => {
            const i = GROUP_ORDER.indexOf(path.split('.')[0]);
            return i === -1 ? GROUP_ORDER.length : i;
        };
        const escAttr = (v) => String(v).replace(/\\/g, '\\\\').replace(/"/g, '\\"');
        const flattenSupports = (node, prefix, out) => {
            for (const key in node) {
                if (!Object.prototype.hasOwnProperty.call(node, key) || key === 'layers') continue;
                const path = prefix ? prefix + '.' + key : key;
                const value = node[key];
                if (value !== null && typeof value === 'object' && !Array.isArray(value)) flattenSupports(value, path, out);
                else out.push([path, value]);
            }
        };
        const serializeModel = (model, depth) => {
            const contract = registry()[model.name];
            if (!contract) return '';
            const t = tagFor(slugOf(model.name), model);
            const slug = t.tag;
            const indent = '  '.repeat(depth);
            const defs = contract.attributeDefinitions || {};
            const rich = richAttrOf(contract);
            const parts = [];
            for (const key in defs) {
                if (!Object.prototype.hasOwnProperty.call(defs, key) || key === rich || t.skip.indexOf(key) !== -1) continue;
                const def = defs[key] || {};
                // docs/content-translation.md §0/Wave 2 — Code view shows/edits whichever locale
                // the canvas is currently editing, through the same read/write rule as every
                // other surface (window.hbEditor.readAttr/resolveAttrKey).
                const value = model.attributes ? (window.hbEditor && window.hbEditor.readAttr ? window.hbEditor.readAttr(model, key) : model.attributes[key]) : undefined;
                if (value === undefined || value === null) continue;
                const dflt = def.default === undefined || def.default === null ? '' : def.default;
                if (String(value) === String(dflt)) continue; // only non-default values appear in code
                const text = (typeof value === 'object') ? JSON.stringify(value) : String(value);
                parts.push(key + '=' + fmtValue(text));
            }
            const leaves = [];
            flattenSupports(model.supports || {}, '', leaves);
            leaves.sort((x, y) => groupRank(x[0]) - groupRank(y[0]));
            // Compress: state prefixes, box collapse (all four sides → CSS shorthand),
            // then the alias table; the full dotted path is the fallback, never an error.
            const slots = [];
            const boxes = {};
            leaves.forEach(([path, value]) => {
                if (value === undefined || value === null || value === '') return;
                let statePfx = '', rest = path;
                const sm = /^states\.([a-z]+)\./.exec(path);
                if (sm && STATES.indexOf(sm[1]) !== -1) { statePfx = sm[1] + ':'; rest = path.slice(sm[0].length); }
                for (const shortName in BOX_SHORTHANDS) {
                    const box = BOX_SHORTHANDS[shortName];
                    if (rest.indexOf(box.path + '.') === 0) {
                        const side = rest.slice(box.path.length + 1);
                        if (box.keys.indexOf(side) !== -1) {
                            const groupKey = statePfx + shortName;
                            if (!boxes[groupKey]) { boxes[groupKey] = { sides: {}, at: slots.length, shortName, statePfx, box }; slots.push(null); }
                            boxes[groupKey].sides[side] = value;
                            return;
                        }
                    }
                }
                const text = (typeof value === 'object') ? JSON.stringify(value) : String(value);
                slots.push(statePfx + (REVERSE[rest] || rest) + '=' + fmtValue(text));
            });
            Object.keys(boxes).forEach((groupKey) => {
                const acc = boxes[groupKey];
                const collapsed = collapseBox(acc.sides, acc.box.keys);
                if (collapsed !== null) { slots[acc.at] = acc.statePfx + acc.shortName + '=' + fmtValue(collapsed); return; }
                const sideParts = [];
                acc.box.keys.forEach((k) => {
                    if (acc.sides[k] === undefined) return;
                    const full = acc.box.path + '.' + k;
                    sideParts.push(acc.statePfx + (REVERSE[full] || full) + '=' + fmtValue(String(acc.sides[k])));
                });
                slots[acc.at] = { multi: sideParts };
            });
            slots.forEach((slot) => {
                if (slot == null) return;
                if (typeof slot === 'string') parts.push(slot);
                else slot.multi.forEach((p) => parts.push(p));
            });
            const inline = indent + '[' + slug + (parts.length ? ' ' + parts.join(' ') : '');
            const wide = parts.length > 0 && (inline + ']').length > MAX_TAG_WIDTH;
            // `open` always ends right where the closing bracket (or `/]`) belongs.
            const open = wide
                ? indent + '[' + slug + '\n' + parts.map((p) => indent + '  ' + p).join('\n') + '\n' + indent
                : inline;
            const inner = Array.isArray(model.innerBlocks) ? model.innerBlocks : [];
            if (inner.length) {
                const kids = inner.map((child) => serializeModel(child, depth + 1)).filter(Boolean).join('\n');
                return open + ']\n' + kids + '\n' + indent + '[/' + slug + ']';
            }
            const richVal = rich && model.attributes ? (window.hbEditor && window.hbEditor.readAttr ? window.hbEditor.readAttr(model, rich) : model.attributes[rich]) : null;
            const body = rich ? String(richVal != null ? richVal : '') : '';
            if (!body.trim()) return open + (rich ? '][/' + slug + ']' : (wide ? '/]' : ' /]'));
            const bodyLines = formatBody(body).map((line) => indent + '  ' + line).join('\n');
            return open + ']\n' + bodyLines + '\n' + indent + '[/' + slug + ']';
        };
        const serializeDoc = () => {
            const blocks = (window.hbEditor && window.hbEditor.getDoc().blocks) || [];
            const out = blocks.map((model) => serializeModel(model, 0)).filter(Boolean).join('\n\n');
            return out ? out + '\n' : '';
        };

        // ── parser: shortcode text → models + line-numbered errors ─
        const unescAttr = (v) => v.replace(/\\(["\\])/g, '$1');
        const setPath = (obj, path, value) => {
            const parts = path.split('.');
            let node = obj;
            for (let i = 0; i < parts.length - 1; i++) {
                if (!node[parts[i]] || typeof node[parts[i]] !== 'object') node[parts[i]] = {};
                node = node[parts[i]];
            }
            node[parts[parts.length - 1]] = value;
        };
        const dedent = (raw) => {
            const lines = raw.split('\n');
            while (lines.length && lines[0].trim() === '') lines.shift();
            while (lines.length && lines[lines.length - 1].trim() === '') lines.pop();
            let min = Infinity;
            lines.forEach((line) => {
                if (line.trim() === '') return;
                const lead = line.match(/^ */)[0].length;
                if (lead < min) min = lead;
            });
            if (!isFinite(min)) min = 0;
            return lines.map((line) => line.slice(min)).join('\n');
        };
        const parseShortcode = (text, msg) => {
            const errors = [];
            const rootBlocks = [];
            const stack = [];
            const map = slugToName();
            const lineOf = (index) => text.slice(0, index).split('\n').length;
            const err = (line, message) => errors.push({ line: line, message: message });

            const applyAttr = (contract, model, rawName, raw, line, slug) => {
                const defs = contract.attributeDefinitions || {};
                let state = '', name = rawName;
                const ci = name.indexOf(':');
                if (ci > 0 && STATES.indexOf(name.slice(0, ci)) !== -1) {
                    state = name.slice(0, ci);
                    name = name.slice(ci + 1);
                }
                // Contract attributes win over aliases; a state prefix always means supports.
                if (!state && Object.prototype.hasOwnProperty.call(defs, name)) {
                    const def = defs[name] || {};
                    let value = raw;
                    if (def.type === 'boolean') value = raw === 'true' || raw === '1';
                    else if (def.type === 'integer' || def.type === 'number') {
                        value = Number(raw);
                        if (isNaN(value)) { err(line, msg('msgErrInvalidValue', { name: rawName, slug: slug })); return; }
                    } else if (def.type === 'object' || def.type === 'media' || def.type === 'array') {
                        try { value = JSON.parse(raw); } catch (e) { err(line, msg('msgErrInvalidValue', { name: rawName, slug: slug })); return; }
                    }
                    if (Array.isArray(def.enum) && def.enum.length && def.enum.indexOf(value) === -1) {
                        err(line, msg('msgErrInvalidValue', { name: rawName, slug: slug }));
                        return;
                    }
                    // docs/content-translation.md §0/Wave 2 — a translatable attribute written
                    // from Code view lands on the same key setAttribute()/rich-text editing would.
                    const attrKey = window.hbEditor && window.hbEditor.resolveAttrKey ? window.hbEditor.resolveAttrKey(model.name, name) : name;
                    model.attributes[attrKey] = value;
                    return;
                }
                const box = BOX_SHORTHANDS[name] || null;
                const path = box ? box.path : (ALIASES[name] || name);
                const group = path.split('.')[0];
                if (!contract.supports || !Object.prototype.hasOwnProperty.call(contract.supports, group)) {
                    err(line, msg('msgErrUnknownAttr', { name: rawName, slug: slug }));
                    return;
                }
                // Long-form state paths must name a REAL state — `states.300.…` would
                // round-trip forever and never emit any CSS.
                if (group === 'states') {
                    const seg = path.split('.');
                    if (STATES.indexOf(seg[1]) === -1 || seg.length < 3) {
                        err(line, msg('msgErrUnknownAttr', { name: rawName, slug: slug }));
                        return;
                    }
                }
                const prefix = state ? 'states.' + state + '.' : '';
                if (box) {
                    const declared = dataGet(contract.supports, box.path);
                    const values = raw.trim().split(/\s+/).slice(0, 4);
                    // A side-map declaration (or a multi-value) expands CSS-style; a scalar
                    // declaration with one value writes the scalar path directly.
                    if ((declared && typeof declared === 'object') || values.length > 1) {
                        const sides = expandBox(values, box.keys);
                        box.keys.forEach((k) => setPath(model.supports, prefix + box.path + '.' + k, sides[k]));
                        return;
                    }
                }
                setPath(model.supports, prefix + path, raw);
            };
            const attach = (frame) => {
                if (frame.dummy) return;
                const rich = richAttrOf(frame.contract);
                const body = dedent(frame.body.join(''));
                if (rich) {
                    if (body !== '') {
                        const richKey = window.hbEditor && window.hbEditor.resolveAttrKey ? window.hbEditor.resolveAttrKey(frame.model.name, rich) : rich;
                        frame.model.attributes[richKey] = body;
                    }
                }
                else if (body.trim() !== '' && !frame.model.innerBlocks.length) {
                    err(frame.line, msg('msgErrNoBody', { slug: frame.slug }));
                }
                const parent = stack.length ? stack[stack.length - 1] : null;
                if (parent && !parent.dummy) parent.model.innerBlocks.push(frame.model);
                else if (!parent) rootBlocks.push(frame.model);
            };
            const pushText = (chunk, at) => {
                if (!chunk) return;
                if (stack.length) { stack[stack.length - 1].body.push(chunk); return; }
                const off = chunk.search(/\S/);
                if (off !== -1) err(lineOf(at + off), msg('msgErrOutside', {}));
            };

            let last = 0, m;
            TAG_RE.lastIndex = 0;
            while ((m = TAG_RE.exec(text))) {
                pushText(text.slice(last, m.index), last);
                last = TAG_RE.lastIndex;
                const line = lineOf(m.index);
                const closing = !!m[1], slug = m[2], attrStr = m[3], selfClose = !!m[4];
                if (closing) {
                    if (!stack.length || stack[stack.length - 1].slug !== slug) { err(line, msg('msgErrStrayClose', { slug: slug })); continue; }
                    attach(stack.pop());
                    continue;
                }
                // Real slugs win; then the tag aliases (p, h1…h6 — the level rides the tag).
                let name = map[slug], preset = null;
                if (!name && TAG_SHORT[slug]) {
                    name = map[TAG_SHORT[slug].slug];
                    preset = TAG_SHORT[slug].attrs || null;
                }
                if (!name) {
                    err(line, msg('msgErrUnknownBlock', { slug: slug }));
                    if (!selfClose) stack.push({ slug: slug, dummy: true, body: [], line: line });
                    continue;
                }
                const contract = registry()[name];
                const parent = stack.length ? stack[stack.length - 1] : null;
                if (parent && !parent.dummy && !(parent.contract.innerBlocks && parent.contract.innerBlocks.enabled)) {
                    err(line, msg('msgErrNoChildren', { slug: parent.slug }));
                }
                const frame = {
                    slug: slug, contract: contract, body: [], line: line,
                    model: { name: name, attributes: {}, supports: {}, innerBlocks: [] },
                };
                if (preset) { for (const k in preset) frame.model.attributes[k] = preset[k]; }
                // Errors point at the attribute's OWN line — pretty-printed tags span
                // several lines, so the tag's first line would often be the wrong one.
                const attrsAt = m.index + 1 + slug.length;
                ATTR_RE.lastIndex = 0;
                let a;
                while ((a = ATTR_RE.exec(attrStr))) {
                    const value = a[2] !== undefined ? unescAttr(a[2]) : a[3];
                    applyAttr(contract, frame.model, a[1], value, lineOf(attrsAt + a.index), slug);
                }
                if (selfClose) { stack.push(frame); attach(stack.pop()); }
                else stack.push(frame);
            }
            pushText(text.slice(last), last);
            stack.forEach((frame) => {
                if (!frame.dummy) err(frame.line, msg('msgErrUnclosed', { slug: frame.slug, line: frame.line }));
            });
            return { blocks: rootBlocks, errors: errors };
        };

        // ── highlighter: same tokenizer, escaped segment by segment ─
        const escHtml = (s) => s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
        // Body prose: inline HTML tags get their own token color so rich-text markup reads
        // as markup, not as plain text.
        const bodyHtml = (text) => text.replace(/(<[^>]*>)|([^<]+)|(<)/g, (all, tag, plain, lone) =>
            tag ? '<span class="h">' + escHtml(tag) + '</span>' : escHtml(plain || lone || ''));
        // Attribute values color by TYPE: numbers/units, hex colours (underlined with
        // themselves — an inline swatch that can't disturb the overlay metrics), var()
        // token references, strings. State prefixes (hover:) get their own color.
        const valueToken = (value) => {
            if (value.charAt(0) === '"') return '<span class="s">' + value + '</span>';
            if (/^#[0-9a-fA-F]{3,8}$/.test(value)) return '<span class="c" style="border-bottom:2px solid ' + value + '">' + value + '</span>';
            if (/^-?[0-9.]+[a-z%]*$/i.test(value)) return '<span class="n">' + value + '</span>';
            if (/^var\(/.test(value)) return '<span class="v">' + value + '</span>';
            return '<span class="s">' + value + '</span>';
        };
        const nameToken = (name) => {
            const ci = name.indexOf(':');
            return ci > 0
                ? '<span class="st">' + name.slice(0, ci + 1) + '</span><span class="a">' + name.slice(ci + 1) + '</span>'
                : '<span class="a">' + name + '</span>';
        };
        const highlight = (text) => {
            let out = '', last = 0, m;
            TAG_RE.lastIndex = 0;
            while ((m = TAG_RE.exec(text))) {
                out += bodyHtml(text.slice(last, m.index));
                const prefix = '[' + (m[1] || '') + m[2];
                const suffix = m[0].slice(prefix.length + m[3].length);
                const attrs = escHtml(m[3]).replace(
                    /([a-zA-Z0-9_.:-]+)(\s*=\s*)("(?:[^"\\]|\\.)*"|[^\s\]"]*[^\s\]"\/])/g,
                    (all, name, eq, value) => nameToken(name) + '<span class="o">' + eq + '</span>' + valueToken(value)
                );
                out += '<span class="t">' + escHtml(prefix) + '</span>' + attrs + '<span class="t">' + escHtml(suffix) + '</span>';
                last = TAG_RE.lastIndex;
            }
            return out + bodyHtml(text.slice(last)) + '\n';
        };

        // ── the view ───────────────────────────────────────────────
        const boot = () => {
            document.querySelectorAll('[data-hb-codeview]').forEach((root) => {
                if (root.__hbCodeview) return;
                root.__hbCodeview = true;

                const input = root.querySelector('[data-hb-cv-input]');
                const hl = root.querySelector('[data-hb-cv-hl]');
                const nums = root.querySelector('[data-hb-cv-nums]');
                const status = root.querySelector('[data-hb-cv-status]');
                const statusList = root.querySelector('[data-hb-cv-status-list]');
                const msg = (key, repl) => {
                    let t = root.dataset[key] || '';
                    Object.keys(repl || {}).forEach((k) => { t = t.replace(':' + k, String(repl[k])); });
                    return t;
                };

                let visible = false, dirty = false, applying = false, timer = null;
                let errLines = new Set();

                const renderGutter = () => {
                    const count = input.value.split('\n').length;
                    let html = '';
                    for (let i = 1; i <= count; i++) html += '<span class="ln' + (errLines.has(i) ? ' is-err' : '') + '">' + i + '</span>';
                    nums.innerHTML = html;
                    activeLn = -1; // rebuilt — the band updater re-marks the active number
                };
                // Current-line band + active gutter number, tracked from the caret.
                const band = root.querySelector('[data-hb-cv-band]');
                let activeLn = -1;
                const updateBand = () => {
                    if (!band) return;
                    const caret = input.selectionStart == null ? 0 : input.selectionStart;
                    const line = input.value.slice(0, caret).split('\n').length;
                    band.style.transform = 'translate3d(0, ' + (16 + (line - 1) * LINE_H - input.scrollTop) + 'px, 0)';
                    if (activeLn !== line) {
                        const kids = nums.children;
                        if (kids[activeLn - 1]) kids[activeLn - 1].classList.remove('is-active');
                        if (kids[line - 1]) kids[line - 1].classList.add('is-active');
                        activeLn = line;
                    }
                };
                const refresh = () => {
                    hl.innerHTML = highlight(input.value);
                    renderGutter();
                    updateBand();
                    // Content growth changes scrollWidth/Height without any resize event the
                    // bars could see — nudge them so they appear/retract as the code changes.
                    root.querySelectorAll('[data-hb-custom-scrollbar]').forEach((b) => b.__hbScrollbar && b.__hbScrollbar.refresh());
                };
                const syncScroll = () => {
                    hl.scrollTop = input.scrollTop;
                    hl.scrollLeft = input.scrollLeft;
                    nums.style.transform = 'translateY(' + (-input.scrollTop) + 'px)';
                    updateBand();
                };
                const showErrors = (errors) => {
                    statusList.innerHTML = '';
                    errors.forEach((e) => {
                        const btn = document.createElement('button');
                        btn.type = 'button';
                        btn.className = 'hb-codeview__err';
                        btn.dataset.line = String(e.line);
                        const lineEl = document.createElement('b');
                        lineEl.textContent = msg('msgLineLabel', { line: e.line });
                        btn.appendChild(lineEl);
                        btn.appendChild(document.createTextNode(e.message));
                        statusList.appendChild(btn);
                    });
                    status.hidden = false;
                };
                const clearStatus = () => { status.hidden = true; statusList.innerHTML = ''; };

                const serializeIntoView = () => {
                    input.value = serializeDoc();
                    dirty = false;
                    errLines = new Set();
                    clearStatus();
                    refresh();
                    syncScroll();
                };
                // Only a CLEAN parse ever touches the doc — errors leave the canvas untouched
                // and light the gutter + status strip instead.
                const validateAndApply = () => {
                    const result = parseShortcode(input.value, msg);
                    errLines = new Set(result.errors.map((e) => e.line));
                    renderGutter();
                    if (result.errors.length) { showErrors(result.errors); return false; }
                    clearStatus();
                    applying = true;
                    window.hbEditor.replaceDoc(result.blocks);
                    applying = false;
                    dirty = false;
                    return true;
                };
                const onInput = () => {
                    dirty = true;
                    refresh();
                    clearTimeout(timer);
                    timer = setTimeout(() => { if (visible && dirty) validateAndApply(); }, 500);
                };

                const setMode = (code) => {
                    const shell = document.querySelector('.hb-editor');
                    const chip = document.querySelector('[data-hb="code-editor"]');
                    visible = code;
                    root.hidden = !code;
                    if (shell) shell.classList.toggle('hb-editor--codeview', code);
                    if (chip) {
                        chip.setAttribute('aria-pressed', code ? 'true' : 'false');
                        // The chip names the surface the NEXT click takes you to.
                        const label = chip.querySelector('span');
                        const next = code ? chip.dataset.labelVisual : chip.dataset.labelCode;
                        if (label && next) label.textContent = next;
                        const title = code ? chip.dataset.titleVisual : chip.dataset.titleCode;
                        if (title) chip.title = title;
                    }
                    if (code) { serializeIntoView(); input.focus(); }
                };

                // Machine-authored markup belongs in the Code view — that is where a generated
                // page is read and corrected. The AI panel applies its blocks through the
                // runtime and then calls these, so the result shows up as code rather than
                // only as text in the chat. `sync` is the no-jump variant: it refreshes the
                // view if it is already open and pristine, and does nothing otherwise.
                window.hbCodeView.open = () => setMode(true);
                window.hbCodeView.sync = () => { if (visible && !dirty) serializeIntoView(); };

                input.addEventListener('input', onInput);
                input.addEventListener('scroll', syncScroll);
                input.addEventListener('click', updateBand);
                input.addEventListener('keyup', updateBand);
                input.addEventListener('focus', updateBand);
                input.addEventListener('keydown', (event) => {
                    if (event.key !== 'Tab') return;
                    event.preventDefault();
                    input.setRangeText('  ', input.selectionStart, input.selectionEnd, 'end');
                    onInput();
                });
                root.querySelector('[data-hb-cv-revert]')?.addEventListener('click', serializeIntoView);
                statusList.addEventListener('click', (event) => {
                    const item = event.target.closest('[data-line]');
                    if (!item) return;
                    const line = parseInt(item.dataset.line, 10) || 1;
                    const lines = input.value.split('\n');
                    let index = 0;
                    for (let i = 0; i < line - 1 && i < lines.length; i++) index += lines[i].length + 1;
                    input.focus();
                    input.setSelectionRange(index, index + (lines[line - 1] || '').length);
                    input.scrollTop = Math.max(0, (line - 1) * LINE_H - 60);
                    syncScroll();
                });

                // The footer chip is the single Visual ⇄ Code switch. Leaving Code with
                // pending edits parses first; errors block the switch so nothing is lost.
                if (!document.__hbCodeToggle) {
                    document.__hbCodeToggle = true;
                    document.addEventListener('click', (event) => {
                        const chip = event.target.closest('[data-hb="code-editor"]');
                        if (!chip || !window.hbEditor) return;
                        if (!visible) { setMode(true); return; }
                        clearTimeout(timer);
                        if (!dirty || validateAndApply()) setMode(false);
                    });
                }

                // A doc change made elsewhere (e.g. an inspector edit) while Code is open and
                // pristine re-serializes; once the user has typed, their text wins on apply.
                const onExternalChange = () => { if (visible && !applying && !dirty) serializeIntoView(); };
                document.addEventListener('hb:blocks-changed', onExternalChange);
                document.addEventListener('hb:block-updated', onExternalChange);
            });
        };
        // The dialect, available to anything else on the page. docs/code-view.md calls this the
        // machine-authoring surface, and the AI panel is its first non-human caller: routing
        // generated markup through THIS parser means AI output is validated against the same
        // registry the canvas uses, reports the same line-numbered errors, and lands through the
        // same undo stack — instead of a second, drifting insertion path.
        //
        // `parse` supplies its own message lookup because the real one reads a root element's
        // data-* strings, which a non-DOM caller doesn't have; error *positions* are what matter
        // to a caller that isn't rendering the gutter.
        window.hbCodeView = {
            parse: (text) => parseShortcode(String(text == null ? '' : text), (key) => key),
            serialize: serializeDoc,
        };

        if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot, { once: true });
        else boot();
        document.addEventListener('hb:refresh', boot);
    })();
</script>
@endonce

<div {{ $attributes->merge(['class' => 'hb-codeview']) }} data-hb-codeview
    data-msg-err-unknown-block="{{ __('heisenberg::editor.code.err_unknown_block') }}"
    data-msg-err-unknown-attr="{{ __('heisenberg::editor.code.err_unknown_attr') }}"
    data-msg-err-invalid-value="{{ __('heisenberg::editor.code.err_invalid_value') }}"
    data-msg-err-no-children="{{ __('heisenberg::editor.code.err_no_children') }}"
    data-msg-err-no-body="{{ __('heisenberg::editor.code.err_no_body') }}"
    data-msg-err-stray-close="{{ __('heisenberg::editor.code.err_stray_close') }}"
    data-msg-err-unclosed="{{ __('heisenberg::editor.code.err_unclosed') }}"
    data-msg-err-outside="{{ __('heisenberg::editor.code.err_outside') }}"
    data-msg-line-label="{{ __('heisenberg::editor.code.line_label') }}">
    <div class="hb-codeview__main">
        <div class="hb-codeview__gutter" aria-hidden="true"><div class="hb-codeview__nums" data-hb-cv-nums></div></div>
        <div class="hb-codeview__editor">
            <div class="hb-codeview__band" data-hb-cv-band aria-hidden="true"></div>
            <pre class="hb-codeview__hl" data-hb-cv-hl aria-hidden="true"></pre>
            <textarea class="hb-codeview__input" data-hb-cv-input spellcheck="false" wrap="off"
                aria-label="{{ __('heisenberg::editor.code.aria_input') }}"
                placeholder="{{ __('heisenberg::editor.code.placeholder') }}"></textarea>
            {{-- Smoothing off on both axes: precise scrolling matters in a text surface, and
                 caret-driven native scrolls must never fight an easing loop. --}}
            <x-ui.custom-scrollbar container="[data-hb-cv-input]" :smooth="false" />
            <x-ui.custom-scrollbar container="[data-hb-cv-input]" axis="x" :smooth="false" />
        </div>
    </div>
    <div class="hb-codeview__status" data-hb-cv-status hidden>
        <span class="hb-codeview__status-title">{{ __('heisenberg::editor.code.errors_title') }}</span>
        <div class="hb-codeview__status-list" data-hb-cv-status-list></div>
        <button type="button" class="hb-codeview__revert" data-hb-cv-revert>{{ __('heisenberg::editor.code.revert') }}</button>
    </div>
</div>
