        @once('hb-post-meta-live')
        <script>
            (() => {
                const statusLabel = (labels, status) => (labels && labels[status]) || status;
                const readJson = (el, key, fallback) => {
                    try { return JSON.parse((el && el.dataset[key]) || 'null') || fallback; } catch (e) { return fallback; }
                };
                const pad2 = (n) => String(n).padStart(2, '0');
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
                        const slugStillPendingElsewhere = slugTrigger && slugTrigger.dataset.hbPending === 'true' && post.slug !== slugTrigger.dataset.hbPendingValue;
                        if (!slugStillPendingElsewhere) applyConfirmedSlug(post.slug);
                    }
                    if ('published_at' in post) {
                        const pubTrigger = document.querySelector('[data-hb-post-popup-trigger="publish"]');
                        const pubValue = post.published_at || null;
                        const pubStillPendingElsewhere = pubTrigger && pubTrigger.dataset.hbPending === 'true' && (pubValue || '') !== (pubTrigger.dataset.hbPendingValue || '');
                        if (!pubStillPendingElsewhere) applyConfirmedPublishedAt(pubValue);
                    }
                    if (post.status) {
                        const trigger = document.querySelector('[data-hb-post-status]');
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
