{{--
    Section: the block-tree DOM renderer.

    Owns: MAX_NESTING_DEPTH; renderNode() (turns one template node — text, rich-text, icon,
    text-lines, inner-blocks, or a plain tag — into a live DOM node/fragment for a given block
    model + contract, recursing into children and innerBlocks); renderBlockEl() (wraps a
    rendered root in the .hb-blk container, stamps data-block/data-block-name/data-level, and
    applies the image/icon empty-state decorations); and the two empty-state decorators
    decorateIconBlock()/decorateImageBlock().

    Depends on: subst()/resolveTag() (03-style-sanitizers); readAttr() (02-doc-model);
    styleDeclarations()/predicateMatches()/safeUrl()/injectLibraryIcon()/embedSrcFor()/
    embedFileSrcFor()/alignmentValuesFor() (04-render-support); decorateEmailVariables()
    (01-bootstrap-and-email-variables) for rich-text nodes on email documents.

    Defines for later sections: MAX_NESTING_DEPTH, renderNode(), renderBlockEl(),
    decorateIconBlock(), decorateImageBlock() — renderBlockEl() in particular is the workhorse
    every tree mutation in 08-tree-ops calls to redraw a block after a model change.
--}}
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
            if (document.querySelector('[data-hb-canvas][data-hb-document-type="email"]')) decorateEmailVariables(span);
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

