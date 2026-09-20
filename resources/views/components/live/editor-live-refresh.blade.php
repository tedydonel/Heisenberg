{{--
    Live editor updates for EXTERNALLY-authored content (e.g. an MCP client's create_post/
    update_post/write_canvas) — see src/Http/Controllers/PostLiveController.php's docblock for
    the full design rationale (polling vs. SSE/broadcasting, authorization, the ETag/304 path).

    Mounted once from resources/views/editor/index.blade.php, AFTER live.block-runtime and
    live.topbar so window.hbEditor / window.hbTopbarState already exist by the time this script
    runs (both are assigned synchronously by their own inline <script> tags earlier in the same
    document — this file adds only a defensive DOMContentLoaded fallback on top of that).

    Contract with the rest of the shell (deliberately reuses what already exists — no second
    document-replacement path, no second save-state channel):
      - window.hbEditor.replaceDoc(blocks) swaps the canvas — the SAME call the in-editor AI
        assistant uses (live/ai/panel-script.blade.php).
      - window.hbTopbarState (added alongside this feature — live/topbar/script.blade.php) is
        the read/write bridge onto the topbar's own hbContentVersion/hbDirty/hbSaveInFlight
        closure state, so this file never keeps a second, divergent copy of "is the doc dirty"
        or "what version are we on".
      - hb:post-id is the existing event a brand-new (never-saved) post's first save already
        dispatches; listening for it lets a draft start being polled the moment it gets an id,
        with no special-casing elsewhere.
--}}
@once
<style nonce="{{ heisenberg_csp_nonce() }}">
    .hb-live-refresh {
        position: fixed;
        top: 44px;
        left: 50%;
        transform: translateX(-50%);
        z-index: 80;
        display: flex;
        align-items: center;
        gap: var(--hb-space-3, 12px);
        max-width: min(92vw, 440px);
        padding: var(--hb-space-2, 8px) var(--hb-space-3, 12px);
        background: var(--hb-bg);
        border: 1px solid var(--hb-border);
        border-radius: var(--hb-radius-md, 5px);
        box-shadow: var(--hb-shadow-lg, 0 4px 14px rgba(0, 0, 0, .18));
        font-family: var(--hb-font-sans, Rubik, sans-serif);
        font-size: var(--hb-fs-sm, 12px);
    }
    .hb-live-refresh[hidden] { display: none; }
    .hb-live-refresh__text { flex: 1 1 auto; color: var(--hb-text-primary); }
    .hb-live-refresh[data-kind="available"] { border-color: var(--hb-warning, #b8860b); }
    .hb-live-refresh__actions { display: flex; align-items: center; gap: var(--hb-space-2, 8px); flex: none; }
    .hb-live-refresh__btn {
        border: 0;
        background: none;
        cursor: pointer;
        font-family: inherit;
        font-size: inherit;
        font-weight: 500;
        padding: 3px 7px;
        border-radius: var(--hb-radius-sm, 3px);
        color: var(--hb-text-secondary);
    }
    .hb-live-refresh__btn:hover { background: var(--hb-surface-hover); color: var(--hb-text-primary); }
    .hb-live-refresh__btn--primary { color: var(--hb-accent); }
    /* Incoming-change highlight. Deliberately the same visual language as the in-editor AI's
       own `.hb-ai-writing` (pulsing outline in --hb-editing-soft, defined in panel-ai.blade.php):
       an author watching the canvas should get the same "something is being written here" cue
       whether the words come from the AI panel or from an external MCP client. Defined here
       rather than reusing that class so the cue still works when the AI panel is disabled. */
    .hb-canvas .hb-blk.hb-live-incoming {
        outline: 2px solid var(--hb-editing-soft, #8AA1FF);
        outline-offset: 2px;
        animation: hb-live-incoming-pulse 1.2s ease-in-out infinite;
    }
    @keyframes hb-live-incoming-pulse { 50% { outline-color: transparent; } }
    @media (prefers-reduced-motion: reduce) {
        .hb-canvas .hb-blk.hb-live-incoming { animation: none; }
    }
</style>
@endonce
@php
    $hbLiveRefreshEnabled = (bool) config('heisenberg.editor.live_refresh.enabled', true);
    // Floor of 1000ms regardless of config — a misconfigured near-zero value must not turn this
    // into a request-per-frame hammer; PostLiveController's own ETag/304 path keeps a normal
    // interval cheap, but there is no floor on the server side, so it belongs here.
    $hbLiveRefreshIntervalMs = max(1000, (int) config('heisenberg.editor.live_refresh.interval_ms', 4000));
@endphp
@if ($hbLiveRefreshEnabled)
<div class="hb-live-refresh" data-hb-live-refresh role="status" aria-live="polite"
    aria-label="{{ __('heisenberg::editor.live_refresh.aria_notice') }}" hidden>
    <span class="hb-live-refresh__text" data-hb-live-refresh-text></span>
    <span class="hb-live-refresh__actions">
        <button type="button" class="hb-live-refresh__btn hb-live-refresh__btn--primary" data-hb-live-refresh-load hidden>{{ __('heisenberg::editor.live_refresh.load') }}</button>
        <button type="button" class="hb-live-refresh__btn" data-hb-live-refresh-dismiss>{{ __('heisenberg::editor.live_refresh.dismiss') }}</button>
    </span>
</div>
<script type="application/json" data-hb-live-refresh-config>{!! json_encode([
    'intervalMs' => $hbLiveRefreshIntervalMs,
    'statusUrlTemplate' => route('heisenberg.editor.posts.live-status', ['post' => '__ID__']),
    'showUrlTemplate' => route('heisenberg.editor.posts.show', ['post' => '__ID__']),
    'msgApplied' => __('heisenberg::editor.live_refresh.applied'),
    'msgAvailable' => __('heisenberg::editor.live_refresh.available'),
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}</script>
<script nonce="{{ heisenberg_csp_nonce() }}">
    (() => {
        if (document.__hbLiveRefreshBooted) return;
        document.__hbLiveRefreshBooted = true;

        const cfgEl = document.querySelector('[data-hb-live-refresh-config]');
        if (!cfgEl) return;
        let cfg;
        try { cfg = JSON.parse(cfgEl.textContent || '{}'); } catch (e) { return; }
        if (!cfg || !cfg.statusUrlTemplate || !cfg.showUrlTemplate) return;

        const toastEl = () => document.querySelector('[data-hb-live-refresh]');

        let postId = (() => {
            const root = document.querySelector('.hb-topbar');
            const raw = root ? root.dataset.hbPostId : '';
            return raw ? raw : null;
        })();
        let etag = null;      // last ETag seen from the status endpoint (conditional GET)
        let noticeVersion = null; // the remote content_version currently offered in the "available" banner
        let stopped = false;  // 404/403 (post gone, trashed, or no longer viewable) — never poll again
        let timer = null;
        let hideTimer = null;

        const showToast = (kind, text, withLoad) => {
            const el = toastEl();
            if (!el) return;
            clearTimeout(hideTimer);
            el.dataset.kind = kind;
            el.hidden = false;
            const textEl = el.querySelector('[data-hb-live-refresh-text]');
            if (textEl) textEl.textContent = text;
            const loadBtn = el.querySelector('[data-hb-live-refresh-load]');
            if (loadBtn) loadBtn.hidden = !withLoad;
        };
        const hideToast = () => {
            const el = toastEl();
            if (el) el.hidden = true;
        };

        // Fetches the post's current full document (SAME endpoint the rest of the editor loads
        // a post through — heisenberg.editor.posts.show) and swaps the canvas via the one
        // document-replacement path the shell already has. Resolves true only on a real apply.
        /**
         * Mirror the externally-saved title for the locale being edited, the same way the
         * in-editor AI assistant's own set_page_title handler does (see
         * live/ai/panel-script.blade.php) — the canvas title is a contenteditable <h1>, so it
         * takes textContent, and both input+change are dispatched so the topbar's own
         * listeners see it exactly as if a person had typed it. Without this, an external
         * write that renamed the post would swap the blocks under an unchanged title.
         */
        const applyRemoteTitle = (post) => {
            const field = document.querySelector('[data-hb-title]');
            if (!field || !post) return;

            const locale = (window.hbEditor && window.hbEditor.getEditingLocale)
                ? window.hbEditor.getEditingLocale()
                : 'en';
            const incoming = post['title_' + locale];
            if (typeof incoming !== 'string') return;

            const current = ('value' in field) ? field.value : field.textContent;
            if ((current || '').trim() === incoming.trim()) return;

            if ('value' in field) {
                field.value = incoming;
            } else {
                field.textContent = incoming;
            }
            field.dispatchEvent(new Event('input', { bubbles: true }));
            field.dispatchEvent(new Event('change', { bubbles: true }));
        };

        /**
         * Visual parity with the in-editor AI: when content arrives from outside this tab, pulse
         * the blocks that actually changed so the author SEES where the writing landed instead of
         * text silently appearing. Ids present before the swap are remembered, so only genuinely
         * new/changed blocks light up; the class is removed after one beat so it never becomes
         * permanent chrome. Purely cosmetic — wrapped so a DOM surprise can never break the sync.
         */
        let incomingTimer = null;
        const blockIdsNow = () => {
            const out = new Set();
            document.querySelectorAll('.hb-canvas .hb-blk[data-block]').forEach((el) => out.add(el.getAttribute('data-block')));
            return out;
        };
        const highlightIncoming = (before) => {
            try {
                const canvas = document.querySelector('.hb-canvas');
                if (!canvas) return;
                canvas.querySelectorAll('.hb-blk.hb-live-incoming').forEach((el) => el.classList.remove('hb-live-incoming'));

                const fresh = Array.from(canvas.querySelectorAll('.hb-blk[data-block]'))
                    .filter((el) => !before.has(el.getAttribute('data-block')));
                // A pure content edit (same ids) still deserves a cue — fall back to the last block,
                // which is what the AI panel highlights while streaming.
                const targets = fresh.length ? fresh : Array.from(canvas.querySelectorAll('.hb-blk[data-block]')).slice(-1);
                targets.forEach((el) => el.classList.add('hb-live-incoming'));

                if (targets[0]) {
                    try { targets[0].scrollIntoView({ block: 'nearest', behavior: 'smooth' }); } catch (e) { }
                }

                clearTimeout(incomingTimer);
                incomingTimer = setTimeout(() => {
                    canvas.querySelectorAll('.hb-blk.hb-live-incoming').forEach((el) => el.classList.remove('hb-live-incoming'));
                }, 2400);
            } catch (e) { /* cosmetic only */ }
        };

        const applyRemote = (version) => window.fetch(cfg.showUrlTemplate.replace('__ID__', postId), {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        })
            .then((r) => (r.ok ? r.json() : null))
            .then((data) => {
                if (!data || !data.post || !window.hbEditor || typeof window.hbEditor.replaceDoc !== 'function') return false;
                const idsBefore = blockIdsNow();
                window.hbEditor.replaceDoc(data.blocks || []);
                highlightIncoming(idsBefore);
                applyRemoteTitle(data.post);
                // AFTER the title is in the DOM: acknowledgeExternalSync() recomputes the
                // autosave baseline from hbTitleSaveExtra(), which reads [data-hb-title].
                if (window.hbTopbarState) window.hbTopbarState.acknowledgeExternalSync(data.post.content_version);
                return true;
            })
            .catch(() => false);

        const poll = () => {
            if (stopped || !postId) return Promise.resolve();
            // Never mid-save: an autosave/explicit save PUT is already racing the version this
            // poll would read, and applying a remote doc under it would be a wasted or confusing
            // round trip either way. Simply skip this tick — the next one tries again.
            if (window.hbTopbarState && window.hbTopbarState.isSaving()) return Promise.resolve();

            const headers = { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' };
            if (etag) headers['If-None-Match'] = etag;

            return window.fetch(cfg.statusUrlTemplate.replace('__ID__', postId), { headers, credentials: 'same-origin' })
                .then((r) => {
                    if (r.status === 304) return null; // unchanged — nothing to do
                    if (r.status === 404 || r.status === 403) { stopped = true; hideToast(); return null; }
                    if (!r.ok) return null; // transient server error — try again next tick
                    etag = r.headers.get('ETag') || etag;

                    return r.json().catch(() => null);
                })
                .then((data) => {
                    if (!data || data.enabled === false || data.content_version == null) return undefined;

                    const remote = Number(data.content_version);
                    const local = window.hbTopbarState ? window.hbTopbarState.getContentVersion() : remote;
                    if (!(remote > local)) {
                        // Remote has caught up to (or never got ahead of) us — clear a stale offer.
                        if (noticeVersion !== null) { noticeVersion = null; hideToast(); }
                        return undefined;
                    }

                    // DIRTY GUARD: never silently overwrite unsaved local edits. Offer the newer
                    // version instead, and only ever apply it on the user's own confirmation.
                    // Gated on hasUnsavedChanges() — a REAL content difference — not on the bare
                    // hbDirty flag, which a stray click or a typed-then-undone edit also sets and
                    // which nothing clears until a save succeeds; gating on that alone made one
                    // incidental keystroke stop live updates permanently (see hasUnsavedChanges()).
                    const topbar = window.hbTopbarState;
                    const blocked = topbar
                        ? (typeof topbar.hasUnsavedChanges === 'function' ? topbar.hasUnsavedChanges() : topbar.isDirty())
                        : false;
                    if (blocked) {
                        if (noticeVersion === remote) return undefined; // already offering this exact version
                        noticeVersion = remote;
                        showToast('available', cfg.msgAvailable, true);

                        return undefined;
                    }

                    // Clean document: this IS the "live" behaviour the feature exists for — bring
                    // the external change in automatically, but still announce it so the author
                    // knows why the canvas just changed under them.
                    noticeVersion = null;

                    return applyRemote(remote).then((applied) => {
                        if (!applied) return;
                        showToast('applied', cfg.msgApplied, false);
                        clearTimeout(hideTimer);
                        hideTimer = setTimeout(hideToast, 5000);
                    });
                })
                .catch(() => undefined); // offline / network hiccup — silent, next tick tries again
        };

        // Runs only while the tab is actually visible: a background tab holds no timer at all
        // (rather than merely skipping the fetch), so "stop polling when hidden" is literal.
        // visibilitychange below restarts it the moment the tab is looked at again.
        const tick = () => {
            timer = null;
            if (stopped || document.visibilityState !== 'visible') return;
            poll().finally(() => {
                if (!stopped) timer = setTimeout(tick, cfg.intervalMs);
            });
        };
        const kick = () => {
            if (stopped || timer || !postId) return;
            timer = setTimeout(tick, cfg.intervalMs);
        };

        document.addEventListener('visibilitychange', () => {
            if (document.visibilityState === 'visible') kick();
        });
        // A brand-new post has no id (and nothing to poll) until its first save; hb:post-id is
        // dispatched exactly then (live/topbar/script.blade.php) — start polling from that point.
        document.addEventListener('hb:post-id', (event) => {
            const id = event.detail && event.detail.id;
            if (id == null) return;
            postId = String(id);
            etag = null;
            noticeVersion = null;
            hideToast();
            kick();
        });

        const wireButtons = () => {
            const el = toastEl();
            if (!el || el.__hbLiveRefreshWired) return;
            el.__hbLiveRefreshWired = true;
            const loadBtn = el.querySelector('[data-hb-live-refresh-load]');
            const dismissBtn = el.querySelector('[data-hb-live-refresh-dismiss]');
            if (loadBtn) {
                loadBtn.addEventListener('click', () => {
                    if (noticeVersion === null) { hideToast(); return; }
                    const version = noticeVersion;
                    noticeVersion = null;
                    applyRemote(version).then((applied) => {
                        if (applied) showToast('applied', cfg.msgApplied, false);
                        else hideToast();
                        clearTimeout(hideTimer);
                        hideTimer = setTimeout(hideToast, 5000);
                    });
                });
            }
            if (dismissBtn) {
                dismissBtn.addEventListener('click', () => hideToast());
            }
        };

        wireButtons();
        kick();
    })();
</script>
@endif
