{{--
    Section: the block-tree DOM renderer.

    Owns: MAX_NESTING_DEPTH; renderNode() (turns one template node — text, rich-text, icon,
    text-lines, inner-blocks, or a plain tag — into a live DOM node/fragment for a given block
    model + contract, recursing into children and innerBlocks); renderBlockEl() (wraps a
    rendered root in the .hb-blk container, stamps data-block/data-block-name/data-level, and
    applies the image/icon empty-state decorations); the two empty-state decorators
    decorateIconBlock()/decorateImageBlock(); and MAX_EMAIL_COLUMNS/emailInnerBlocksFor(), the
    client-side mirror of EmailRenderer::capColumns()/assignColumnWidths().

    Both renderNode() and renderBlockEl() take a `surface` argument (`'render'`|`'email'`,
    defaulting to RENDER_SURFACE when a caller omits it — every existing call site in
    06-selection-support/07-toolbar-and-selection/08-tree-ops/11-doc-replace-and-translation still
    calls renderBlockEl(model[, depth]) unchanged) — the exact client-side mirror of
    {@see BlockTreeRenderer}'s own `$surface` parameter (see that class's docblock): `'render'`
    walks a contract's `render.template` (web, byte-for-byte what this file always produced);
    `'email'` walks `email.template` instead, skips the contract-level className/classNames/align
    injection (BlockTreeRenderer::resolveClass()'s own email carve-out), and degrades a gradient to
    its first colour stop (styleDeclarations(), 04-render-support). A contract with no section for
    the current surface renders EMPTY, not an error (renderBlockEl() returning null — embed/icon
    have no `email` section at all; docs/email-system.md §4) — exactly {@see EmailRenderer}'s own
    "skip, don't fail" posture.

    Depends on: subst()/resolveTag() (03-style-sanitizers); readAttr() (02-doc-model);
    styleDeclarations()/predicateMatches()/safeUrl()/injectLibraryIcon()/embedSrcFor()/
    embedFileSrcFor()/alignmentValuesFor() (04-render-support); decorateEmailVariables()
    (01-bootstrap-and-email-variables) for rich-text nodes on the email surface; RENDER_SURFACE
    (01-bootstrap-and-email-variables) as renderNode()/renderBlockEl()'s default `surface`.

    Defines for later sections: MAX_NESTING_DEPTH, MAX_EMAIL_COLUMNS, emailInnerBlocksFor(),
    renderNode(), renderBlockEl(), decorateIconBlock(), decorateImageBlock() — renderBlockEl() in
    particular is the workhorse every tree mutation in 08-tree-ops calls to redraw a block after a
    model change.
--}}
    const MAX_NESTING_DEPTH = 20;

    // EmailRenderer::MAX_EMAIL_COLUMNS — the email surface never ships more than this many
    // columns from a `columns` block (docs/email-system.md §4).
    const MAX_EMAIL_COLUMNS = 3;

    /**
     * The email-surface mirror of EmailRenderer::capColumns()/assignColumnWidths(): a `columns`
     * block's innerBlocks, capped at MAX_EMAIL_COLUMNS and stamped with the SAME synthetic
     * `_emailColWidthPercent` attribute column.json's `email.template` substitutes into its root
     * `width` — whole-percent widths summing to 100, the last column absorbing the rounding
     * remainder. This is a container-specific transform (the generic inner-blocks primitive has
     * no "first N children" concept — same reason EmailRenderer does it as a tree walk rather than
     * something a contract's `email.template` could express on its own), so it is applied here,
     * not inside the generic 'inner-blocks' case below, and ONLY when reading a `columns` block's
     * children for the email surface.
     *
     * Returns freshly-cloned column MODELS — never mutates model.innerBlocks or any child's own
     * `attributes` object — so every editing entry point (findModel()/locateBlock(), which every
     * one of setAttribute()/moveBlock()/removeBlock()/drag-drop keys off a block's real id) still
     * sees the actual document; only what gets DRAWN is affected. One consequence: a `columns`
     * block with more than MAX_EMAIL_COLUMNS children on an email document renders (and is
     * selectable/draggable) for its first 3 columns only on the canvas — matching what the real
     * send ships — while the extra columns still exist in the document and reappear if the
     * document's column count is ever reduced back through the inspector's Columns control.
     */
    function emailInnerBlocksFor(model) {
        const inner = Array.isArray(model.innerBlocks) ? model.innerBlocks : [];
        if (model.name.indexOf('/columns') === -1) return inner;
        const capped = inner.slice(0, MAX_EMAIL_COLUMNS);
        const count = capped.length;
        if (!count) return capped;
        const base = Math.floor(100 / count);
        return capped.map(function (column, i) {
            const percent = (i === count - 1 ? (100 - base * (count - 1)) : base) + '%';
            return Object.assign({}, column, {
                attributes: Object.assign({}, column.attributes || {}, { _emailColWidthPercent: percent }),
            });
        });
    }

    function renderNode(node, model, contract, isRoot, depth, surface) {
        depth = depth || 0;
        surface = surface || RENDER_SURFACE;
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
            if (surface === 'email') decorateEmailVariables(span);
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
            const inner = surface === 'email' ? emailInnerBlocksFor(model) : (Array.isArray(model.innerBlocks) ? model.innerBlocks : []);
            for (let i = 0; i < inner.length; i++) {
                if (depth >= MAX_NESTING_DEPTH) break;
                const el = renderBlockEl(inner[i], depth + 1, surface);
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

        // Conditionally-unwrapped element — mirrors BlockTreeRenderer::renderNode()'s
        // `omitTagWhenAttributeEmpty` handling exactly: `{ "omitTagWhenAttributeEmpty": "href",
        // "tag": "a", ... }` renders children with NO wrapping element at all when that attribute
        // resolves empty (e.g. the email image template's `<a>` around an `<img>` — an anchor with
        // no `href` is dead markup). A generic node feature, not email-specific, so no surface
        // gate here either — it just happens only the email templates author it today.
        const unwrapAttribute = node.omitTagWhenAttributeEmpty;
        if (typeof unwrapAttribute === 'string' && unwrapAttribute !== '') {
            const unwrapVal = readAttr(model, unwrapAttribute);
            if (String(unwrapVal == null ? '' : unwrapVal).trim() === '') {
                const frag = document.createDocumentFragment();
                const kids = node.children || [];
                for (let i = 0; i < kids.length; i++) { const ch = renderNode(kids[i], model, contract, false, depth, surface); if (ch) frag.appendChild(ch); }
                return frag;
            }
        }

        const el = document.createElement(resolveTag(node.tag || 'div', model, contract));
        if (node.class) { const c = subst(node.class, model); if (c) el.className = c; }
        if (isRoot && contract && contract.style) {

            // BlockTreeRenderer::resolveClass(): `surface === 'email'` skips the contract-level
            // className/classNames/align injection entirely — `hb-supports`, `hb-ease-*`,
            // `hb-flex-layout`, `hb-align-*` all name web-only CSS (interaction states, animation,
            // flexbox) with no counterpart in an inbox. Email root classes are exactly what the
            // `email.template` node itself authors (e.g. `hb-email-col`) via the plain `node.class`
            // handling just above — nothing auto-appended. Web (`render`) is unchanged.
            if (surface !== 'email') {
                String(contract.style.className || '').split(/\s+/).forEach(function (t) { if (t) el.classList.add(t); });
                const conditional = contract.style.classNames || [];
                for (let ci = 0; ci < conditional.length; ci++) {
                    if (conditional[ci] && predicateMatches(conditional[ci].when, model, contract)) el.classList.add(conditional[ci].class);
                }
                const alignment = model.supports && model.supports.align;
                if (alignmentValuesFor(model.name).indexOf(alignment) >= 0) el.classList.add('hb-align-' + alignment);
            }
            // The root inline style IS still materialized on email (BlockStyleCompiler::
            // blockStyleDeclarations() runs for both surfaces — "harmless here", that method's own
            // docblock) — only the gradient-degrade rule inside it differs per surface.
            const declarations = styleDeclarations(model, contract, surface);
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

                // Enum-mapped attribute: an `enumMap` token substitution (e.g. the heading email
                // template's `attributes.level`), plus `cases` ("1": "…", …) and a `default` —
                // mirrors BlockTreeRenderer::resolveAttributes()'s `enumMap`
                // case exactly (the email heading template's per-level literal px sizing, since
                // email clients can't be trusted with clamp()/a tag-selector cascade). The WHOLE
                // value is chosen by matching enumMap's resolved token against `cases`, falling
                // back to `default`.
                if ('enumMap' in raw) {
                    const key = subst(raw.enumMap, model);
                    const cases = raw.cases && typeof raw.cases === 'object' ? raw.cases : {};
                    const chosen = typeof cases[key] === 'string' ? cases[key] : String(raw.default == null ? '' : raw.default);
                    let value = subst(chosen, model);
                    if (an === 'src' || an === 'href' || an === 'srcset' || an === 'poster') {
                        value = safeUrl(value);
                        if (!value && (an === 'src' || an === 'srcset')) continue;
                    }
                    el.setAttribute(an, value);
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
        for (let i = 0; i < kids.length; i++) { const ch = renderNode(kids[i], model, contract, false, depth, surface); if (ch) el.appendChild(ch); }
        return el;
    }

    function renderBlockEl(model, depth, surface) {
        surface = surface || RENDER_SURFACE;
        const c = REGISTRY[model.name];
        if (!c) return null;
        // BlockTreeRenderer::renderJsonBlock()'s `$contract[$surface]['template'] ?? null`: a
        // block whose contract has no section for THIS surface renders EMPTY, not an error —
        // embed/icon have no `email` section at all (docs/email-system.md §4).
        const template = surface === 'email' ? c.emailTemplate : c.template;
        if (!template) return null;
        const root = renderNode(template, model, c, true, depth || 0, surface);
        if (!root) return null;
        if (surface === 'email') hoistEmailSpacer(root);

        // A <div> is not valid inside a table row, and the email surface's `column` block roots
        // at a <td>. Wrapping that in the usual <div class="hb-blk"> produced <tr><div><td>,
        // which the browser lays out as a ZERO-SIZE box — so the block could be selected but
        // dockToolbar() had a 0x0 rect to aim at and parked the floating toolbar at the top of
        // the viewport, i.e. the toolbar "didn't appear" for anything inside a layout in an
        // email. For table-structural roots, carry the block identity ON the root itself
        // instead of wrapping it; every selector the editor uses keys off .hb-blk[data-block],
        // so selection, drag and the inspector are unaffected.
        const TABLE_ROOT_TAGS = ['TD', 'TH', 'TR', 'TBODY', 'THEAD', 'TFOOT'];
        if (surface === 'email' && TABLE_ROOT_TAGS.indexOf(root.tagName) !== -1) {
            root.classList.add('hb-blk');
            if ((depth || 0) > 0) root.classList.add('hb-blk--nested');
            root.setAttribute('data-block', model.id);
            root.setAttribute('data-block-name', model.name);
            decorateImageBlock(root, model);
            decorateIconBlock(root, model);
            return root;
        }

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


    /**
     * CANVAS ONLY: move an email block's trailing spacer from padding INSIDE the block to
     * margin OUTSIDE it.
     *
     * The email templates express vertical rhythm as bottom padding on their outermost <td>
     * (16px on paragraph/list/image/button, 12px on heading, 24px on separator, none on group).
     * That is correct for what we SEND — margins are unreliable across mail clients — but the
     * hover/selection outline is drawn on the block's rendered root, so that padding sits
     * inside the outlined box and every email block grew a dead band under it the moment you
     * hovered or selected it.
     *
     * Padding-in becomes margin-out: the gap between blocks is unchanged, the outline hugs the
     * content. Nothing here touches what is exported or previewed — EmailRenderer walks the
     * contract in PHP and never runs this, so the sent MIME is byte-for-byte what it was.
     *
     * The value is READ off the node rather than hardcoded, which keeps all four spacings right
     * and stays right if a contract's padding is ever edited.
     */
    function hoistEmailSpacer(root) {
        if (!root || root.tagName !== 'TABLE') return;

        // Walk the template's own `table > tr > td` explicitly instead of querySelector('td').
        // `columns` roots at `table > tr > [inner-blocks]` and its child `column` blocks each
        // render a <td> of their own, so the first <td> in tree order can belong to a DIFFERENT
        // block — that query would zero a column's padding and hang the margin on the wrong
        // element. No tbody hop is normally needed (these nodes are built with createElement,
        // and only the HTML PARSER injects tbody) but it is cheap to tolerate one.
        let row = root.firstElementChild;
        if (row && (row.tagName === 'TBODY' || row.tagName === 'THEAD')) row = row.firstElementChild;
        if (!row || row.tagName !== 'TR') return;

        const cell = row.firstElementChild;
        if (!cell || cell.tagName !== 'TD') return;
        // A cell that is itself a block is a `column`, not this block's spacer (see above).
        if (cell.classList.contains('hb-blk')) return;

        const spacer = cell.style.paddingBottom;
        if (!spacer || parseFloat(spacer) === 0) return;

        cell.style.paddingBottom = '0px';
        // On the root, not the .hb-blk wrapper: a NESTED block's wrapper is `display: contents`
        // and generates no box, so a margin there would be dropped and the rhythm lost inside
        // every column. The root <table> has a box on both paths, and margin falls outside the
        // box-shadow either way.
        root.style.marginBottom = spacer;
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

