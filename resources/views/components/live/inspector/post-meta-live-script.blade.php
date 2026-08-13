        @once('hb-post-meta-live')
        <script>
            (() => {
                const statusLabel = (labels, status) => (labels && labels[status]) || status;
                const readJson = (el, key, fallback) => {
                    try { return JSON.parse((el && el.dataset[key]) || 'null') || fallback; } catch (e) { return fallback; }
                };
                const pad2 = (n) => String(n).padStart(2, '0');
                // TIMEZONE (see PostController's own docblock): every published_at/scheduled_at
                // value this script touches is a naive "Y-m-d\TH:i" app-timezone wall clock —
                // never an offset-bearing ISO string. Parsing it with `new Date(iso)` and reading
                // back local getters would silently reinterpret it through the BROWSER's zone,
                // which is exactly the +1h-style drift this shape avoids. asWallClock() only
                // validates/passes the string through; formatSummaryDate() builds its Date object
                // from the parsed y/mo/d/h/mi FIELDS (the multi-arg constructor treats them as
                // local components verbatim, never reinterpreting), so the digits it displays
                // always match the wall clock that was typed, regardless of browser zone.
                const asWallClock = (value) => {
                    const m = /^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})$/.exec(value || '');
                    return m ? value : '';
                };
                const formatSummaryDate = (value) => {
                    const m = /^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})$/.exec(value || '');
                    if (!m) return null;
                    const d = new Date(Number(m[1]), Number(m[2]) - 1, Number(m[3]), Number(m[4]), Number(m[5]));
                    return d.toLocaleString(undefined, { month: 'short', day: 'numeric', year: 'numeric', hour: '2-digit', minute: '2-digit' });
                };

                const hbSlugMarkers = () => Array.from(document.querySelectorAll('[data-hb-post-slug-input]'));
                const hbSlugInputEl = (marker) => (marker.matches('input') ? marker : marker.querySelector('input'));
                const updateSlugRowText = (value) => {
                    const trigger = document.querySelector('[data-hb-post-popup-trigger="slug"]');
                    if (trigger) trigger.textContent = value ? '/' + value : '—';
                };

                const publishedRoot = () => document.querySelector('[data-hb-post-published-input]');
                const scheduleRoot = () => document.querySelector('[data-hb-post-schedule-input]');

                const closePostPopups = (except) => {
                    document.querySelectorAll('[data-hb-post-popup]').forEach((popup) => { if (popup !== except) popup.hidden = true; });
                    document.querySelectorAll('[data-hb-post-popup-trigger]').forEach((trigger) => {
                        if (!except || !except.contains(trigger)) trigger.setAttribute('aria-expanded', 'false');
                    });
                };
                const showPostPopup = (name, trigger) => {
                    const popup = document.querySelector('[data-hb-post-popup="' + name + '"]');
                    if (!popup) return;
                    const wasOpen = !popup.hidden;
                    closePostPopups(popup);
                    popup.hidden = wasOpen;
                    trigger.setAttribute('aria-expanded', wasOpen ? 'false' : 'true');
                    if (wasOpen) return;
                    const rect = trigger.getBoundingClientRect();
                    const width = popup.offsetWidth;
                    const height = popup.offsetHeight;
                    const left = Math.max(8, Math.min(window.innerWidth - width - 8, rect.right - width));
                    const below = rect.bottom + 8;
                    const top = below + height <= window.innerHeight - 8 ? below : Math.max(8, rect.top - height - 8);
                    popup.style.left = left + 'px';
                    popup.style.top = top + 'px';
                    const focusable = popup.querySelector('input:not(:disabled), [tabindex="0"]');
                    if (focusable) focusable.focus();
                };

                const rebuildStatusOptions = (wrapper, current) => {
                    const list = document.querySelector('[data-hb-post-status-list]');
                    const prototype = list && list.querySelector('[data-hb-post-status-option]');
                    if (!list || !prototype) return;
                    const transitions = readJson(wrapper, 'hbTransitions', {});
                    const labels = readJson(wrapper, 'hbStatusLabels', {});
                    const targets = (transitions[current] || []).filter((t) => t !== current);
                    const blueprint = prototype.cloneNode(true);
                    list.textContent = '';
                    [current, ...targets].forEach((val) => {
                        const option = blueprint.cloneNode(true);
                        option.dataset.hbPostStatusOption = val;
                        option.setAttribute('aria-selected', val === current ? 'true' : 'false');
                        option.classList.toggle('hb-vmi--on', val === current);
                        const name = option.querySelector('.hb-vmi__name');
                        if (name) name.textContent = statusLabel(labels, val);
                        list.appendChild(option);
                    });
                };

                const displayStatusRow = (trigger, status, label) => {
                    trigger.textContent = label;
                    trigger.dataset.value = status;
                    document.querySelectorAll('[data-hb-post-status-option]').forEach((option) => {
                        const on = option.dataset.hbPostStatusOption === status;
                        option.classList.toggle('hb-vmi--on', on);
                        option.setAttribute('aria-selected', on ? 'true' : 'false');
                    });
                };

                const updateScheduleRowText = (value) => {
                    const trigger = document.querySelector('[data-hb-post-popup-trigger="schedule"]');
                    if (!trigger) return;
                    trigger.dataset.hbCurrentScheduledAt = value || '';
                    trigger.textContent = formatSummaryDate(value) || '—';
                };

                const toggleScheduleRow = (show, scheduledAtValue) => {
                    const row = document.querySelector('[data-hb-post-schedule-row]');
                    if (row) {
                        row.hidden = !show;
                        if (show && scheduledAtValue) {
                            const local = asWallClock(scheduledAtValue);
                            const root = scheduleRoot();
                            if (root && root.__hbDatePicker) root.__hbDatePicker.setValue(local);
                            updateScheduleRowText(local);
                        }
                    }
                    const publishRow = document.querySelector('[data-hb-post-publish-row]');
                    if (publishRow) publishRow.hidden = show;
                };

                // A status pick is "pending" from the moment it's clicked (queued for the
                // NEXT EXPLICIT save only — see topbar.blade.php's hbPendingStatus docblock)
                // until the server actually confirms it or rejects it. Surfaced via
                // data-hb-pending so the row itself (not just the footer's global pill)
                // shows the owner their pick hasn't landed yet.
                const setStatusPending = (trigger, pending) => {
                    if (!trigger) return;
                    if (pending) {
                        trigger.dataset.hbPending = 'true';
                        trigger.title = trigger.dataset.hbStatusPendingHint || '';
                    } else {
                        delete trigger.dataset.hbPending;
                        trigger.removeAttribute('title');
                    }
                };

                // Same shape as setStatusPending() above, generalised for the Slug and
                // Publish-date rows (2026-08-12) — they have the identical "queued for the
                // next explicit Save only, clobbered by every hb:post-saved including
                // autosaves that never carried it" latent bug Status just had fixed. Kept as
                // its own function (not a refactor of setStatusPending) so nothing here can
                // regress the Status pinning tests. `value`, when pending, is the queued
                // typed/picked value itself — compared against the server's echo in
                // hb:post-saved below to tell "this save applied it" from "unrelated save,
                // leave the row alone". The Slug row's trigger is `[data-hb-post-popup-
                // trigger="slug"]` — the Summary ROW button, never the `[data-hb-post-slug-
                // input]` marker/input pair shared with the SEO panel's mirrored field, so
                // this never touches that mirroring contract.
                const setRowPending = (trigger, pending, hintKey, value) => {
                    if (!trigger) return;
                    if (pending) {
                        trigger.dataset.hbPending = 'true';
                        trigger.dataset.hbPendingValue = value ?? '';
                        trigger.title = trigger.dataset[hintKey] || '';
                    } else {
                        delete trigger.dataset.hbPending;
                        delete trigger.dataset.hbPendingValue;
                        trigger.removeAttribute('title');
                    }
                };

                const applyConfirmedStatus = (status, scheduledAtValue) => {
                    const trigger = document.querySelector('[data-hb-post-status]');
                    if (!trigger) return;
                    trigger.dataset.hbCurrentStatus = status;
                    trigger.dataset.hbCurrentScheduledAt = scheduledAtValue || '';
                    setStatusPending(trigger, false);
                    rebuildStatusOptions(trigger, status);
                    displayStatusRow(trigger, status, statusLabel(readJson(trigger, 'hbStatusLabels', {}), status));
                    toggleScheduleRow(status === 'scheduled', scheduledAtValue);
                };

                const applyConfirmedSlug = (slug) => {
                    hbSlugMarkers().forEach((marker) => {
                        marker.dataset.hbCurrentSlug = slug || '';
                        const input = hbSlugInputEl(marker);
                        if (input) input.value = slug || '';
                    });
                    updateSlugRowText(slug || '');
                    setRowPending(document.querySelector('[data-hb-post-popup-trigger="slug"]'), false, 'hbSlugPendingHint');
                };
                const applyConfirmedPublishedAt = (value) => {
                    const trigger = document.querySelector('[data-hb-post-popup-trigger="publish"]');
                    if (!trigger) return;
                    const local = value ? asWallClock(value) : '';
                    const root = publishedRoot();
                    if (root && root.__hbDatePicker) root.__hbDatePicker.setValue(local);
                    trigger.dataset.hbCurrentPublishedAt = local;
                    trigger.textContent = formatSummaryDate(local) || trigger.dataset.hbImmediatelyLabel || 'Immediately';
                    setRowPending(trigger, false, 'hbPublishPendingHint');
                };

                document.addEventListener('hb:post-saved', (event) => {
                    const post = event.detail && event.detail.post;
                    if (!post) return;
                    if (typeof post.slug === 'string') {
                        const slugTrigger = document.querySelector('[data-hb-post-popup-trigger="slug"]');
                        // Same clobber risk Status had: autosave never carries a slug edit
                        // (PostController::save() skips applySlug() outright for autosave:true),
                        // so its echoed post.slug is always the unchanged, last-confirmed value.
                        // If a typed slug is still pending, leave the row (and the mirrored SEO-
                        // panel input) showing it rather than reverting mid-edit.
                        const slugStillPendingElsewhere = slugTrigger && slugTrigger.dataset.hbPending === 'true' && post.slug !== slugTrigger.dataset.hbPendingValue;
                        if (!slugStillPendingElsewhere) applyConfirmedSlug(post.slug);
                    }
                    if ('published_at' in post) {
                        const pubTrigger = document.querySelector('[data-hb-post-popup-trigger="publish"]');
                        const pubValue = post.published_at || null;
                        // Same guard, for the Publish-date row (applyPublishedAt() is also
                        // autosave-skipped server-side).
                        const pubStillPendingElsewhere = pubTrigger && pubTrigger.dataset.hbPending === 'true' && (pubValue || '') !== (pubTrigger.dataset.hbPendingValue || '');
                        if (!pubStillPendingElsewhere) applyConfirmedPublishedAt(pubValue);
                    }
                    if (post.status) {
                        const trigger = document.querySelector('[data-hb-post-status]');
                        // Autosave NEVER carries a transition (PostController skips it
                        // outright for autosave:true), so every autosave's echoed post.status
                        // is just the server's unchanged, last-confirmed value. If a status
                        // pick is still pending here, the echo can only mean "this save wasn't
                        // the one that applied it" — leave the row showing the pending pick
                        // rather than clobbering it back to the stale status (the bug: picking
                        // Published, then an unrelated autosave tick visibly reverted the row
                        // to Draft even though the pick was still queued and would have applied
                        // on the next explicit Save).
                        const stillPendingElsewhere = trigger && trigger.dataset.hbPending === 'true' && String(post.status) !== trigger.dataset.value;
                        if (!stillPendingElsewhere) applyConfirmedStatus(String(post.status), post.scheduled_at || null);
                    }
                });

                document.addEventListener('hb:post-status-rejected', () => {
                    const trigger = document.querySelector('[data-hb-post-status]');
                    if (!trigger) return;
                    applyConfirmedStatus(trigger.dataset.hbCurrentStatus || 'draft', trigger.dataset.hbCurrentScheduledAt || null);
                });
                document.addEventListener('hb:post-slug-rejected', () => {
                    hbSlugMarkers().forEach((marker) => {
                        const input = hbSlugInputEl(marker);
                        if (input) input.value = marker.dataset.hbCurrentSlug || '';
                    });
                    updateSlugRowText(hbSlugMarkers()[0] ? hbSlugMarkers()[0].dataset.hbCurrentSlug || '' : '');
                    setRowPending(document.querySelector('[data-hb-post-popup-trigger="slug"]'), false, 'hbSlugPendingHint');
                });
                document.addEventListener('hb:post-published-at-rejected', () => {
                    const trigger = document.querySelector('[data-hb-post-popup-trigger="publish"]');
                    if (!trigger) return;
                    const local = trigger.dataset.hbCurrentPublishedAt || '';
                    const root = publishedRoot();
                    if (root && root.__hbDatePicker) root.__hbDatePicker.setValue(local);
                    trigger.textContent = formatSummaryDate(local) || trigger.dataset.hbImmediatelyLabel || 'Immediately';
                    setRowPending(trigger, false, 'hbPublishPendingHint');
                });

                document.addEventListener('click', (event) => {
                    const option = event.target.closest('[data-hb-post-status-option]');
                    if (option) {
                        const trigger = document.querySelector('[data-hb-post-status]');
                        if (!trigger) return;
                        const committed = trigger.dataset.hbCurrentStatus || 'draft';
                        const status = option.dataset.hbPostStatusOption;
                        displayStatusRow(trigger, status, statusLabel(readJson(trigger, 'hbStatusLabels', {}), status));
                        toggleScheduleRow(status === 'scheduled', trigger.dataset.hbCurrentScheduledAt || null);
                        closePostPopups(null);
                        if (status === committed) {
                            setStatusPending(trigger, false);
                            document.dispatchEvent(new CustomEvent('hb:post-status-change', { detail: { status: null } }));
                            return;
                        }
                        setStatusPending(trigger, true);
                        let scheduledAt = null;
                        if (status === 'scheduled') {
                            const root = scheduleRoot();
                            const picker = root && root.__hbDatePicker;
                            if (picker && !picker.getValue()) {
                                const soon = new Date(Date.now() + 60 * 60 * 1000);
                                const defaultValue = soon.getFullYear() + '-' + pad2(soon.getMonth() + 1) + '-' + pad2(soon.getDate()) + 'T' + pad2(soon.getHours()) + ':' + pad2(soon.getMinutes());
                                picker.setValue(defaultValue);
                                updateScheduleRowText(defaultValue);
                            }
                            scheduledAt = picker ? picker.getValue() : null;
                        }
                        document.dispatchEvent(new CustomEvent('hb:post-status-change', { detail: { status: status, scheduledAt: scheduledAt } }));
                        return;
                    }

                    const trigger = event.target.closest('[data-hb-post-popup-trigger]');
                    if (trigger) {
                        if (trigger.disabled) return;
                        showPostPopup(trigger.dataset.hbPostPopupTrigger, trigger);
                        return;
                    }

                    if (!event.target.closest('[data-hb-post-popup]')) closePostPopups(null);
                });

                document.addEventListener('keydown', (event) => {
                    if (event.key === 'Escape') closePostPopups(null);
                });

                document.addEventListener('input', (event) => {
                    const marker = event.target.closest && event.target.closest('[data-hb-post-slug-input]');
                    const input = marker ? hbSlugInputEl(marker) : null;
                    if (!marker || !input || event.target !== input) return;
                    hbSlugMarkers().forEach((other) => {
                        if (other === marker) return;
                        const otherInput = hbSlugInputEl(other);
                        if (otherInput) otherInput.value = input.value;
                    });
                    updateSlugRowText(input.value);
                });
                document.addEventListener('change', (event) => {
                    const marker = event.target.closest && event.target.closest('[data-hb-post-slug-input]');
                    const input = marker ? hbSlugInputEl(marker) : null;
                    if (marker && input && event.target === input) {
                        const committed = marker.dataset.hbCurrentSlug || '';
                        const typed = input.value.trim();
                        hbSlugMarkers().forEach((other) => {
                            const otherInput = hbSlugInputEl(other);
                            if (otherInput) otherInput.value = typed;
                        });
                        updateSlugRowText(typed);
                        setRowPending(document.querySelector('[data-hb-post-popup-trigger="slug"]'), typed !== committed, 'hbSlugPendingHint', typed);
                        document.dispatchEvent(new CustomEvent('hb:post-slug-change', {
                            detail: { slug: typed === committed ? null : typed },
                        }));
                        return;
                    }

                    if (!event.target.matches('[data-hb-dtp-value]')) return;
                    const pubRoot = event.target.closest('[data-hb-post-published-input]');
                    if (pubRoot) {
                        const value = pubRoot.__hbDatePicker.getValue();
                        const pubTrigger = document.querySelector('[data-hb-post-popup-trigger="publish"]');
                        const committed = pubTrigger ? (pubTrigger.dataset.hbCurrentPublishedAt || '') : '';
                        if (pubTrigger) pubTrigger.textContent = formatSummaryDate(value) || pubTrigger.dataset.hbImmediatelyLabel || 'Immediately';
                        setRowPending(pubTrigger, value !== committed, 'hbPublishPendingHint', value);
                        document.dispatchEvent(new CustomEvent('hb:post-published-at-change', {
                            detail: { publishedAt: value === committed ? null : value },
                        }));
                        return;
                    }
                    const schRoot = event.target.closest('[data-hb-post-schedule-input]');
                    if (schRoot) {
                        const value = schRoot.__hbDatePicker.getValue();
                        updateScheduleRowText(value);
                        document.dispatchEvent(new CustomEvent('hb:post-status-change', {
                            detail: { status: 'scheduled', scheduledAt: value },
                        }));
                    }
                });

                document.addEventListener('hb:post-id', () => {
                    document.querySelectorAll('[data-hb-post-popup-trigger]').forEach((trigger) => {
                        trigger.disabled = false;
                        trigger.removeAttribute('title');
                    });
                    hbSlugMarkers().forEach((marker) => {
                        const input = hbSlugInputEl(marker);
                        if (input) input.disabled = false;
                    });
                });
            })();
        </script>
        @endonce
