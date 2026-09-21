{{--
    Section: bootstrap & email variables.

    Owns: the DATA/REGISTRY snapshot read from window.__hbEditor (populated by the first
    <script> block in the parent block-runtime.blade.php); DOCUMENT_TYPE/RENDER_SURFACE — the
    canvas's own read of the SAME `data-hb-document-type` the server stamped onto
    resources/views/components/live/canvas.blade.php's root, so the render tree walked below
    (05-render-tree) picks the identical `render`/`email` surface {@see BlockTreeRenderer}/
    {@see EmailRenderer} would use for THIS document (docs/email-system.md §4); the
    EMAIL_VARIABLES lookup and its token helpers (emailVariableToken, decorateEmailVariables,
    serializedEmailValue) used to render/round-trip "{{ variable }}" chips inside rich-text fields
    on email documents; and the editing-locale bootstrap (homeLocale, CONTENT_LOCALES,
    editingLocale, currentPostId, localeStorageKey/persistEditingLocale, the initEditingLocale IIFE
    that restores the last locale from localStorage, and the hb:post-id listener that keeps
    currentPostId — and so the localStorage key — in sync once a draft is first saved).

    Depends on: nothing from earlier partials (this is the first one); only the outer IIFE
    closure, window.__hbEditor, and the canvas's own `[data-hb-canvas]` root (already in the DOM —
    resources/views/editor/index.blade.php renders <x-heisenberg::live.canvas> before
    <x-heisenberg::live.block-runtime> — by the time this synchronous inline <script> runs) set by
    the surrounding block-runtime.blade.php.

    Defines for later sections: DATA, REGISTRY, DOCUMENT_TYPE, RENDER_SURFACE, EMAIL_VARIABLES,
    emailVariableToken(), decorateEmailVariables(), serializedEmailValue(), homeLocale,
    CONTENT_LOCALES, editingLocale (let, reassigned by setEditingLocale() in
    12-history-and-locale-switch), currentPostId, localeStorageKey(), persistEditingLocale().
--}}
    const DATA = window.__hbEditor || {};
    const REGISTRY = DATA.registry || {};

    // A document never changes type (docs/email-system.md §3), so this is read once here rather
    // than re-queried on every render call.
    const DOCUMENT_TYPE = (function () {
        const canvas = document.querySelector('[data-hb-canvas]');
        return (canvas && canvas.dataset.hbDocumentType) || 'post';
    })();
    // ONE canvas. An email document is drawn by exactly the same path as a post — the same
    // `render.template`, the same block CSS, the same DOM — so selection, outlines, the toolbar,
    // drag-and-drop and every inspector control behave identically in both editors. What makes
    // a document an email is its PALETTE (contracts with no `email` section are not offered) and
    // its EXPORT (EmailRenderer walks `email.template` in PHP) — never a second renderer here.
    //
    // It used to be `'email'` for email documents, which made this file a second, table-based
    // renderer: a different DOM under the same editor chrome, and the origin of every
    // email-only canvas defect (missing outlines, a zero-size toolbar anchor, dead inspector
    // controls, drops landing in the wrong slot). renderNode()/renderBlockEl() still accept a
    // `surface` argument; nothing passes `'email'` any more.
    const RENDER_SURFACE = 'render';

    const EMAIL_VARIABLES = {};
    (Array.isArray(DATA.emailVariables) ? DATA.emailVariables : []).forEach((entry) => {
        if (entry && entry.key) EMAIL_VARIABLES[String(entry.key)] = entry;
    });

    function emailVariableToken(key) { return EMAIL_VARIABLES[key] || null; }
    function decorateEmailVariables(root) {
        if (!root || !Object.keys(EMAIL_VARIABLES).length) return;
        const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT);
        const nodes = [];
        let node;
        while ((node = walker.nextNode())) {
            if (!node.parentElement || !node.parentElement.closest('.hb-email-variable-token')) {
                nodes.push(node);
            }
        }
        nodes.forEach((textNode) => {
            const value = textNode.nodeValue || '';
            const re = /\{\{\s*([a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*)*)\s*\}\}/g;
            let match; let last = 0; const fragment = document.createDocumentFragment(); let found = false;
            while ((match = re.exec(value))) {
                const definition = emailVariableToken(match[1]);
                if (!definition) continue;
                found = true;
                if (match.index > last) fragment.appendChild(document.createTextNode(value.slice(last, match.index)));
                const chip = document.createElement('span');
                chip.className = 'hb-email-variable-token';
                chip.contentEditable = 'false';
                chip.dataset.hbEmailVariable = match[1];
                chip.title = definition.description || match[1];
                chip.textContent = definition.label || match[1];
                fragment.appendChild(chip);
                last = match.index + match[0].length;
            }
            if (!found) return;
            if (last < value.length) {
                fragment.appendChild(document.createTextNode(value.slice(last)));
            } else {
                fragment.appendChild(document.createTextNode('\u200B'));
            }
            textNode.parentNode.replaceChild(fragment, textNode);
        });
    }
    function serializedEmailValue(element) {
        const clone = element.cloneNode(true);
        clone.querySelectorAll('[data-hb-email-variable]').forEach((chip) => {
            const text = document.createTextNode('{' + '{ ' + chip.getAttribute('data-hb-email-variable') + ' }' + '}');
            chip.replaceWith(text);
        });
        return clone.innerHTML.replace(/\u200B/g, '');
    }

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


