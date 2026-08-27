

@props(['registry' => [], 'blocksCss' => '', 'registryHash' => '', 'postId' => null, 'postLocale' => 'en', 'contentLocales' => ['en', 'fr']])



<style id="hb-blocks-css" nonce="{{ heisenberg_csp_nonce() }}">{!! $blocksCss !!}</style>

<script nonce="{{ heisenberg_csp_nonce() }}">
    window.__hbEditor = Object.assign(window.__hbEditor || {}, {
        registry: @json($registry),

        registryHash: @json($registryHash),

        iconUrlTemplate: @json(\Illuminate\Support\Facades\Route::has('heisenberg.editor.asset.icon') ? route('heisenberg.editor.asset.icon', ['set' => '__SET__', 'slug' => '__SLUG__']) : ''),

        postId: @json($postId),
        postLocale: @json($postLocale),
        contentLocales: @json($contentLocales),
    });
</script>

@once
<script nonce="{{ heisenberg_csp_nonce() }}">
(() => {
    const DATA = window.__hbEditor || {};
    const REGISTRY = DATA.registry || {};

    const homeLocale = DATA.postLocale || 'en';
    const CONTENT_LOCALES = Array.isArray(DATA.contentLocales) && DATA.contentLocales.length ? DATA.contentLocales : [homeLocale];
    let editingLocale = homeLocale;
    let currentPostId = DATA.postId != null ? DATA.postId : null;
    const localeStorageKey = () => 'hb-editor:editing-locale:' + (currentPostId != null ? currentPostId : 'new');
    const persistEditingLocale = (locale) => { try { localStorage.setItem(localeStorageKey(), locale); } catch (e) {  } };
    (function initEditingLocale() {
        try {
            const stored = localStorage.getItem(localeStorageKey());
            if (stored && CONTENT_LOCALES.indexOf(stored) !== -1) editingLocale = stored;
        } catch (e) {  }
    })();

    document.addEventListener('hb:post-id', function (event) {
        currentPostId = event && event.detail ? event.detail.id : currentPostId;
        persistEditingLocale(editingLocale);
    });


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
            if (tok.indexOf('attributes.') === 0) { const v = readAttr(model, tok.slice(11)); return v == null ? '' : String(v); }
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

    const HB_ALPHA = '(?:0|1|0?\\.\\d+|1\\.0+|(?:100|\\d{1,2})(?:\\.\\d+)?%)';
    function isSafeColorToken(value) {
        return /^var\(--(?:accent-[a-z0-9-]+|ink|faint|paper)\)$/.test(value)
            || /^#[0-9a-f]{3,8}$/i.test(value)
            || new RegExp('^rgba?\\(\\s*(25[0-5]|2[0-4]\\d|1?\\d?\\d)\\s*,\\s*(25[0-5]|2[0-4]\\d|1?\\d?\\d)\\s*,\\s*(25[0-5]|2[0-4]\\d|1?\\d?\\d)(\\s*,\\s*' + HB_ALPHA + ')?\\s*\\)$', 'i').test(value)
            || new RegExp('^hsla?\\(\\s*(360|3[0-5]\\d|[12]?\\d?\\d)\\s*,\\s*(100|\\d?\\d)%\\s*,\\s*(100|\\d?\\d)%(\\s*,\\s*' + HB_ALPHA + ')?\\s*\\)$', 'i').test(value);
    }
    function isSafeLengthSignedValue(value) { return /^(0|-?\d+(\.\d+)?(px|rem|em|%|vw|vh))$/i.test(value); }

    const GRADIENT_POSITION = '-?\\d+(?:\\.\\d+)?(?:%|px|rem|em|vw|vh)';
    function isSafeLinearPreamble(part) {
        part = part.trim();
        return /^-?\d{1,3}(\.\d+)?deg$/i.test(part)
            || /^to\s+(?:(?:top|bottom)(?:\s+(?:left|right))?|(?:left|right)(?:\s+(?:top|bottom))?)$/i.test(part);
    }
    function isSafeRadialPreamble(part) {
        part = part.trim();
        if (!part) return false;
        const pos = '(?:center|top|bottom|left|right|' + GRADIENT_POSITION + ')';
        return /^(?:circle|ellipse)$/i.test(part)
            || new RegExp('^(?:(?:circle|ellipse)\\s+)?at\\s+' + pos + '(?:\\s+' + pos + ')?$', 'i').test(part);
    }
    function isSafeGradientStop(stop) {
        const tokens = splitTopLevel(stop.trim(), ' ').filter((t) => t !== '');
        if (tokens.length < 1 || tokens.length > 2) return false;
        if (!isSafeColorToken(tokens[0])) return false;
        return tokens.length === 1 || new RegExp('^' + GRADIENT_POSITION + '$', 'i').test(tokens[1]);
    }
    function isSafeGradientValue(value) {
        value = value.trim();
        const prefix = /^(linear|radial)-gradient\(/i.exec(value);
        if (!prefix || !value.endsWith(')')) return false;
        const kind = prefix[1].toLowerCase();
        const inner = value.slice(prefix[0].length, -1);
        if ((inner.match(/\(/g) || []).length !== (inner.match(/\)/g) || []).length) return false;
        const parts = splitTopLevel(inner, ',');
        if (!parts.length || parts[0] === '') return false;
        const isPreamble = kind === 'linear' ? isSafeLinearPreamble : isSafeRadialPreamble;
        if (isPreamble(parts[0])) parts.shift();
        if (parts.length < 2) return false;
        return parts.every(isSafeGradientStop);
    }

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

    function normalizeCssNumber(value, sanitizer) {
        if (!/^-?\d+(\.\d+)?$/.test(value)) return value;
        if (sanitizer === 'size-value' || sanitizer === 'length-signed') return value + 'px';
        if (sanitizer === 'angle') return value + 'deg';
        if (sanitizer === 'opacity' && Number(value) > 1) return value + '%';
        return value;
    }

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
        if (sanitizer === 'color-value-or-gradient') {
            if (value === 'transparent') return true;
            return /^var\(--[a-z0-9-]+\)$/i.test(value) || isSafeColorToken(value) || isSafeGradientValue(value);
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

    const previewStates = {};


    const iconCache = {};
    const iconPending = {};
    function injectLibraryIcon(el, reference) {
        if (Object.prototype.hasOwnProperty.call(iconCache, reference)) {
            if (iconCache[reference]) el.innerHTML = iconCache[reference];
            return;
        }
        if (iconPending[reference]) return;
        const template = DATA.iconUrlTemplate || '';
        if (!template) return;
        iconPending[reference] = true;
        const parts = reference.split('/');
        window.fetch(template.replace('__SET__', parts[0]).replace('__SLUG__', parts[1]), { credentials: 'same-origin' })
            .then((r) => (r.ok ? r.text() : ''))
            .then((svg) => {


                iconCache[reference] = (svg && svg.indexOf('<script') === -1) ? svg : '';
                delete iconPending[reference];
                if (!iconCache[reference]) return;
                document.querySelectorAll('[data-hb-icon="' + reference + '"]').forEach(function (span) {
                    span.innerHTML = iconCache[reference];
                });
            })
            .catch(function () { delete iconPending[reference]; });
    }

    function styleDeclarations(model, contract) {
        const variables = contract && contract.style && contract.style.variables;
        if (!variables || typeof variables !== 'object') return '';
        const state = previewStates[model.id];

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
            if (safe) {


                let finalSafe = safe;
                if (sanitizer === 'font-family' && finalSafe.length > 0
                    && finalSafe[0] !== '"' && finalSafe[0] !== "'"
                    && /\s/.test(finalSafe)) {
                    finalSafe = '"' + finalSafe.replace(/"/g, '\\"') + '"';
                }
                declarations.push(name + ': ' + finalSafe);
            }
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


    const EMBED_SRC_PATTERN = /^https:\/\/(?:www\.youtube(?:-nocookie)?\.com\/embed\/|player\.vimeo\.com\/video\/|www\.dailymotion\.com\/embed\/video\/|www\.loom\.com\/embed\/|fast\.wistia\.net\/embed\/iframe\/|streamable\.com\/e\/|www\.tiktok\.com\/embed\/v2\/|customer-[a-z0-9]{1,40}\.cloudflarestream\.com\/)[A-Za-z0-9_/?=&.-]+$/;
    const EMBED_FILE_SRC_PATTERN = /^https:\/\/[A-Za-z0-9](?:[A-Za-z0-9.-]{0,251}[A-Za-z0-9])?(?::[0-9]{1,5})?\/[A-Za-z0-9._~%!$&()*+,;=:/-]*\.(?:mp4|webm|ogg|ogv|mov)(?:\?[A-Za-z0-9._~%!$&()*+,;=:/?-]*)?(?:#[A-Za-z0-9._~%!$&()*+,;=:/?-]*)?$/i;
    const EMBED_RULES = [


        { re: /^(?:(?:https?:)?\/\/)?(?:www\.|m\.|music\.)?youtube\.com\/watch\?(?:[^#]*&)?v=([A-Za-z0-9_-]{5,20})(?:[&#].*)?$/i, out: 'yt' },
        { re: /^(?:(?:https?:)?\/\/)?(?:www\.|m\.|music\.)?youtube\.com\/shorts\/([A-Za-z0-9_-]{5,20})(?:[/?#].*)?$/i, out: 'yt' },
        { re: /^(?:(?:https?:)?\/\/)?(?:www\.|m\.|music\.)?youtube\.com\/live\/([A-Za-z0-9_-]{5,20})(?:[/?#].*)?$/i, out: 'yt' },
        { re: /^(?:(?:https?:)?\/\/)?(?:www\.|m\.|music\.)?youtube\.com\/v\/([A-Za-z0-9_-]{5,20})(?:[/?#].*)?$/i, out: 'yt' },
        { re: /^(?:(?:https?:)?\/\/)?(?:www\.|m\.|music\.)?youtube(?:-nocookie)?\.com\/embed\/([A-Za-z0-9_-]{5,20})(?:[/?#].*)?$/i, out: 'yt' },
        { re: /^(?:(?:https?:)?\/\/)?(?:www\.)?youtu\.be\/([A-Za-z0-9_-]{5,20})(?:[/?#].*)?$/i, out: 'yt' },



        { re: /^(?:(?:https?:)?\/\/)?(?:www\.)?vimeo\.com\/([0-9]{1,15})(?:\/([A-Za-z0-9]{6,32}))?(?:[/?#].*)?$/i, out: 'vimeo' },
        { re: /^(?:(?:https?:)?\/\/)?player\.vimeo\.com\/video\/([0-9]{1,15})(?:[/?#].*)?$/i, out: 'vimeo' },
        { re: /^(?:(?:https?:)?\/\/)?(?:www\.)?vimeo\.com\/channels\/[A-Za-z0-9_-]{1,64}\/([0-9]{1,15})(?:[/?#].*)?$/i, out: 'vimeo' },
        { re: /^(?:(?:https?:)?\/\/)?(?:www\.)?vimeo\.com\/groups\/[A-Za-z0-9_-]{1,64}\/videos\/([0-9]{1,15})(?:[/?#].*)?$/i, out: 'vimeo' },
        { re: /^(?:(?:https?:)?\/\/)?(?:www\.)?vimeo\.com\/showcase\/[0-9]{1,15}\/video\/([0-9]{1,15})(?:[/?#].*)?$/i, out: 'vimeo' },



        { re: /^(?:(?:https?:)?\/\/)?(?:www\.)?dailymotion\.com\/video\/([A-Za-z0-9]{5,20})(?:[_/?#].*)?$/i, out: 'dm' },
        { re: /^(?:(?:https?:)?\/\/)?(?:www\.)?dailymotion\.com\/embed\/video\/([A-Za-z0-9]{5,20})(?:[_/?#].*)?$/i, out: 'dm' },
        { re: /^(?:(?:https?:)?\/\/)?dai\.ly\/([A-Za-z0-9]{5,20})(?:[_/?#].*)?$/i, out: 'dm' },



        { re: /^(?:(?:https?:)?\/\/)?(?:www\.)?loom\.com\/share\/([A-Za-z0-9]{16,64})(?:[/?#].*)?$/i, out: 'loom' },
        { re: /^(?:(?:https?:)?\/\/)?(?:www\.)?loom\.com\/embed\/([A-Za-z0-9]{16,64})(?:[/?#].*)?$/i, out: 'loom' },



        { re: /^(?:(?:https?:)?\/\/)?(?:[A-Za-z0-9-]{1,63}\.)?wistia\.com\/medias\/([A-Za-z0-9]{6,20})(?:[/?#].*)?$/i, out: 'wistia' },
        { re: /^(?:(?:https?:)?\/\/)?(?:[A-Za-z0-9-]{1,63}\.)?wistia\.net\/(?:medias|embed\/iframe)\/([A-Za-z0-9]{6,20})(?:[/?#].*)?$/i, out: 'wistia' },
        { re: /^(?:(?:https?:)?\/\/)?wi\.st\/medias\/([A-Za-z0-9]{6,20})(?:[/?#].*)?$/i, out: 'wistia' },



        { re: /^(?:(?:https?:)?\/\/)?(?:www\.)?streamable\.com\/(?:e\/)?([A-Za-z0-9]{3,12})(?:[/?#].*)?$/i, out: 'streamable' },



        { re: /^(?:(?:https?:)?\/\/)?(?:www\.|m\.)?tiktok\.com\/@[A-Za-z0-9._-]{1,30}\/video\/([0-9]{5,25})(?:[/?#].*)?$/i, out: 'tiktok' },
        { re: /^(?:(?:https?:)?\/\/)?(?:www\.)?tiktok\.com\/embed\/v2\/([0-9]{5,25})(?:[/?#].*)?$/i, out: 'tiktok' },



        { re: /^(?:(?:https?:)?\/\/)?customer-([A-Za-z0-9]{1,40})\.cloudflarestream\.com\/([A-Za-z0-9]{8,64})\/(?:watch|iframe)(?:[/?#].*)?$/i, out: 'cfstream' },
    ];

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


    function vimeoQueryHash(url) {
        const m = /[?&]h=([A-Za-z0-9]{6,32})(?:[&#]|$)/i.exec(url);
        return m ? m[1] : '';
    }
    function embedClean(url) {

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

    function embedFileSrcFor(url) {
        const clean = embedClean(url);
        return clean !== '' && EMBED_FILE_SRC_PATTERN.test(clean) ? clean : '';
    }
    function alignmentValuesFor(name) {
        const values = REGISTRY[name] && REGISTRY[name].supports && REGISTRY[name].supports.align;
        if (!Array.isArray(values)) return [];
        return values.filter((value, index) => ['left', 'center', 'right'].indexOf(value) >= 0 && values.indexOf(value) === index);
    }

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
            const val = readAttr(model, node.attribute);

            span.classList.add('hb-ce');
            span.setAttribute('contenteditable', 'true');
            span.spellcheck = true;
            span.setAttribute('data-hb-rt', node.attribute || '');
            span.setAttribute('data-ph', 'Write something…');
            span.innerHTML = (val == null ? '' : String(val));
            return span;
        }


        if (type === 'icon') {
            const reference = String(model.attributes[node.attribute] == null ? '' : model.attributes[node.attribute]).trim();
            if (!/^[a-z0-9-]+\/[a-z0-9-]+$/.test(reference)) return null;
            const span = document.createElement('span');
            if (node.class) { const c = subst(node.class, model); if (c) span.className = c; }
            span.setAttribute('data-hb-icon', reference);
            injectLibraryIcon(span, reference);
            return span;
        }


        if (type === 'text-lines') {
            const frag = document.createDocumentFragment();
            const raw = readAttr(model, node.attribute);
            let lineTag = String(node.tag || 'li').toLowerCase();
            if (!/^[a-z][a-z0-9-]*$/.test(lineTag)) lineTag = 'li';
            const lineCls = node.class ? subst(node.class, model) : '';
            String(raw == null ? '' : raw).split(/\r\n|\r|\n/).forEach(function (line) {
                line = line.trim();
                if (!line) return;
                const li = document.createElement(lineTag);
                if (lineCls) li.className = lineCls;
                li.textContent = line;
                frag.appendChild(li);
            });
            return frag;
        }


        if (type === 'inner-blocks') {
            const frag = document.createDocumentFragment();
            const inner = Array.isArray(model.innerBlocks) ? model.innerBlocks : [];
            for (let i = 0; i < inner.length; i++) {
                if (depth >= MAX_NESTING_DEPTH) break;
                const el = renderBlockEl(inner[i], depth + 1);
                if (el) frag.appendChild(el);
            }


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

                if ('embed' in raw) {
                    const src = embedSrcFor(subst(raw.embed, model));
                    if (src !== '') el.setAttribute(an, src);
                    continue;
                }


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
        decorateIconBlock(wrap, model);
        return wrap;
    }


    function decorateIconBlock(container, model) {
        if (!container || !model || model.name.indexOf('/icon') === -1) return;
        const reference = model.attributes && model.attributes.icon;
        if (reference && /^[a-z0-9-]+\/[a-z0-9-]+$/.test(String(reference).trim())) return;
        if (container.querySelector('.hb-icon-empty')) return;
        const ph = document.createElement('button');
        ph.type = 'button';
        ph.className = 'hb-icon-empty';
        ph.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 8v8M8 12h8"/></svg><span>Select icon</span>';
        ph.addEventListener('click', function () {
            document.dispatchEvent(new CustomEvent('hb:pick-icon', { detail: { id: model.id, model: model }, cancelable: true }));
        });
        (container.querySelector('[data-block-id]') || container).appendChild(ph);
    }


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

    function wireCanvasBlockDrag() {
        var noDrag = '.hb-ce, a, button, input, textarea, select, label, [data-hb-inner-appender], .hb-img-empty, [data-image-picker], .hb-tb';
        document.addEventListener('pointerdown', (e) => {
            if (e.button != null && e.button !== 0) return;
            const wrap = wrapEl();
            if (!wrap) return;
            const grip = e.target.closest && e.target.closest('.hb-tb__btn--drag');
            let blk = null;
            let pressEl = null;
            if (grip) {
                blk = selected;
                pressEl = grip;
            } else {
                pressEl = e.target.closest && e.target.closest('.hb-blk');
                if (!pressEl || !wrap.contains(pressEl)) return;
                if (e.target.closest(noDrag)) return;
                blk = pressEl;
            }
            if (!blk) return;
            const id = blk.getAttribute('data-block');
            const model = id ? findModel(id) : null;
            if (!model) return;
            e.preventDefault();
            select(blk);
            try { pressEl.setPointerCapture(e.pointerId); } catch (err) { }
            const startX = e.clientX, startY = e.clientY;
            let active = false;
            let hover = null;
            let inside = null;
            let insideEl = null;
            let insideMark = null;

            function clearInsideMarks() {
                if (insideEl) { insideEl.classList.remove('is-drop-inside'); insideEl = null; }
                if (insideMark) { insideMark.classList.remove('is-drop-before', 'is-drop-after'); insideMark = null; }
            }
            function onMove(ev) {
                autoScrollY = ev.clientY;
                if (!active) {
                    if (Math.abs(ev.clientX - startX) + Math.abs(ev.clientY - startY) < 5) return;
                    active = true;
                    blk.classList.add('is-dragging');
                    document.body.classList.add('hb-canvas-drag');
                    startAutoScroll();
                }
                clearDropMarks(wrap, ':scope > .hb-blk');
                clearInsideMarks();
                hover = null;
                inside = null;
                if (!overCanvas(ev.clientX, ev.clientY)) return;
                const target = containerAt(ev.clientX, ev.clientY, model.name);
                if (target && target.blk !== blk && !blk.contains(target.blk)) {
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
                hover = resolveDropItem(wrap, ':scope > .hb-blk', blk, ev.clientX, ev.clientY);
                if (hover) hover.el.classList.add(hover.below ? 'is-drop-after' : 'is-drop-before');
            }
            function cleanup() {
                pressEl.removeEventListener('pointermove', onMove);
                pressEl.removeEventListener('pointerup', onUp);
                pressEl.removeEventListener('pointercancel', onCancel);
                blk.classList.remove('is-dragging');
                document.body.classList.remove('hb-canvas-drag');
                clearDropMarks(wrap, ':scope > .hb-blk');
                clearInsideMarks();
                stopAutoScroll();
            }
            function onUp() {
                if (active) {
                    if (inside) {
                        moveBlockTo(id, inside.id, inside.index);
                    } else if (hover) {
                        const hoverIndex = indexOf(hover.el.getAttribute('data-block'));
                        if (hoverIndex !== -1) moveBlockTo(id, null, hover.below ? hoverIndex + 1 : hoverIndex);
                    }
                }
                cleanup();
            }
            function onCancel() { cleanup(); }
            pressEl.addEventListener('pointermove', onMove);
            pressEl.addEventListener('pointerup', onUp);
            pressEl.addEventListener('pointercancel', onCancel);
        });
    }

    const RESIZE_BAND = 6;
    const RESIZE_MIN = 24;
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
            try { hit.root.setPointerCapture(e.pointerId); } catch (err) { }
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
            model.attributes[resolveAttrKey(model.name, ce.getAttribute('data-hb-rt'))] = ce.innerHTML;
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

    function setEditingLocale(locale) {
        if (typeof locale !== 'string' || CONTENT_LOCALES.indexOf(locale) === -1) return false;
        editingLocale = locale;
        persistEditingLocale(editingLocale);
        doc.blocks.forEach(function (m) { reRenderBlock(m.id); });
        const id = selected ? selected.getAttribute('data-block') : null;
        const model = id ? findModel(id) : null;
        if (model) document.dispatchEvent(new CustomEvent('hb:block-selected', { detail: { name: model.name, model: model } }));
        document.dispatchEvent(new CustomEvent('hb:editing-locale-change', { detail: { locale: editingLocale, homeLocale: homeLocale } }));
        return true;
    }

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
        getEditingLocale: function () { return editingLocale; },
        getHomeLocale: function () { return homeLocale; },
        getContentLocales: function () { return CONTENT_LOCALES.slice(); },
        setEditingLocale: setEditingLocale,
        resolveAttrKey: resolveAttrKey,
        readAttr: readAttr,
        moveById: moveById,
        moveBlockTo: moveBlockTo,
        duplicateBlock: duplicateBlock,
        insertPattern: insertPattern,
        previewState: previewState,
        parentIdOf: function (id) { return parentIdOf(id); },
        moveBlock: moveBlock,
        removeBlock: removeBlock,
        selectById: selectById,
        reRenderBlock: reRenderBlock,
        replaceDoc: replaceDoc,
        foldTranslation: foldTranslation,
        applyCanvasWrite: applyCanvasWrite,
        undo: undo,
        redo: redo,
        canUndo: function () { return history.past.length > 0; },
        canRedo: function () { return history.future.length > 0; },
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
