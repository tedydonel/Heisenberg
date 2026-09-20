{{--
    Section: bootstrap & email variables.

    Owns: the DATA/REGISTRY snapshot read from window.__hbEditor (populated by the first
    <script> block in the parent block-runtime.blade.php); the EMAIL_VARIABLES lookup and its
    token helpers (emailVariableToken, decorateEmailVariables, serializedEmailValue) used to
    render/round-trip "{{ variable }}" chips inside rich-text fields on email documents; and the
    editing-locale bootstrap (homeLocale, CONTENT_LOCALES, editingLocale, currentPostId,
    localeStorageKey/persistEditingLocale, the initEditingLocale IIFE that restores the last
    locale from localStorage, and the hb:post-id listener that keeps currentPostId — and so the
    localStorage key — in sync once a draft is first saved).

    Depends on: nothing from earlier partials (this is the first one); only the outer IIFE
    closure and window.__hbEditor set by the surrounding block-runtime.blade.php.

    Defines for later sections: DATA, REGISTRY, EMAIL_VARIABLES, emailVariableToken(),
    decorateEmailVariables(), serializedEmailValue(), homeLocale, CONTENT_LOCALES,
    editingLocale (let, reassigned by setEditingLocale() in 12-history-and-locale-switch),
    currentPostId, localeStorageKey(), persistEditingLocale().
--}}
    const DATA = window.__hbEditor || {};
    const REGISTRY = DATA.registry || {};
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


