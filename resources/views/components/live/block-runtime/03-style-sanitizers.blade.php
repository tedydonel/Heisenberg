{{--
    Section: template substitution & inline-style value sanitizers.

    Owns: the "{{ attributes.foo }}" template substitution engine (truthy, dataGet, subst) used
    by node templates and dynamic tag names; the dynamic-tag allowlist (DYN_TAGS) and
    resolveTag(); and the whole family of CSS value validators that gate what
    styleDeclarations() (04-render-support) is allowed to emit as an inline `style` attribute —
    color tokens, gradients, shadows, lengths, angles, etc. (isSafeColorToken, isSafeLengthSignedValue,
    the gradient/shadow parsers built on splitTopLevel, normalizeCssNumber, and the cssValueValid
    dispatcher keyed by a contract's declared `sanitize` name). This is the client-side mirror of
    BlockContractValidator's server-side allowlist (see that class's own comment pointing back here).
    Also owns firstGradientStopColor() — the email-surface gradient degrade (a validated gradient's
    FIRST colour stop, since Outlook cannot render `linear-gradient()`/`radial-gradient()`), the
    client-side mirror of {@see CssValueSanitizer}'s own method of the same name; 04-render-support's
    styleDeclarations() is the only caller.

    Depends on: nothing block-model-specific; these are pure functions over strings/values, plus
    subst()/dataGet() which read a model's attributes/supports (readAttr from 02-doc-model).

    Defines for later sections: truthy(), dataGet(), subst(), DYN_TAGS, resolveTag(),
    isSafeColorToken(), isSafeLengthSignedValue(), isSafeLinearPreamble(), isSafeRadialPreamble(),
    isSafeGradientStop(), isSafeGradientValue(), splitTopLevel(), isSafeShadowLayer(),
    isSafeShadowValue(), normalizeCssNumber(), cssValueValid(), firstGradientStopColor().
--}}
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

    /**
     * The email degrade for an already-validated gradient (mirrors
     * CssValueSanitizer::firstGradientStopColor() exactly): Outlook cannot render a CSS gradient,
     * so the email surface substitutes the gradient's FIRST colour stop as a flat fallback rather
     * than a value the client will just ignore. Scans comma-parts for the first one whose leading
     * token is a safe colour — that skips the optional direction/shape preamble without
     * re-deriving which gradient kind produced it. Returns '' (never the original gradient) if,
     * somehow, no stop parses as a colour — callers only use the return value when it's non-empty.
     */
    function firstGradientStopColor(gradient) {
        const m = /^(?:linear|radial)-gradient\((.*)\)$/is.exec(gradient);
        if (!m) return '';
        const parts = splitTopLevel(m[1], ',');
        for (let i = 0; i < parts.length; i++) {
            const tokens = splitTopLevel(parts[i].trim(), ' ').filter((t) => t !== '');
            if (tokens.length && isSafeColorToken(tokens[0])) return tokens[0];
        }
        return '';
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
    // LOCKSTEP with CssValueSanitizer::isSafeFilterValue().
    function isSafeFilterValue(value) {
        if (value === 'none') return true;
        const fns = String(value).trim().split(/\s+/);
        if (!fns.length || fns.length > 12) return false;
        return fns.every((fn) => /^blur\(\d{1,3}(\.\d+)?px\)$/.test(fn));
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
        if (sanitizer === 'box-sizing') return ['border-box', 'content-box'].indexOf(value) >= 0;
        if (sanitizer === 'filter') return isSafeFilterValue(value);
        return /^[a-z0-9\s().,%_\/-]+$/i.test(value);
    }

