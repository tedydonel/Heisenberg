<script nonce="{{ heisenberg_csp_nonce() }}">
    (() => {
        const HB_PANEL_KEYS = ['sidebar', 'panel', 'inspector'];
        const hbIsNarrow = () => window.matchMedia('(max-width: 1023px)').matches;
        window.hbSetPanelCollapsed = (shell, key, collapsed) => {
            shell.classList.toggle(`hb-editor--${key}-collapsed`, collapsed);
            localStorage.setItem(`hb-editor:${key}-collapsed`, collapsed ? 'true' : 'false');
        };

                let hbPostId = null;
        let hbContentVersion = 0;
        let hbSaveUrl = '';
        let hbUpdateUrlTemplate = '';
        let hbPreviewStoreUrl = '';
        let hbPreviewShowUrl = '';
        let hbPreviewPostUrlTemplate = '';
        let hbEditorUrlTemplate = '';
        let hbDocumentType = 'post';
        let hbEmailPreviewUrlTemplate = '';
        let hbEmailExportUrlTemplate = '';
        let hbMsgConflict = '';
        let hbMsgInvalid = '';
        let hbMsgNetwork = '';
        let hbSeeded = false;
        let hbDirty = false;
        let hbConflicted = false;
        let hbSaveInFlight = null;
        let hbAutosaveTimer = null;
        const HB_AUTOSAVE_MS = 3000;
        let hbPendingStatus = null;
        let hbPendingScheduledAt = null;
        let hbPendingSlug = null;
        let hbPendingPublishedAt = null;
        let hbPendingSeo = null;
        let hbTitleByLocale = {};
        let hbLocaleLabels = {};

        const hbCsrfToken = () => {
            const meta = document.querySelector('meta[name="csrf-token"]');
            return meta ? meta.getAttribute('content') : '';
        };
        const hbReadTitle = () => {
            const el = document.querySelector('[data-hb-title]');
            if (!el) return '';
            return (el.tagName === 'INPUT' ? el.value : el.textContent).trim();
        };
        const hbLocaleQuery = (separator) => {
            const locale = (window.hbEditor && window.hbEditor.getEditingLocale) ? window.hbEditor.getEditingLocale() : '';
            return locale ? (separator || '?') + 'locale=' + encodeURIComponent(locale) : '';
        };

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
        document.addEventListener('hb:doc-title', (event) => {
            const locale = (window.hbEditor && window.hbEditor.getEditingLocale) ? window.hbEditor.getEditingLocale() : 'en';
            hbTitleByLocale[locale] = (event.detail && event.detail.title) || '';
        });
        document.addEventListener('hb:editing-locale-change', (event) => {
            hbApplyEditingLocale(event.detail.locale, event.detail.homeLocale);
        });

        const hbAdoptPostUrl = () => {
            if (hbPostId === null || !hbEditorUrlTemplate || !window.history || !window.history.replaceState) return;
            const target = hbEditorUrlTemplate.replace('__ID__', hbPostId);
            if (window.location.href === target) return;
            try { window.history.replaceState({ hbPostId: hbPostId }, '', target); } catch (e) { }
        };

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

        function hbSetPendingStatus(status, scheduledAt) {
            hbPendingStatus = status || null;
            hbPendingScheduledAt = hbPendingStatus === 'scheduled' ? (scheduledAt || null) : null;
            hbEmitSaveState(hbHasPending() ? (hbConflicted ? 'conflict' : 'dirty') : 'saved');
        }

        function hbSetPendingSlug(slug) {
            hbPendingSlug = slug;
            hbEmitSaveState(hbHasPending() ? (hbConflicted ? 'conflict' : 'dirty') : 'saved');
        }
        function hbSetPendingPublishedAt(publishedAt) {
            hbPendingPublishedAt = publishedAt;
            hbEmitSaveState(hbHasPending() ? (hbConflicted ? 'conflict' : 'dirty') : 'saved');
        }

        function hbSetPendingSeo(seo) {
            hbPendingSeo = (seo && Object.keys(seo).length > 0) ? seo : null;
            hbEmitSaveState(hbHasPending() ? (hbConflicted ? 'conflict' : 'dirty') : 'saved');
        }

        function hbHasPending() {
            return hbDirty || hbPendingStatus !== null || hbPendingSlug !== null || hbPendingPublishedAt !== null || hbPendingSeo !== null;
        }

        function hbPerformSave(explicit) {
            if (hbSaveInFlight) return;
            if (!navigator.onLine) return;
            if (hbConflicted && !explicit) return;
            if (!explicit && hbPostId === null) return;
            if (!window.hbEditor || typeof window.hbEditor.buildSavePayload !== 'function') return;

            clearTimeout(hbAutosaveTimer);
            hbAutosaveTimer = null;
            hbDirty = false;

            const includeStatus = explicit && hbPendingStatus !== null;
            const includeSlug = explicit && hbPendingSlug !== null;
            const includePublishedAt = explicit && hbPendingPublishedAt !== null;
            const includeSeo = explicit && hbPendingSeo !== null;
            const extra = Object.assign({ autosave: !explicit }, hbTitleSaveExtra());
            if (hbPostId !== null) extra.content_version = hbContentVersion;
            if (window.hbEditor && window.hbEditor.getEditingLocale) {
                extra.editingLocale = window.hbEditor.getEditingLocale();
            }
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
                            if (wasNew && hbPostId !== null) {
                                hbAdoptPostUrl();
                                document.dispatchEvent(new CustomEvent('hb:post-id', { detail: { id: hbPostId } }));
                            }
                        }
                        hbConflicted = false;
                        if (includeStatus) { hbPendingStatus = null; hbPendingScheduledAt = null; }
                        if (includeSlug) hbPendingSlug = null;
                        if (includePublishedAt) hbPendingPublishedAt = null;
                        if (includeSeo) hbPendingSeo = null;
                        if (res.data && res.data.post) {
                            document.dispatchEvent(new CustomEvent('hb:post-saved', { detail: { post: res.data.post } }));
                        }
                        hbEmitSaveState(hbHasPending() ? 'dirty' : 'saved');
                        return;
                    }
                    hbDirty = true;
                    if (includeStatus) {
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
                        hbEmitSaveState('error', { message: messages.join(' ') || (res.data && res.data.message) || hbMsgInvalid, errors: errors });
                        return;
                    }
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

            if (!document.__hbLocaleApplied && window.hbEditor && window.hbEditor.getEditingLocale) {
                document.__hbLocaleApplied = true;
                hbApplyEditingLocale(window.hbEditor.getEditingLocale(), window.hbEditor.getHomeLocale ? window.hbEditor.getHomeLocale() : window.hbEditor.getEditingLocale());
            }

            if (!document.__hbExportEnable) {
                document.__hbExportEnable = true;
                document.addEventListener('hb:post-id', () => {
                    document.querySelectorAll('[data-hb-export-toggle]').forEach((btn) => { btn.disabled = false; });
                });
            }

            document.querySelectorAll('.hb-topbar__save').forEach((btn) => {
                if (btn.__hbSave) return; btn.__hbSave = true;
                btn.addEventListener('click', () => hbPerformSave(true));
            });

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

            if (!document.__hbAutosaveWired) {
                document.__hbAutosaveWired = true;
                document.addEventListener('hb:blocks-changed', hbMarkDirty);
                document.addEventListener('hb:doc-title', hbMarkDirty);
                window.addEventListener('online', () => {
                    if (hbDirty && !hbConflicted && hbPostId !== null) hbPerformSave(false);
                });
            }

            if (!document.__hbStatusPendingWired) {
                document.__hbStatusPendingWired = true;
                document.addEventListener('hb:post-status-change', (event) => {
                    const detail = event.detail || {};
                    hbSetPendingStatus(detail.status || null, detail.scheduledAt || null);
                });
            }

            if (!document.__hbSlugPublishedAtPendingWired) {
                document.__hbSlugPublishedAtPendingWired = true;
                document.addEventListener('hb:post-slug-change', (event) => {
                    hbSetPendingSlug((event.detail || {}).slug ?? null);
                });
                document.addEventListener('hb:post-published-at-change', (event) => {
                    hbSetPendingPublishedAt((event.detail || {}).publishedAt ?? null);
                });
            }

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
                    document.querySelectorAll('[data-hb-nav]').forEach((n) => {
                        n.classList.remove('hb-navitem--active');
                        n.setAttribute('aria-current', 'false');
                    });
                    if (window.hbEditorShowPanel) window.hbEditorShowPanel('nav', 0);
                    document.dispatchEvent(new CustomEvent('hb:nav-open'));
                });
            });
            document.querySelectorAll('[data-hb-preview]').forEach((btn) => {
                if (btn.__hbPreview) return; btn.__hbPreview = true;
                btn.addEventListener('click', () => {
                    hbSeed();
                    const tab = window.open('about:blank', '_blank');
                    const openOrNavigate = (url) => { if (tab) tab.location = url; else window.open(url, '_blank'); };
                    const localeQuery = hbLocaleQuery();

                    if (hbDocumentType === 'email' && hbPostId !== null) {
                        openOrNavigate(hbEmailPreviewUrlTemplate.replace('__ID__', hbPostId) + localeQuery);
                        return;
                    }

                    if (hbPostId !== null) {
                        openOrNavigate(hbPreviewPostUrlTemplate.replace('__ID__', hbPostId) + localeQuery);
                        return;
                    }

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
                            if (res && res.stored) openOrNavigate(hbPreviewShowUrl + localeQuery);
                            else if (tab) tab.close();
                        })
                        .catch(() => { if (tab) tab.close(); });
                });
            });
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
                    window.location.href = hbEmailExportUrlTemplate.replace('__ID__', hbPostId)
                        + '?format=' + opt.dataset.format + hbLocaleQuery('&');
                });
            });
            if (!document.__hbExportOutside) {
                document.__hbExportOutside = true;
                document.addEventListener('click', (e) => { if (!e.target.closest('.hb-topbar__exportsel')) setExportMenu(false); });
            }

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
