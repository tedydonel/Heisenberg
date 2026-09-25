{{--
    Section: the public window.hbEditor API.

    Owns: the single object assignment that is this whole IIFE's externally-visible surface —
    every other partial exists to define the functions/state this section wires together and
    exposes to the rest of the editor shell (topbar, inspector, AI tool-call handler,
    PostController's save flow via buildSavePayload()).

    Depends on: essentially every earlier partial — see each function's own definition site for
    specifics (findModel/indexOf/doc from 02-doc-model & 07-toolbar-and-selection; insertBlock/
    insertInto/setAttribute/setSupport/moveById/moveBlockTo/duplicateBlock/insertPattern/
    previewState/moveBlock/removeBlock from 06-selection-support & 08-tree-ops; getEditingLocale/
    getHomeLocale/getContentLocales/setEditingLocale/resolveAttrKey/readAttr from
    01-bootstrap-and-email-variables, 02-doc-model & 12-history-and-locale-switch; selectById/
    reRenderBlock from 07-toolbar-and-selection; replaceDoc/translationSegments/translateSegments/applyCanvasWrite from
    11-doc-replace-and-translation; undo/redo/canUndo/canRedo/history from
    12-history-and-locale-switch; DATA.registryHash from 01-bootstrap-and-email-variables).

    Defines: window.hbEditor (terminal — no later partial depends on anything defined here; this
    is the last section before the IIFE closes).
--}}
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
        // Translation as TEXT: hand out the source strings, write a translation into a named locale.
        translationSegments: translationSegments,
        translateSegments: translateSegments,
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
