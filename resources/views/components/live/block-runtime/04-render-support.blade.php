{{--
    Section: rendering support — preview states, icon injection, computed styles, embeds.

    Owns: previewStates (the hover/active/focus preview override map keyed by block id); the
    icon fetch/cache pipeline (iconCache, iconPending, injectLibraryIcon — fetches an SVG from the
    icon library endpoint and caches it); styleDeclarations() (turns a contract's style.variables
    plus a model's attributes/supports/preview-state overrides into a sanitized inline `style`
    string — its `surface` parameter degrades a validated gradient to its first colour stop for
    `'email'`, mirroring {@see CssValueSanitizer::sanitizeCssValue()}'s own surface handling
    exactly; the contract-level className/classNames/align skip for email is 05-render-tree's own
    concern, not this function's) and predicateMatches() (its conditional-classNames helper);
    safeUrl() (the src/href/srcset/poster scheme allowlist); and the embed-URL recognizer
    (EMBED_SRC_PATTERN, EMBED_FILE_SRC_PATTERN, EMBED_RULES and the embedStartSeconds/
    vimeoQueryHash/embedClean/embedSrcFor/embedFileSrcFor pipeline that turns a pasted YouTube/
    Vimeo/etc. URL into a safe embeddable iframe src — kept LOCKSTEP with BlockRenderer's
    server-side copies, see that class's own comments) plus alignmentValuesFor().

    Depends on: cssValueValid()/normalizeCssNumber() (03-style-sanitizers) for styleDeclarations();
    isSafeGradientValue()/firstGradientStopColor() (03-style-sanitizers) for styleDeclarations()'s
    email gradient degrade; dataGet() (03-style-sanitizers) for reading nested supports/attribute
    paths; DATA (01-bootstrap-and-email-variables) for the icon URL template; REGISTRY
    (01-bootstrap-and-email-variables) for alignmentValuesFor(); RENDER_SURFACE
    (01-bootstrap-and-email-variables) as styleDeclarations()'s default when a caller omits
    `surface`.

    Defines for later sections: previewStates, iconCache, iconPending, injectLibraryIcon(),
    styleDeclarations(), styleVariableMap(), resolveEmailVars(), emailLayoutFor(), predicateMatches(), safeUrl(), embedSrcFor(), embedFileSrcFor(),
    alignmentValuesFor().
--}}
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

    function styleDeclarations(model, contract, surface) {
        const map = styleVariableMap(model, contract, surface);
        const declarations = [];
        for (const name in map) declarations.push(name + ': ' + map[name]);
        return declarations.length ? declarations.join('; ') + ';' : '';
    }

    // BlockStyleCompiler::blockStyleMap(): variable name -> sanitized value for ONE block
    // instance. A variable with no authored value and no default is ABSENT, so a
    // `var(--name, fallback)` reading it takes its fallback.
    function styleVariableMap(model, contract, surface) {
        surface = surface || RENDER_SURFACE;
        const map = {};
        const variables = contract && contract.style && contract.style.variables;
        if (!variables || typeof variables !== 'object') return map;
        // Interaction states do not exist in a mail client, so the email surface never previews
        // one — the canvas must show what is sent.
        const state = surface === 'email' ? null : previewStates[model.id];

        const overrides = state && state !== 'default'
            ? dataGet(model.supports || {}, 'states.' + state)
            : null;
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
            let safe = cssValueValid(value, sanitizer) ? value : (cssValueValid(fallback, sanitizer) ? fallback : '');
            // §Bug A step 5 / CssValueSanitizer::sanitizeCssValue(): Outlook can't render a CSS
            // gradient, so the email surface ships its first colour stop as a flat fallback
            // instead — run on whatever ended up safe (value or fallback), so a gradient DEFAULT
            // degrades exactly like an authored one.
            if (safe !== '' && surface === 'email' && isSafeGradientValue(safe)) safe = firstGradientStopColor(safe);
            if (safe) {


                let finalSafe = safe;
                if (sanitizer === 'font-family' && finalSafe.length > 0
                    && finalSafe[0] !== '"' && finalSafe[0] !== "'"
                    && /\s/.test(finalSafe)) {
                    finalSafe = '"' + finalSafe.replace(/"/g, '\\"') + '"';
                }
                map[name] = finalSafe;
            }
        }
        return map;
    }

    /**
     * EMAIL SURFACE — the exact mirror of BlockStyleCompiler::resolveEmailVars(): fill every
     * `var(--x[, fallback])` that reads one of THIS contract's own style variables with this
     * block's literal value, or the usage's fallback when the block sets nothing. A `var()`
     * naming anything else (a design token such as `var(--ink)`) is left for the browser, as
     * the PHP leaves it for EmailRenderer's theme-token pass.
     *
     * Done as string substitution rather than by declaring the properties on the root and
     * letting the cascade resolve them, deliberately: custom properties INHERIT, so a Group's
     * `--hb-border-top-width` or `--hb-text-align` would reach every block nested inside it on
     * the canvas while the sent mail — resolved per block — shows no such thing.
     */
    function resolveEmailVars(css, model, contract) {
        css = String(css == null ? '' : css);
        if (css.toLowerCase().indexOf('var(') === -1) return css;
        const variables = (contract && contract.style && contract.style.variables) || {};
        return substituteEmailVars(css, variables, styleVariableMap(model, contract, 'email'), 0);
    }
    function substituteEmailVars(css, variables, map, depth) {
        let out = '';
        let offset = 0;
        const lower = css.toLowerCase();
        while (depth < 8) {
            const start = lower.indexOf('var(', offset);
            if (start === -1) break;
            let level = 0, end = -1;
            for (let i = start + 3; i < css.length; i++) {
                if (css[i] === '(') level++;
                else if (css[i] === ')' && --level === 0) { end = i; break; }
            }
            if (end === -1) break;
            const inner = css.slice(start + 4, end);
            const comma = inner.indexOf(',');
            const name = (comma === -1 ? inner : inner.slice(0, comma)).trim();
            const fallback = comma === -1 ? null : inner.slice(comma + 1).trim();
            out += css.slice(offset, start);
            if (!Object.prototype.hasOwnProperty.call(variables, name)) out += css.slice(start, end + 1);
            else if (Object.prototype.hasOwnProperty.call(map, name)) out += map[name];
            else if (fallback !== null) out += substituteEmailVars(fallback, variables, map, depth + 1);
            offset = end + 1;
        }
        return out + css.slice(offset);
    }
    // BlockStyleCompiler::emailLayout() — a container's flex layout translated into table-cell
    // terms: direction (row = one cell per child), gap (spacer row/cell), and justify/align
    // resolved to cell `align`/`valign`, the axes swapping with direction exactly as flexbox's do.
    function emailLayoutFor(model, contract) {
        const map = styleVariableMap(model, contract, 'email');
        const row = String(map['--hb-flex-direction'] || 'column').indexOf('row') === 0;
        const justify = map['--hb-flex-justify'] || '';
        const align = map['--hb-flex-align'] || '';
        const horizontal = { start: 'left', center: 'center', end: 'right' };
        const vertical = { start: 'top', center: 'middle', end: 'bottom' };
        return {
            row: row,
            gap: map['--hb-flex-gap'] || '',
            spread: row && (justify === 'space-between' || justify === 'space-around'),
            align: horizontal[row ? justify : align] || '',
            valign: vertical[row ? align : justify] || '',
        };
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

