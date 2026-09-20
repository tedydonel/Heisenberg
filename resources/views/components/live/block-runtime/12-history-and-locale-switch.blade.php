{{--
    Section: undo/redo history and the editing-locale switch.

    Owns: the undo/redo stack (HISTORY_CAP, the `history` state object, historyEmit/historyCommit/
    historySchedule/historyFlush/historyReset/historyRestore, undo()/redo()) — a debounced
    JSON-snapshot stack of doc.blocks capped at HISTORY_CAP entries, plus the document-level
    Ctrl/Cmd+Z / Ctrl/Cmd+Shift+Z / Ctrl/Cmd+Y keyboard shortcut wiring; and setEditingLocale()
    (switches the active editing locale, re-renders every top-level block so translatable fields
    show the newly-selected locale's value, and re-emits the current selection + an
    hb:editing-locale-change event).

    Depends on: doc (02-doc-model); renderDoc() (11-doc-replace-and-translation, via
    historyRestore()); CONTENT_LOCALES/persistEditingLocale/editingLocale (01-bootstrap-and-email-
    variables — setEditingLocale() reassigns the `editingLocale` binding declared there);
    reRenderBlock() (07-toolbar-and-selection); selected/findModel() (02-doc-model).

    Defines for later sections: historyReset() (called by 11-doc-replace-and-translation's
    replaceDoc()), undo(), redo(), the two canUndo/canRedo predicates read directly off `history`
    in 13-api, and setEditingLocale() — all four surfaced on window.hbEditor in 13-api.
--}}
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

