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
        border-radius: var(--hb-radius-md, 5px); box-shadow: var(--hb-shadow-lg, 3px 4px 4px rgba(0, 0, 0, .1));
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
    /* Email export (docs/email-system.md §6) — devsel-menu shape, own wrapper class only. */
    .hb-topbar__exportsel { position: relative; display: inline-flex; align-items: center; }
    /* Editing-locale dropdown (docs/content-translation.md §0/Wave 2) — a visual sibling of the
       device dropdown above, same trigger/menu shape, placed just to its left. Unlike the device
       trigger (icon-only; the icon itself swaps per selection) this one has no per-locale icon,
       so the trigger grows to fit the CURRENT editing locale's name next to a static translate
       glyph — the one deliberate width exception in this otherwise fixed-size icon-button row.
       Picking a locale here does not navigate anywhere — it switches which locale's text every
       translatable attribute reads/writes (window.hbEditor.setEditingLocale), in the SAME
       document. */
    .hb-topbar__langsel { position: relative; display: inline-flex; align-items: center; }
    .hb-topbar__lang { width: auto; padding: 0 6px; gap: 5px; }
    .hb-topbar__lang-label {
        font-family: var(--hb-font-sans, Rubik, sans-serif); font-size: var(--hb-fs-sm, 12px);
        font-weight: 500; white-space: nowrap; max-width: 90px; overflow: hidden; text-overflow: ellipsis;
    }
    .hb-topbar__langsel-menu {
        position: absolute; top: calc(100% + 5px); right: 0; z-index: 60;
        width: max-content; min-width: 180px; padding: 4px;
        background: var(--hb-bg, #fff); border: 1px solid var(--hb-border, #E4E4E4);
        border-radius: var(--hb-radius-md, 5px); box-shadow: var(--hb-shadow-lg, 3px 4px 4px rgba(0, 0, 0, .1));
        display: flex; flex-direction: column; gap: 2px;
    }
    .hb-topbar__langsel-menu[hidden] { display: none; }
    .hb-topbar__langsel-opt {
        display: flex; align-items: center; justify-content: space-between; gap: 10px;
        min-height: 28px; padding: 4px 8px; width: 100%; cursor: pointer;
        border: 0; background: none; border-radius: var(--hb-radius-sm, 3px);
        font-family: var(--hb-font-sans, Rubik, sans-serif); font-size: var(--hb-fs-sm, 12px); font-weight: 400;
        color: var(--hb-text-secondary, #5A5A5A); text-align: left; white-space: nowrap;
    }
    .hb-topbar__langsel-opt:hover { background: var(--hb-surface-hover, #F7F7F7); color: var(--hb-text-primary, #0A0A0A); }
    .hb-topbar__langsel-opt.is-on { color: var(--hb-text-primary, #0A0A0A); font-weight: 500; }
    .hb-topbar__langsel-opt__check { width: 12px; height: 12px; flex: none; color: var(--hb-accent, #000); display: inline-flex; visibility: hidden; }
    .hb-topbar__langsel-opt.is-on .hb-topbar__langsel-opt__check { visibility: visible; }
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
        // docs/email-system.md §7-E3 — 'email' or 'post', read once like everything else in
        // hbSeed(). A document never changes type, so this never needs to be re-read.
        let hbDocumentType = 'post';
        let hbEmailPreviewUrlTemplate = '';
        let hbEmailExportUrlTemplate = '';
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
        // A status/schedule queued by the Summary's status control (inspector.blade.php,
        // hb:post-status-change) — rides the NEXT EXPLICIT save only (autosave never
        // transitions, matching PostController's own design), then clears itself. Kept
        // separate from hbDirty (which autosave optimistically zeroes every ~3s) so a
        // pending transition can't silently read as "Saved" before an explicit Save applies it.
        let hbPendingStatus = null;
        let hbPendingScheduledAt = null;
        // A slug/published_at edit queued by the Summary's URL/Publish rows
        // (inspector.blade.php, hb:post-slug-change / hb:post-published-at-change) —
        // same "next explicit save only, tracked apart from hbDirty" posture as
        // hbPendingStatus above. `null` means no pending edit; an empty string IS a
        // real pending value (slug '' asks the server to regenerate from the title;
        // published_at '' asks it to clear the date), so both are compared against
        // `null` specifically, never a falsy check.
        let hbPendingSlug = null;
        let hbPendingPublishedAt = null;
        // The SEO/Social panel's queued field edits (panel-seo-social.blade.php,
        // hb:post-seo-change, docs/seo-system.md §3) — same "next explicit save only, tracked
        // apart from hbDirty" posture as the three above. Unlike them, this is an OBJECT of
        // only-the-keys-the-user-actually-touched (never the full 10-field shape) rather than a
        // single scalar; `null` means nothing queued, same convention.
        let hbPendingSeo = null;
        // docs/content-translation.md §0/Wave 2 — title has no bare/unsuffixed column (only
        // title_en/title_fr), so switching the editing locale swaps the visible text rather than
        // reading/writing a suffix variant of one shared field. This cache remembers whatever the
        // user typed for EACH locale during the session (seeded from the server's two columns) so
        // switching away and back never drops an unsaved edit, and every configured locale's
        // current value rides the next save — not only the one on screen when Save was clicked.
        let hbTitleByLocale = {};
        let hbLocaleLabels = {};

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
        // The title_<locale> keys a save carries (docs/content-translation.md §0/Wave 2) — see
        // hbTitleByLocale's own docblock. On a brand-new document title_en rides EVERY create
        // regardless of the editing locale: the column is NOT NULL with no DB default (a
        // pre-existing schema quirk, title_fr is nullable), so it always needs a value — real
        // text when editing in en, otherwise an empty string the server substitutes "Untitled
        // post" for (PostController::contentAttributes()). An update sends the active locale's
        // latest text plus any OTHER locale this session actually touched (leaving an
        // never-touched column alone rather than churning a null into an empty string).
        const hbTitleSaveExtra = () => {
            const locale = (window.hbEditor && window.hbEditor.getEditingLocale) ? window.hbEditor.getEditingLocale() : 'en';
            hbTitleByLocale[locale] = hbReadTitle();
            const extra = {};
            if (hbPostId === null) {
                extra.title_en = locale === 'en' ? (hbTitleByLocale.en || '') : '';
                if (locale !== 'en') extra['title_' + locale] = hbTitleByLocale[locale] || '';
                return extra;
            }
            Object.keys(hbTitleByLocale).forEach((loc) => {
                if (loc === locale || (hbTitleByLocale[loc] || '') !== '') extra['title_' + loc] = hbTitleByLocale[loc] || '';
            });
            return extra;
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
            hbDocumentType = root.dataset.hbDocumentType || 'post';
            hbEmailPreviewUrlTemplate = root.dataset.hbEmailPreviewUrlTemplate || '';
            hbEmailExportUrlTemplate = root.dataset.hbEmailExportUrlTemplate || '';
            hbMsgConflict = root.dataset.hbMsgConflict || hbMsgConflict;
            hbMsgInvalid = root.dataset.hbMsgInvalid || hbMsgInvalid;
            hbMsgNetwork = root.dataset.hbMsgNetwork || hbMsgNetwork;
            try { hbTitleByLocale = JSON.parse(root.dataset.hbTitleByLocale || '{}') || {}; } catch (e) { hbTitleByLocale = {}; }
            try { hbLocaleLabels = JSON.parse(root.dataset.hbLocaleLabels || '{}') || {}; } catch (e) { hbLocaleLabels = {}; }
        };

        // Swaps the visible title to `locale`'s own cached text (falling back to the home
        // locale's, matching LocalizedAttributes::read()'s posture for block attributes), relabels
        // the dropdown trigger, and marks the active option — the one place every "which locale am
        // I editing" surface in the topbar updates from. Dispatching a synthetic `input` on the
        // title element reuses live/canvas.blade.php's own sync/doc-title/empty-state logic rather
        // than duplicating it here.
        const hbApplyEditingLocale = (locale, homeLocale) => {
            hbSeed();
            const val = hbTitleByLocale[locale] || hbTitleByLocale[homeLocale] || '';
            const el = document.querySelector('[data-hb-title]');
            if (el) {
                if (el.tagName === 'INPUT') el.value = val; else el.textContent = val;
                el.dispatchEvent(new Event('input', { bubbles: true }));
            }
            document.querySelectorAll('[data-hb-lang-current-label]').forEach((l) => { l.textContent = hbLocaleLabels[locale] || locale; });
            document.querySelectorAll('[data-hb-lang-option]').forEach((opt) => {
                const on = opt.dataset.locale === locale;
                opt.classList.toggle('is-on', on);
                opt.setAttribute('aria-selected', on ? 'true' : 'false');
            });
        };
        // Keeps the cache current with every keystroke, whichever locale is active right now —
        // canvas.blade.php fires this on every title input (both data-hb-title mirrors sync to
        // each other first, so one event per real edit).
        document.addEventListener('hb:doc-title', (event) => {
            const locale = (window.hbEditor && window.hbEditor.getEditingLocale) ? window.hbEditor.getEditingLocale() : 'en';
            hbTitleByLocale[locale] = (event.detail && event.detail.title) || '';
        });
        document.addEventListener('hb:editing-locale-change', (event) => {
            hbApplyEditingLocale(event.detail.locale, event.detail.homeLocale);
        });

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

        // `status: null` clears a pending choice (the user picked back the already-committed
        // status) without touching hbDirty — there is nothing new to save if that was the ONLY
        // outstanding change. No autosave scheduling here: autosave never carries a transition,
        // so ticking one on a status-only change would just resend content for no reason.
        function hbSetPendingStatus(status, scheduledAt) {
            hbPendingStatus = status || null;
            hbPendingScheduledAt = hbPendingStatus === 'scheduled' ? (scheduledAt || null) : null;
            hbEmitSaveState(hbHasPending() ? (hbConflicted ? 'conflict' : 'dirty') : 'saved');
        }

        // `slug`/`publishedAt` null clears a pending edit (the user typed back the already-
        // committed value) — same shape as hbSetPendingStatus's `status: null`, just for the
        // Summary's two plain inputs instead of its select.
        function hbSetPendingSlug(slug) {
            hbPendingSlug = slug;
            hbEmitSaveState(hbHasPending() ? (hbConflicted ? 'conflict' : 'dirty') : 'saved');
        }
        function hbSetPendingPublishedAt(publishedAt) {
            hbPendingPublishedAt = publishedAt;
            hbEmitSaveState(hbHasPending() ? (hbConflicted ? 'conflict' : 'dirty') : 'saved');
        }

        // `seo: null` (or an object with no keys) clears the pending SEO edit — same "picked
        // back the committed value" posture as the setters above, just for an object of fields
        // instead of one scalar. panel-seo-social.blade.php's script only ever sends the keys
        // that actually differ from its last-confirmed snapshot, so an empty object here really
        // does mean "nothing left to save", not "save every field as empty".
        function hbSetPendingSeo(seo) {
            hbPendingSeo = (seo && Object.keys(seo).length > 0) ? seo : null;
            hbEmitSaveState(hbHasPending() ? (hbConflicted ? 'conflict' : 'dirty') : 'saved');
        }

        // Anything queued that isn't ordinary content dirt — status/schedule, slug,
        // published_at, or SEO — still counts as "something to save".
        function hbHasPending() {
            return hbDirty || hbPendingStatus !== null || hbPendingSlug !== null || hbPendingPublishedAt !== null || hbPendingSeo !== null;
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

            // Autosave payloads never carry a transition, slug, or published_at edit
            // (PostController skips all three outright for `autosave: true`) — only an
            // explicit Save applies a queued status/scheduled_at/slug/published_at.
            const includeStatus = explicit && hbPendingStatus !== null;
            const includeSlug = explicit && hbPendingSlug !== null;
            const includePublishedAt = explicit && hbPendingPublishedAt !== null;
            const includeSeo = explicit && hbPendingSeo !== null;
            const extra = Object.assign({ autosave: !explicit }, hbTitleSaveExtra());
            if (hbPostId !== null) extra.content_version = hbContentVersion;
            // docs/email-system.md §7-E3: the FIRST save of a type=email document carries `type`
            // so PostController's create-only handling stamps it — see that method's own note.
            // Never sent once hbPostId exists (every save after the first is an update, where
            // `type` is simply ignored server-side, so there's no reason to keep resending it).
            if (hbPostId === null && hbDocumentType === 'email') extra.type = 'email';
            if (includeStatus) {
                extra.status = hbPendingStatus;
                if (hbPendingStatus === 'scheduled' && hbPendingScheduledAt) extra.scheduled_at = hbPendingScheduledAt;
            }
            if (includeSlug) extra.slug = hbPendingSlug;
            if (includePublishedAt) extra.published_at = hbPendingPublishedAt;
            if (includeSeo) extra.seo = hbPendingSeo;
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
                        // The transition/slug/published_at (whichever rode this save) just
                        // applied — the queued choices are spent.
                        if (includeStatus) { hbPendingStatus = null; hbPendingScheduledAt = null; }
                        if (includeSlug) hbPendingSlug = null;
                        if (includePublishedAt) hbPendingPublishedAt = null;
                        if (includeSeo) hbPendingSeo = null;
                        // The Post tab's Summary rows re-read status/slug/publish-date from this —
                        // every 2xx save (manual or autosave) echoes the fresh post payload.
                        if (res.data && res.data.post) {
                            document.dispatchEvent(new CustomEvent('hb:post-saved', { detail: { post: res.data.post } }));
                        }
                        hbEmitSaveState(hbHasPending() ? 'dirty' : 'saved');
                        return;
                    }
                    hbDirty = true; // the attempted save did not happen — still unsaved
                    if (includeStatus) {
                        // The queued transition never applied (whatever failed below) — drop it
                        // rather than silently resend it on the next retry/autosave; the Summary's
                        // status control falls back to the post's real, last-confirmed status.
                        hbPendingStatus = null;
                        hbPendingScheduledAt = null;
                        document.dispatchEvent(new CustomEvent('hb:post-status-rejected'));
                    }
                    if (includeSlug) {
                        hbPendingSlug = null;
                        document.dispatchEvent(new CustomEvent('hb:post-slug-rejected'));
                    }
                    if (includePublishedAt) {
                        hbPendingPublishedAt = null;
                        document.dispatchEvent(new CustomEvent('hb:post-published-at-rejected'));
                    }
                    if (includeSeo) {
                        hbPendingSeo = null;
                        document.dispatchEvent(new CustomEvent('hb:post-seo-rejected'));
                    }
                    if (res.status === 409) {
                        hbConflicted = true;
                        hbEmitSaveState('conflict', { message: (res.data && res.data.message) || hbMsgConflict });
                        return;
                    }
                    if (res.status === 422) {
                        const errors = (res.data && res.data.errors) || {};
                        const messages = [];
                        Object.keys(errors).forEach((key) => { (errors[key] || []).forEach((m) => messages.push(m)); });
                        // A lifecycle transition failure (PostController::applyTransition) has no
                        // `errors` map, just `message` — fall back to it before the generic string.
                        hbEmitSaveState('error', { message: messages.join(' ') || (res.data && res.data.message) || hbMsgInvalid, errors: errors });
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

            // Corrects the SSR-rendered title/label for a persisted non-home editing-locale
            // choice (block-runtime.blade.php resolves it from localStorage before this runs —
            // see its own docblock) — once only, so a later hb:refresh can't stomp mid-edit text.
            if (!document.__hbLocaleApplied && window.hbEditor && window.hbEditor.getEditingLocale) {
                document.__hbLocaleApplied = true;
                hbApplyEditingLocale(window.hbEditor.getEditingLocale(), window.hbEditor.getHomeLocale ? window.hbEditor.getHomeLocale() : window.hbEditor.getEditingLocale());
            }

            // Email export trigger — "save first" posture, same as every other post-scoped control.
            if (!document.__hbExportEnable) {
                document.__hbExportEnable = true;
                document.addEventListener('hb:post-id', () => {
                    document.querySelectorAll('[data-hb-export-toggle]').forEach((btn) => { btn.disabled = false; });
                });
            }

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

            // The Summary's status control (inspector.blade.php) queues a status/scheduled_at
            // pick here — see hbSetPendingStatus's docblock for why it's tracked apart from hbDirty.
            if (!document.__hbStatusPendingWired) {
                document.__hbStatusPendingWired = true;
                document.addEventListener('hb:post-status-change', (event) => {
                    const detail = event.detail || {};
                    hbSetPendingStatus(detail.status || null, detail.scheduledAt || null);
                });
            }

            // Same pending-edit posture as the status control above, for the Summary's URL/slug
            // and Publish-date rows — see hbSetPendingSlug/hbSetPendingPublishedAt's docblock.
            if (!document.__hbSlugPublishedAtPendingWired) {
                document.__hbSlugPublishedAtPendingWired = true;
                document.addEventListener('hb:post-slug-change', (event) => {
                    hbSetPendingSlug((event.detail || {}).slug ?? null);
                });
                document.addEventListener('hb:post-published-at-change', (event) => {
                    hbSetPendingPublishedAt((event.detail || {}).publishedAt ?? null);
                });
            }

            // The SEO/Social panel's queued field edits (panel-seo-social.blade.php) — same
            // pending-edit posture as the block above, for an object of fields instead of one
            // scalar. See hbSetPendingSeo's docblock.
            if (!document.__hbSeoPendingWired) {
                document.__hbSeoPendingWired = true;
                document.addEventListener('hb:post-seo-change', (event) => {
                    hbSetPendingSeo((event.detail || {}).seo ?? null);
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

                    // An email document, already saved: EmailPreviewController renders through the
                    // SAME EmailRenderer the Mailable uses (docs/email-system.md §7-E3) rather than
                    // the ordinary BlockRenderer path below — a never-saved email falls through to
                    // the generic session-backed preview instead (EmailRenderer needs a persisted
                    // Post to read its block tree from).
                    if (hbDocumentType === 'email' && hbPostId !== null) {
                        openOrNavigate(hbEmailPreviewUrlTemplate.replace('__ID__', hbPostId));
                        return;
                    }

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
                        body: (() => {
                            const idEl = document.querySelector('[data-hb-featured-image-id]');
                            const urlEl = document.querySelector('[data-hb-featured-image-id-url], [data-hb-featured-image-url]');
                            const featuredId = idEl && idEl.value ? idEl.value : '';
                            const featuredUrl = urlEl && urlEl.value ? urlEl.value : '';
                            const payload = { title: hbReadTitle(), blocks: window.hbEditor.getDoc().blocks || [] };
                            if (featuredId && featuredUrl) {
                                payload.featured_image = { id: Number(featuredId), url: featuredUrl };
                            }
                            return JSON.stringify(payload);
                        })(),
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

            // Email export — download menu beside Preview (docs/email-system.md §6).
            const setExportMenu = (open) => {
                document.querySelectorAll('.hb-topbar__exportsel-menu').forEach((m) => { m.hidden = !open; });
                document.querySelectorAll('[data-hb-export-toggle]').forEach((t) => t.setAttribute('aria-expanded', open ? 'true' : 'false'));
            };
            document.querySelectorAll('[data-hb-export-toggle]').forEach((btn) => {
                if (btn.__hbExpT) return; btn.__hbExpT = true;
                btn.addEventListener('click', (e) => {
                    e.stopPropagation();
                    if (btn.disabled) return;
                    const menu = document.querySelector('.hb-topbar__exportsel-menu');
                    setExportMenu(!menu || menu.hidden);
                });
            });
            document.querySelectorAll('[data-hb-export-item]').forEach((opt) => {
                if (opt.__hbExpI) return; opt.__hbExpI = true;
                opt.addEventListener('click', () => {
                    hbSeed();
                    setExportMenu(false);
                    if (hbPostId === null || !hbEmailExportUrlTemplate) return;
                    window.location.href = hbEmailExportUrlTemplate.replace('__ID__', hbPostId) + '?format=' + opt.dataset.format;
                });
            });
            if (!document.__hbExportOutside) {
                document.__hbExportOutside = true;
                document.addEventListener('click', (e) => { if (!e.target.closest('.hb-topbar__exportsel')) setExportMenu(false); });
            }

            // Editing-locale dropdown (docs/content-translation.md §0/Wave 2) — same open/close
            // shape as the device dropdown above. Picking an option switches which locale the
            // WHOLE document is being edited in (window.hbEditor.setEditingLocale) — no
            // navigation, no request; hb:editing-locale-change (fired from there) is what
            // actually relabels the trigger and swaps the title (see hbApplyEditingLocale above).
            const setLangMenu = (open) => {
                document.querySelectorAll('.hb-topbar__langsel-menu').forEach((m) => { m.hidden = !open; });
                document.querySelectorAll('[data-hb-lang-toggle]').forEach((t) => t.setAttribute('aria-expanded', open ? 'true' : 'false'));
            };
            document.querySelectorAll('[data-hb-lang-toggle]').forEach((btn) => {
                if (btn.__hbLangT) return; btn.__hbLangT = true;
                btn.addEventListener('click', (e) => {
                    e.stopPropagation();
                    const menu = document.querySelector('.hb-topbar__langsel-menu');
                    setLangMenu(!menu || menu.hidden);
                });
            });
            document.querySelectorAll('[data-hb-lang-option]').forEach((opt) => {
                if (opt.__hbLangOpt) return; opt.__hbLangOpt = true;
                opt.addEventListener('click', () => {
                    if (window.hbEditor && window.hbEditor.setEditingLocale) window.hbEditor.setEditingLocale(opt.dataset.locale || '');
                    setLangMenu(false);
                });
            });
            if (!document.__hbLangOutside) {
                document.__hbLangOutside = true;
                document.addEventListener('click', (e) => { if (!e.target.closest('.hb-topbar__langsel')) setLangMenu(false); });
            }
        };
        if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot, { once: true });
        else boot();
        document.addEventListener('hb:refresh', boot);
    })();
</script>
@endonce

@props([
    'postId' => null,
    'contentVersion' => 0,
    // The editing-locale dropdown's data (docs/content-translation.md §0/Wave 2). `homeLocale` is
    // this document's own `locale` column (the bare-key rule's home locale — see
    // block-runtime.blade.php); the dropdown's INITIAL editing locale is corrected client-side
    // from a persisted per-post choice (hbApplyEditingLocale, called once at boot).
    'homeLocale' => 'en',
    'contentLocales' => ['en', 'fr'],
    'contentLocaleLabels' => [],
    'postTitleByLocale' => [],
    // docs/email-system.md §7-E3 — 'post' or 'email'; drives the preview button's target.
    'documentType' => 'post',
    'emailPreviewUrlTemplate' => '',
    'emailExportUrlTemplate' => '',
])
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
        // Post-language dropdown — placed immediately left of the device dropdown it mirrors.
        ['icon' => 'translate', 'label' => __('heisenberg::editor.topbar.aria_post_language'), 'lang' => true],
        ['icon' => 'device-mobile', 'label' => __('heisenberg::editor.topbar.aria_device'), 'device' => true],
    ];
    // Email export (docs/email-system.md §6) — beside Preview, email documents only.
    if ($documentType === 'email') {
        array_splice($rightButtons, 2, 0, [[
            'icon' => 'download-simple', 'label' => __('heisenberg::editor.topbar.aria_email_export'), 'export' => true,
        ]]);
    }
    // SSR best-effort: the editing locale defaults to the document's own home locale (JS
    // corrects this once at boot for a returning session that had switched away — see
    // hbApplyEditingLocale). Never disabled — switching locale is a pure client-side edit target,
    // it needs no saved post to be meaningful.
    $hbCurrentLocale = $homeLocale;
    $hbCurrentLocaleLabel = $contentLocaleLabels[$hbCurrentLocale] ?? __('heisenberg::editor.locales.' . $hbCurrentLocale);
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
    data-hb-document-type="{{ $documentType }}"
    data-hb-email-preview-url-template="{{ $emailPreviewUrlTemplate }}"
    data-hb-email-export-url-template="{{ $emailExportUrlTemplate }}"
    {{-- docs/content-translation.md §0/Wave 2 — the editing-locale dropdown's label lookup and
         the title-per-locale cache the save payload is built from (see hbTitleSaveExtra above). --}}
    data-hb-locale-labels="{{ json_encode($contentLocaleLabels) }}"
    data-hb-title-by-locale="{{ json_encode($postTitleByLocale) }}"
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
            @elseif ($btn['export'] ?? false)
                {{-- Email export (docs/email-system.md §6) — Download HTML / .eml, wired above. --}}
                <div class="hb-topbar__exportsel">
                    <button type="button" class="hb-topbar__btn hb-topbar__btn--sm" data-hb-export-toggle
                        aria-haspopup="true" aria-expanded="false" aria-label="{{ $btn['label'] }}"
                        @if (($postId ?? null) === null) disabled @endif>
                        <span class="hb-topbar__icon hb-topbar__icon--sm" aria-hidden="true">
                            @include('heisenberg::components.ui.icon', ['name' => $btn['icon'], 'size' => 13])
                        </span>
                    </button>
                    <div class="hb-topbar__devsel-menu hb-topbar__exportsel-menu" role="menu" hidden>
                        <button type="button" class="hb-topbar__devsel-opt" role="menuitem" data-hb-export-item data-format="html">{{ __('heisenberg::editor.topbar.export_html') }}</button>
                        <button type="button" class="hb-topbar__devsel-opt" role="menuitem" data-hb-export-item data-format="eml">{{ __('heisenberg::editor.topbar.export_eml') }}</button>
                    </div>
                </div>
            @elseif ($btn['lang'] ?? false)
                {{-- Editing-locale dropdown (docs/content-translation.md §0/Wave 2) — switches
                     which locale the WHOLE document is being edited in, wired in the script
                     above. Trigger shows the CURRENT editing locale's name (not just an icon,
                     unlike the device trigger — there is no per-locale glyph to swap). Never
                     disabled: this is a pure client-side edit target, meaningful even on a
                     never-saved document. --}}
                <div class="hb-topbar__langsel">
                    <button type="button" class="hb-topbar__btn hb-topbar__btn--sm hb-topbar__lang" data-hb-lang-toggle
                        aria-haspopup="listbox" aria-expanded="false" aria-label="{{ $btn['label'] }}">
                        <span class="hb-topbar__icon hb-topbar__icon--sm" aria-hidden="true">
                            @include('heisenberg::components.ui.icon', ['name' => $btn['icon'], 'size' => 13])
                        </span>
                        <span class="hb-topbar__lang-label" data-hb-lang-current-label>{{ $hbCurrentLocaleLabel }}</span>
                    </button>
                    <div class="hb-topbar__langsel-menu" role="listbox" hidden>
                        @foreach ($contentLocales as $hbLocale)
                            <button type="button" class="hb-topbar__langsel-opt @if ($hbLocale === $hbCurrentLocale) is-on @endif" role="option"
                                aria-selected="{{ $hbLocale === $hbCurrentLocale ? 'true' : 'false' }}"
                                data-hb-lang-option data-locale="{{ $hbLocale }}">
                                <span>{{ $contentLocaleLabels[$hbLocale] ?? __('heisenberg::editor.locales.' . $hbLocale) }}</span>
                                <span class="hb-topbar__langsel-opt__check" aria-hidden="true">
                                    @include('heisenberg::components.ui.icon', ['name' => 'check', 'size' => 12])
                                </span>
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
