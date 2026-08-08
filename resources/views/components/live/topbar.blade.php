{{-- live/topbar — 32px bar, bottom border, 3 zones (TL fixed / TC centered,
     grows / TR fixed). All icon buttons here are 28x28 (26x26 in the right cluster), cornerRadius 3,
     transparent by default — a header-specific treatment, deliberately NOT the same shape as ui/icon-button
     (which is 40x26 with an always-visible bg-muted fill), so it isn't force-reused here.
     Undo/redo are live: thin shells over window.hbEditor.undo()/redo(), enabled state from hb:history.
     Fullscreen, Layers and Preview (the eye button) ARE wired — see the script below. Width is
     100% here, not the source's 1440 artboard width.

     The Save button (.hb-topbar__save, bottom of the markup) IS wired — see the script below.
     postId/contentVersion come from EditorController::index() (null/0, blank document) or
     ::show() (an existing post) via props (declared below, after the style/script blocks —
     matching this file tree's own convention, e.g. live/media/media-dialog.blade.php), read
     back out of this component's own data-hb-post-id/data-hb-content-version attributes at
     boot so the save script never needs a second way to reach them. --}}
@once
<style>
    .hb-topbar {
        position: relative;
        display: flex;
        align-items: center;
        justify-content: space-between;
        width: 100%;
        height: 32px;
        background: var(--hb-bg, #fff);
        border-bottom: 1px solid var(--hb-border, #E4E4E4);
    }
    .hb-topbar__zone { display: flex; align-items: center; gap: 2px; height: 100%; }
    .hb-topbar__zone--left { padding: 0 10px; }
    /* Absolutely centered on the bar itself, not stretched via flex — a flex:1+justify-content:center
       zone spreads across the FULL remaining width between the left/right zones, which looks fine at
       the design's 1440px reference but leaves the cluster floating in a large empty gap on realistic,
       wider browser windows (confirmed at 1920px — this is what was actually being reported as "icons
       not in the middle"). Absolute-centering keeps it pinned to the true midpoint at its natural
       (compact) size regardless of viewport width or left/right zone width asymmetry. */
    .hb-topbar__zone--center {
        position: absolute;
        left: 50%;
        top: 50%;
        transform: translate(-50%, -50%);
    }
    .hb-topbar__zone--right { padding: 2px var(--hb-space-3, 12px); }
    .hb-topbar__btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 28px;
        height: 28px;
        border: 0;
        border-radius: var(--hb-radius-sm, 3px);
        background: transparent;
        color: var(--hb-text-muted, #9A9A9A);
        cursor: pointer;
    }
    .hb-topbar__btn:hover { background: var(--hb-surface-hover, #F7F7F7); color: var(--hb-text-secondary, #5A5A5A); }
    .hb-topbar__btn:focus-visible { outline: 2px solid var(--hb-border-focus, #000); outline-offset: -2px; }
    .hb-topbar__btn:disabled { opacity: .35; cursor: default; pointer-events: none; }
    .hb-topbar__btn--sm { width: 26px; height: 26px; }
    .hb-topbar__icon { display: inline-flex; width: 14px; height: 14px; }
    .hb-topbar__icon--sm { width: 13px; height: 13px; }
    .hb-topbar__save {
        display: inline-flex;
        align-items: center;
        height: 100%;
        padding: var(--hb-space-1, 4px) var(--hb-space-3, 12px);
        border: 0;
        border-radius: var(--hb-radius-sm, 3px);
        background: var(--hb-accent, #000);
        color: var(--hb-accent-fg, #fff);
        font-family: var(--hb-font-sans, Rubik, sans-serif);
        font-size: var(--hb-fs-sm, 12px);
        font-weight: 600;
        cursor: pointer;
    }
    .hb-topbar__save:hover { background: var(--hb-accent-hover, #1A1A1A); }
    .hb-topbar__save[aria-busy="true"] { opacity: .6; cursor: default; }
    /* device preview — a dropdown (Desktop / Tablet / Mobile). The trigger shows only the
       current device's icon; labels live in the menu. */
    .hb-topbar__devsel { position: relative; display: inline-flex; align-items: center; }
    .hb-topbar__device .hb-dev { display: none; }
    .hb-topbar__device[data-device="desktop"] .hb-dev--desktop,
    .hb-topbar__device[data-device="tablet"] .hb-dev--tablet,
    .hb-topbar__device[data-device="mobile"] .hb-dev--mobile { display: inline-flex; }
    .hb-topbar__device[data-device="tablet"], .hb-topbar__device[data-device="mobile"] { color: var(--hb-text-primary, #0A0A0A); }
    .hb-topbar__devsel-menu {
        position: absolute; top: calc(100% + 5px); right: 0; z-index: 60;
        width: max-content; padding: 4px;
        background: var(--hb-bg, #fff); border: 1px solid var(--hb-border, #E4E4E4);
        border-radius: var(--hb-radius-md, 5px); box-shadow: var(--hb-shadow-lg, 0 8px 28px rgba(0, 0, 0, .14));
        display: flex; flex-direction: column; gap: 2px;
    }
    .hb-topbar__devsel-menu[hidden] { display: none; }
    .hb-topbar__devsel-opt {
        display: inline-flex; align-items: center; gap: 8px; height: 28px; padding: 0 8px;
        border: 0; background: none; border-radius: var(--hb-radius-sm, 3px);
        font-family: var(--hb-font-sans, Rubik, sans-serif); font-size: var(--hb-fs-sm, 12px); font-weight: 400;
        color: var(--hb-text-secondary, #5A5A5A); text-align: left; cursor: pointer;
        white-space: nowrap;
    }
    .hb-topbar__devsel-opt > span { display: inline; }
    .hb-topbar__devsel-opt svg { width: 15px; height: 15px; flex: none; }
    .hb-topbar__devsel-opt:hover { background: var(--hb-surface-hover, #F7F7F7); color: var(--hb-text-primary, #0A0A0A); }
    .hb-topbar__devsel-opt.is-on { background: var(--hb-surface-hover, #F7F7F7); color: var(--hb-text-primary, #0A0A0A); font-weight: 500; }
</style>
<script>
    (() => {
        // Shared with the restore script in editor/layouts/app.blade.php — below 1024px (tablet +
        // mobile) only one of sidebar/panel/inspector may be open at a time; opening one force-closes
        // the other two. Desktop allows all three open together, unchanged from before.
        const HB_PANEL_KEYS = ['sidebar', 'panel', 'inspector'];
        const hbIsNarrow = () => window.matchMedia('(max-width: 1023px)').matches;
        window.hbSetPanelCollapsed = (shell, key, collapsed) => {
            shell.classList.toggle(`hb-editor--${key}-collapsed`, collapsed);
            localStorage.setItem(`hb-editor:${key}-collapsed`, collapsed ? 'true' : 'false');
        };

        // ── Save / autosave (routes/editor.php: POST/PUT /editor/posts[/{id}]) ──────────────
        // postId/contentVersion are read ONCE from this component's own data-hb-post-id /
        // data-hb-content-version attributes (hbSeed, called from boot() but guarded) and from
        // then on kept current only from a successful save response — never re-read off the DOM,
        // so a later boot() rerun (hb:refresh) can't rewind progress already made this session.
        let hbPostId = null;
        let hbContentVersion = 0;
        let hbSaveUrl = '';
        let hbUpdateUrlTemplate = '';
        let hbPreviewStoreUrl = '';
        let hbPreviewShowUrl = '';
        let hbPreviewPostUrlTemplate = '';
        let hbEditorUrlTemplate = '';
        // Localized save-failure messages — seeded from the component's data-hb-msg-*
        // attributes (the Blade side owns the __() calls; JS never hardcodes copy).
        let hbMsgConflict = '';
        let hbMsgInvalid = '';
        let hbMsgNetwork = '';
        let hbSeeded = false;
        let hbDirty = false;
        let hbConflicted = false;
        let hbSaveInFlight = null;
        let hbAutosaveTimer = null;
        const HB_AUTOSAVE_MS = 3000;

        const hbCsrfToken = () => {
            const meta = document.querySelector('meta[name="csrf-token"]');
            return meta ? meta.getAttribute('content') : '';
        };
        // Both data-hb-title elements (the canvas h1 and the inspector's Post-tab input) are
        // kept in sync with each other by live/canvas.blade.php's own script — reading whichever
        // one exists is enough.
        const hbReadTitle = () => {
            const el = document.querySelector('[data-hb-title]');
            if (!el) return '';
            return (el.tagName === 'INPUT' ? el.value : el.textContent).trim();
        };
        const hbEmitSaveState = (state, detail) => {
            document.dispatchEvent(new CustomEvent('hb:save-state', { detail: Object.assign({ state: state }, detail || {}) }));
        };
        const hbSetSaveBusy = (busy) => {
            document.querySelectorAll('.hb-topbar__save').forEach((btn) => {
                btn.disabled = busy;
                btn.setAttribute('aria-busy', busy ? 'true' : 'false');
            });
        };

        const hbSeed = () => {
            if (hbSeeded) return; hbSeeded = true;
            const root = document.querySelector('.hb-topbar');
            if (!root) return;
            hbPostId = root.dataset.hbPostId ? root.dataset.hbPostId : null;
            hbContentVersion = root.dataset.hbContentVersion ? (parseInt(root.dataset.hbContentVersion, 10) || 0) : 0;
            hbSaveUrl = root.dataset.hbSaveUrl || '';
            hbUpdateUrlTemplate = root.dataset.hbUpdateUrlTemplate || '';
            hbPreviewStoreUrl = root.dataset.hbPreviewStoreUrl || '';
            hbPreviewShowUrl = root.dataset.hbPreviewShowUrl || '';
            hbPreviewPostUrlTemplate = root.dataset.hbPreviewPostUrlTemplate || '';
            hbEditorUrlTemplate = root.dataset.hbEditorUrlTemplate || '';
            hbMsgConflict = root.dataset.hbMsgConflict || hbMsgConflict;
            hbMsgInvalid = root.dataset.hbMsgInvalid || hbMsgInvalid;
            hbMsgNetwork = root.dataset.hbMsgNetwork || hbMsgNetwork;
        };

        // A brand-new post was just created: point the browser at it. Without this the URL stays
        // /editor, so a refresh loads a fresh blank document and the save looks like it vanished.
        // replaceState (not pushState) — the blank /editor is not a state worth going Back to.
        const hbAdoptPostUrl = () => {
            if (hbPostId === null || !hbEditorUrlTemplate || !window.history || !window.history.replaceState) return;
            const target = hbEditorUrlTemplate.replace('__ID__', hbPostId);
            if (window.location.href === target) return;
            try { window.history.replaceState({ hbPostId: hbPostId }, '', target); } catch (e) { /* cross-origin/file:// — harmless */ }
        };

        // Autosave never CREATES a post — it starts only once a post id exists (after the first
        // explicit Save), so an abandoned keystroke session can't spawn a stray draft row.
        function hbScheduleAutosave() {
            if (hbConflicted || hbPostId === null) return;
            clearTimeout(hbAutosaveTimer);
            hbAutosaveTimer = setTimeout(() => hbPerformSave(false), HB_AUTOSAVE_MS);
        }

        function hbMarkDirty() {
            hbDirty = true;
            hbEmitSaveState(hbConflicted ? 'conflict' : 'dirty');
            hbScheduleAutosave();
        }

        // explicit === true: the user clicked Save (always attempted, creates on first call).
        // explicit === false: an autosave tick (only ever fires once hbPostId is already set).
        function hbPerformSave(explicit) {
            if (hbSaveInFlight) return; // manual and auto saves never overlap — see the report
            if (!navigator.onLine) return; // the footer's own online/offline listener covers display
            if (hbConflicted && !explicit) return; // don't keep re-sending a version the server already rejected
            if (!explicit && hbPostId === null) return;
            if (!window.hbEditor || typeof window.hbEditor.buildSavePayload !== 'function') return;

            clearTimeout(hbAutosaveTimer);
            hbAutosaveTimer = null;
            hbDirty = false; // optimistic — hbMarkDirty() flips this back on if more edits land mid-flight

            const extra = { title_en: hbReadTitle(), autosave: !explicit };
            if (hbPostId !== null) extra.content_version = hbContentVersion;
            const body = window.hbEditor.buildSavePayload(extra);
            const url = hbPostId === null ? hbSaveUrl : hbUpdateUrlTemplate.replace('__ID__', hbPostId);
            const method = hbPostId === null ? 'POST' : 'PUT';

            hbSetSaveBusy(true);
            hbEmitSaveState('saving');

            hbSaveInFlight = window.fetch(url, {
                method: method,
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': hbCsrfToken(),
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
                body: JSON.stringify(body),
            })
                .then((r) => r.json().catch(() => ({})).then((data) => ({ ok: r.ok, status: r.status, data: data })))
                .then((res) => {
                    if (res.ok) {
                        if (res.data && res.data.post) {
                            const wasNew = hbPostId === null;
                            if (res.data.post.id != null) hbPostId = res.data.post.id;
                            if (res.data.post.content_version != null) hbContentVersion = res.data.post.content_version;
                            // First save of a new document — move the browser onto /editor/{id}
                            // so a refresh reopens what was just saved instead of a blank doc.
                            if (wasNew && hbPostId !== null) {
                                hbAdoptPostUrl();
                                // The Post tab's category/tag controls (PostCategoryController/
                                // PostTagController) need a REAL, persisted post id — they render
                                // disabled until this fires, since a document with no id yet can't
                                // be the target of an attach/detach request.
                                document.dispatchEvent(new CustomEvent('hb:post-id', { detail: { id: hbPostId } }));
                            }
                        }
                        hbConflicted = false;
                        // The Post tab's Summary rows re-read status/publish/url from this —
                        // every 2xx save (manual or autosave) echoes the fresh post payload.
                        if (res.data && res.data.post) {
                            document.dispatchEvent(new CustomEvent('hb:post-saved', { detail: { post: res.data.post } }));
                        }
                        hbEmitSaveState(hbDirty ? 'dirty' : 'saved');
                        return;
                    }
                    hbDirty = true; // the attempted save did not happen — still unsaved
                    if (res.status === 409) {
                        hbConflicted = true;
                        hbEmitSaveState('conflict', { message: (res.data && res.data.message) || hbMsgConflict });
                        return;
                    }
                    if (res.status === 422) {
                        const errors = (res.data && res.data.errors) || {};
                        const messages = [];
                        Object.keys(errors).forEach((key) => { (errors[key] || []).forEach((m) => messages.push(m)); });
                        hbEmitSaveState('error', { message: messages.join(' ') || hbMsgInvalid, errors: errors });
                        return;
                    }
                    // Always carry the HTTP status: with APP_DEBUG off a 500 body is just
                    // "Server Error", and a 419 (expired CSRF/session) has no body at all — so
                    // without the code these are indistinguishable from a validation failure,
                    // both to the user and to anyone they report it to.
                    hbEmitSaveState('error', {
                        message: 'HTTP ' + res.status + ((res.data && res.data.message) ? ' — ' + res.data.message : ''),
                        status: res.status,
                    });
                })
                .catch(() => {
                    hbDirty = true;
                    hbEmitSaveState('error', { message: hbMsgNetwork });
                })
                .finally(() => {
                    hbSaveInFlight = null;
                    hbSetSaveBusy(false);
                    if (hbDirty) hbScheduleAutosave();
                });
        }

        const boot = () => {
            hbSeed();

            // Save button — always attempted (manual saves are never blocked by hbConflicted;
            // that guard only stops AUTOsave from re-hammering a version the server rejected).
            document.querySelectorAll('.hb-topbar__save').forEach((btn) => {
                if (btn.__hbSave) return; btn.__hbSave = true;
                btn.addEventListener('click', () => hbPerformSave(true));
            });

            // Undo / redo — thin shells over the runtime's history; enabled state follows
            // the hb:history events block-runtime dispatches on every commit/restore.
            document.querySelectorAll('[data-hb-undo]').forEach((btn) => {
                if (btn.__hbHist) return; btn.__hbHist = true;
                btn.addEventListener('click', () => window.hbEditor && window.hbEditor.undo());
            });
            document.querySelectorAll('[data-hb-redo]').forEach((btn) => {
                if (btn.__hbHist) return; btn.__hbHist = true;
                btn.addEventListener('click', () => window.hbEditor && window.hbEditor.redo());
            });
            if (!document.__hbHistoryButtons) {
                document.__hbHistoryButtons = true;
                document.addEventListener('hb:history', (event) => {
                    document.querySelectorAll('[data-hb-undo]').forEach((btn) => { btn.disabled = !event.detail.canUndo; });
                    document.querySelectorAll('[data-hb-redo]').forEach((btn) => { btn.disabled = !event.detail.canRedo; });
                });
            }

            // Autosave triggers — a block-tree change or a title edit both dirty the document.
            // Wired once (document-scoped), regardless of how many times boot() itself reruns.
            if (!document.__hbAutosaveWired) {
                document.__hbAutosaveWired = true;
                document.addEventListener('hb:blocks-changed', hbMarkDirty);
                document.addEventListener('hb:doc-title', hbMarkDirty);
                // Connectivity recovered mid-edit — pick a still-dirty, non-conflicted, already-
                // existing document back up immediately rather than waiting out the debounce.
                window.addEventListener('online', () => {
                    if (hbDirty && !hbConflicted && hbPostId !== null) hbPerformSave(false);
                });
            }

            document.querySelectorAll('[data-hb-toggle]').forEach((btn) => {
                if (btn.__hbToggle) return;
                btn.addEventListener('click', () => {
                    const shell = btn.closest('.hb-editor');
                    if (!shell) return;
                    const key = btn.dataset.hbToggle;
                    const opening = shell.classList.contains(`hb-editor--${key}-collapsed`);
                    window.hbSetPanelCollapsed(shell, key, !opening);
                    if (opening && hbIsNarrow()) {
                        HB_PANEL_KEYS.filter((k) => k !== key).forEach((other) => window.hbSetPanelCollapsed(shell, other, true));
                    }
                });
                btn.__hbToggle = true;
            });
            document.querySelectorAll('[data-hb-theme-toggle]').forEach((btn) => {
                if (btn.__hbThemeToggle) return;
                btn.addEventListener('click', () => {
                    const shell = btn.closest('.hb-editor');
                    if (!shell) return;
                    const dark = shell.classList.toggle('hb-editor--dark');
                    localStorage.setItem('hb-editor:theme', dark ? 'dark' : 'light');
                });
                btn.__hbThemeToggle = true;
            });
            // Fullscreen — real browser fullscreen; flag the shell for optional styling.
            document.querySelectorAll('[data-hb-fullscreen]').forEach((btn) => {
                if (btn.__hbFs) return; btn.__hbFs = true;
                btn.addEventListener('click', () => {
                    if (document.fullscreenElement) document.exitFullscreen();
                    else if (document.documentElement.requestFullscreen) document.documentElement.requestFullscreen();
                });
            });
            if (!document.__hbFsChange) {
                document.__hbFsChange = true;
                document.addEventListener('fullscreenchange', () => {
                    const on = !!document.fullscreenElement;
                    document.querySelectorAll('.hb-editor').forEach((sh) => sh.classList.toggle('hb-editor--fs', on));
                });
            }
            // Layers — open the Navigator (List View | Outline) in the left child panel.
            // Clicking it while the Navigator is already showing collapses the panel again.
            document.querySelectorAll('[data-hb-layers]').forEach((btn) => {
                if (btn.__hbLayers) return; btn.__hbLayers = true;
                btn.addEventListener('click', () => {
                    const shell = btn.closest('.hb-editor'); if (!shell) return;
                    const nav = document.querySelector('[data-hb-panel-nav]');
                    const navShowing = nav && !nav.hidden;
                    const collapsed = shell.classList.contains('hb-editor--panel-collapsed');
                    if (navShowing && !collapsed) {
                        window.hbSetPanelCollapsed(shell, 'panel', true);
                        return;
                    }
                    window.hbSetPanelCollapsed(shell, 'panel', false);
                    // Navigator is not a rail item — clear any active rail selection.
                    document.querySelectorAll('[data-hb-nav]').forEach((n) => {
                        n.classList.remove('hb-navitem--active');
                        n.setAttribute('aria-current', 'false');
                    });
                    if (window.hbEditorShowPanel) window.hbEditorShowPanel('nav', 0);
                    document.dispatchEvent(new CustomEvent('hb:nav-open'));
                });
            });
            // Preview — opens the current document in a new tab, rendered server-side through the
            // real BlockRenderer (routes/editor.php: GET /editor/preview or GET /editor/{id}/preview),
            // never a client-side re-render. The tab is opened synchronously, right here in the click
            // handler, before any fetch — window.open() after an await is reliably popup-blocked, but
            // pointing an already-open blank tab's .location at a URL once async work resolves is not.
            document.querySelectorAll('[data-hb-preview]').forEach((btn) => {
                if (btn.__hbPreview) return; btn.__hbPreview = true;
                btn.addEventListener('click', () => {
                    hbSeed();
                    const tab = window.open('about:blank', '_blank');
                    const openOrNavigate = (url) => { if (tab) tab.location = url; else window.open(url, '_blank'); };

                    // Already saved at least once — render straight from the DB's stored block
                    // tree (PreviewController::showPost), no session round-trip needed, so the
                    // tab can be pointed at it immediately.
                    if (hbPostId !== null) {
                        openOrNavigate(hbPreviewPostUrlTemplate.replace('__ID__', hbPostId));
                        return;
                    }

                    // Never saved yet — the same session-backed store/show round trip
                    // PreviewController already supports, just reached through the editor's
                    // OWN routes (not the deprecated builder's) — see routes/editor.php.
                    if (!window.hbEditor || typeof window.hbEditor.getDoc !== 'function') {
                        if (tab) tab.close();
                        return;
                    }
                    window.fetch(hbPreviewStoreUrl, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'application/json',
                            'X-CSRF-TOKEN': hbCsrfToken(),
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                        credentials: 'same-origin',
                        body: JSON.stringify({ title: hbReadTitle(), blocks: window.hbEditor.getDoc().blocks || [] }),
                    })
                        .then((r) => (r.ok ? r.json().catch(() => null) : null))
                        .then((res) => {
                            if (res && res.stored) openOrNavigate(hbPreviewShowUrl);
                            else if (tab) tab.close();
                        })
                        .catch(() => { if (tab) tab.close(); });
                });
            });
            // Device diff view — a dropdown select (Desktop / Tablet / Mobile), mirroring the
            // builder's devsel: the trigger toggles a listbox; picking an option constrains the
            // canvas page width and updates the trigger icon + selected option.
            const setDevice = (dev) => {
                if (!dev) return;
                document.querySelectorAll('.hb-canvas').forEach((cv) => {
                    cv.classList.toggle('hb-canvas--tablet', dev === 'tablet');
                    cv.classList.toggle('hb-canvas--mobile', dev === 'mobile');
                });
                document.querySelectorAll('[data-hb-device-toggle]').forEach((t) => { t.dataset.device = dev; });
                document.querySelectorAll('[data-hb-device-opt]').forEach((opt) => {
                    const on = opt.dataset.device === dev;
                    opt.classList.toggle('is-on', on);
                    opt.setAttribute('aria-selected', on ? 'true' : 'false');
                });
            };
            const setDeviceMenu = (open) => {
                document.querySelectorAll('.hb-topbar__devsel-menu').forEach((m) => { m.hidden = !open; });
                document.querySelectorAll('[data-hb-device-toggle]').forEach((t) => t.setAttribute('aria-expanded', open ? 'true' : 'false'));
            };
            document.querySelectorAll('[data-hb-device-toggle]').forEach((btn) => {
                if (btn.__hbDevT) return; btn.__hbDevT = true;
                btn.addEventListener('click', (e) => {
                    e.stopPropagation();
                    const menu = document.querySelector('.hb-topbar__devsel-menu');
                    setDeviceMenu(!menu || menu.hidden);
                });
            });
            document.querySelectorAll('[data-hb-device-opt]').forEach((opt) => {
                if (opt.__hbDevO) return; opt.__hbDevO = true;
                opt.addEventListener('click', () => { setDevice(opt.dataset.device); setDeviceMenu(false); });
            });
            if (!document.__hbDevOutside) {
                document.__hbDevOutside = true;
                document.addEventListener('click', (e) => { if (!e.target.closest('.hb-topbar__devsel')) setDeviceMenu(false); });
            }
        };
        if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot, { once: true });
        else boot();
        document.addEventListener('hb:refresh', boot);
    })();
</script>
@endonce

@props(['postId' => null, 'contentVersion' => 0])
@php
    $leftButtons = [
        ['icon' => 'house-fill', 'label' => __('heisenberg::editor.topbar.aria_home'), 'toggle' => null, 'tip' => 'aria_home'],
        null,
        ['icon' => 'list', 'label' => __('heisenberg::editor.topbar.aria_menu'), 'toggle' => 'sidebar', 'tip' => 'aria_menu'],
        ['icon' => 'sidebar-simple', 'label' => __('heisenberg::editor.topbar.aria_panel_left'), 'toggle' => 'panel', 'tip' => 'aria_panel_left'],
    ];
    $centerButtons = [
        ['icon' => 'arrow-counter-clockwise', 'label' => __('heisenberg::editor.topbar.aria_undo'), 'undo' => true, 'fullscreen' => false, 'layers' => false, 'preview' => false],
        ['icon' => 'arrow-clockwise', 'label' => __('heisenberg::editor.topbar.aria_redo'), 'redo' => true, 'fullscreen' => false, 'layers' => false, 'preview' => false],
        null,
        ['icon' => 'arrows-out', 'label' => __('heisenberg::editor.topbar.aria_fullscreen'), 'fullscreen' => true, 'layers' => false, 'preview' => false],
        ['icon' => 'stack', 'label' => __('heisenberg::editor.topbar.aria_layers'), 'fullscreen' => false, 'layers' => true, 'preview' => false],
    ];
    $rightButtons = [
        ['icon' => 'moon', 'label' => __('heisenberg::editor.topbar.aria_theme'), 'theme' => true],
        // Preview lives here now, not on a separate eye icon in the centre group: "open in a new
        ['icon' => 'arrow-square-out', 'label' => __('heisenberg::editor.topbar.aria_preview'), 'theme' => false, 'preview' => true],
        ['icon' => 'device-mobile', 'label' => __('heisenberg::editor.topbar.aria_device'), 'device' => true],
    ];
    $deviceLabels = [
        'desktop' => __('heisenberg::editor.topbar.device_desktop'),
        'tablet'  => __('heisenberg::editor.topbar.device_tablet'),
        'mobile'  => __('heisenberg::editor.topbar.device_mobile'),
    ];
    $devices = [
        'desktop' => ['desktop', $deviceLabels['desktop']],
        'tablet'  => ['device-tablet', $deviceLabels['tablet']],
        'mobile'  => ['device-mobile', $deviceLabels['mobile']],
    ];
@endphp
<div {{ $attributes->merge(['class' => 'hb-topbar']) }}
    data-hb-post-id="{{ $postId ?? '' }}"
    data-hb-content-version="{{ $contentVersion ?? 0 }}"
    data-hb-msg-conflict="{{ __('heisenberg::editor.topbar.save_conflict') }}"
    data-hb-msg-invalid="{{ __('heisenberg::editor.topbar.save_invalid') }}"
    data-hb-msg-network="{{ __('heisenberg::editor.topbar.save_network') }}"
    data-hb-save-url="{{ route('heisenberg.editor.posts.store') }}"
    data-hb-update-url-template="{{ route('heisenberg.editor.posts.store') }}/__ID__"
    data-hb-preview-store-url="{{ route('heisenberg.editor.preview.store') }}"
    data-hb-preview-show-url="{{ route('heisenberg.editor.preview') }}"
    data-hb-preview-post-url-template="{{ route('heisenberg.editor.index') }}/__ID__/preview"
    {{-- Where this document lives once it has an id. A save from the blank /editor creates the
         post but leaves the browser on /editor, so refreshing re-opened an empty editor and the
         work looked lost (it was in the DB the whole time). After a create we rewrite the URL to
         this, so refresh/bookmark/back all land on the saved post. --}}
    data-hb-editor-url-template="{{ route('heisenberg.editor.index') }}/__ID__"
>
    <div class="hb-topbar__zone hb-topbar__zone--left">
        @foreach ($leftButtons as $btn)
            @if (is_null($btn))
                <x-ui.divider orientation="vertical" style="width:1px;height:16px;" />
            @else
                <button
                    type="button"
                    class="hb-topbar__btn"
                    aria-label="{{ $btn['label'] }}"
                    @if ($btn['toggle'] ?? null) data-hb-toggle="{{ $btn['toggle'] }}" @endif
                >
                    <span class="hb-topbar__icon" aria-hidden="true">
                        @include('heisenberg::components.ui.icon', ['name' => $btn['icon'], 'size' => 14])
                    </span>
                </button>
            @endif
        @endforeach
    </div>

    <div class="hb-topbar__zone hb-topbar__zone--center">
        @foreach ($centerButtons as $btn)
            @if (is_null($btn))
                <x-ui.divider orientation="vertical" style="width:1px;height:16px;" />
            @else
                <button type="button" class="hb-topbar__btn" aria-label="{{ $btn['label'] }}"
                    @if ($btn['undo'] ?? false) data-hb-undo disabled @endif
                    @if ($btn['redo'] ?? false) data-hb-redo disabled @endif
                    @if ($btn['fullscreen'] ?? false) data-hb-fullscreen @endif
                    @if ($btn['layers'] ?? false) data-hb-layers @endif
                    @if ($btn['preview'] ?? false) data-hb-preview @endif
                >
                    <span class="hb-topbar__icon" aria-hidden="true">
                        @include('heisenberg::components.ui.icon', ['name' => $btn['icon'], 'size' => 14])
                    </span>
                </button>
            @endif
        @endforeach
    </div>

    <div class="hb-topbar__zone hb-topbar__zone--right">
        @foreach ($rightButtons as $btn)
            @if ($btn['device'] ?? false)
                <div class="hb-topbar__devsel">
                    <button type="button" class="hb-topbar__btn hb-topbar__btn--sm hb-topbar__device" data-hb-device-toggle data-device="desktop" aria-haspopup="listbox" aria-expanded="false" aria-label="{{ $btn['label'] }}">
                        @foreach ($devices as $dev => $meta)
                            <span class="hb-topbar__icon hb-topbar__icon--sm hb-dev hb-dev--{{ $dev }}" aria-hidden="true">@include('heisenberg::components.ui.icon', ['name' => $meta[0], 'size' => 13])</span>
                        @endforeach
                    </button>
                    <div class="hb-topbar__devsel-menu" role="listbox" hidden>
                        @foreach ($devices as $dev => $meta)
                            <button type="button" class="hb-topbar__devsel-opt @if ($dev === 'desktop') is-on @endif" role="option" aria-selected="{{ $dev === 'desktop' ? 'true' : 'false' }}" data-device="{{ $dev }}" data-hb-device-opt>
                                @include('heisenberg::components.ui.icon', ['name' => $meta[0], 'size' => 15])<span>{{ $meta[1] }}</span>
                            </button>
                        @endforeach
                    </div>
                </div>
            @else
                <button
                    type="button"
                    class="hb-topbar__btn hb-topbar__btn--sm"
                    aria-label="{{ $btn['label'] }}"
                    @if ($btn['theme'] ?? false) data-hb-theme-toggle @endif
                    @if ($btn['preview'] ?? false) data-hb-preview @endif
                >
                    <span class="hb-topbar__icon hb-topbar__icon--sm" aria-hidden="true">
                        @include('heisenberg::components.ui.icon', ['name' => $btn['icon'], 'size' => 13])
                    </span>
                </button>
            @endif
        @endforeach
        <x-ui.divider orientation="vertical" style="width:1px;height:14px;" />
        <button type="button" class="hb-topbar__save">{{ __('heisenberg::editor.common.save') }}</button>
        {{-- source rotates this icon instance -540deg (≡ 180deg) rather than mirroring it --}}
        <button type="button" class="hb-topbar__btn" aria-label="{{ __('heisenberg::editor.topbar.aria_panel_right') }}" data-hb-toggle="inspector">
            <span class="hb-topbar__icon" aria-hidden="true" style="transform:rotate(180deg);">
                @include('heisenberg::components.ui.icon', ['name' => 'sidebar-simple', 'size' => 14])
            </span>
        </button>
    </div>
</div>
