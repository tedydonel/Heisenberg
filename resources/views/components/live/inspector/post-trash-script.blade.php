{{-- Wiring for the "Move to trash" row in post-title-summary.blade.php (data-hb-post-trash).
     Two-step confirm — first click arms (label swaps, a Cancel button appears), second click
     fires the DELETE — same pattern live/ai/ai-history-dialog.blade.php uses for its own delete
     button, never window.confirm(). On success the post no longer exists, so the browser is sent
     to a blank /editor rather than left showing a dead document; on failure the error goes
     through the SAME hb:save-state channel every other save/tool failure uses (the footer's
     save-status pill renders it — see footer.blade.php's own docblock). --}}
@once('hb-post-trash')
<script>
    (() => {
        const csrf = () => document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

        const boot = () => {
            document.querySelectorAll('[data-hb-post-trash]').forEach((btn) => {
                if (btn.__hbTrash) return;
                btn.__hbTrash = true;

                const row = btn.closest('[data-hb-post-trash-row]');
                const cancelBtn = row ? row.querySelector('[data-hb-post-trash-cancel]') : null;
                const labelEl = btn.querySelector('[data-hb-post-trash-label]');
                const restLabel = labelEl ? labelEl.textContent : '';
                const armedLabel = btn.dataset.hbConfirmLabel || restLabel;
                let armed = false;

                const disarm = () => {
                    armed = false;
                    btn.classList.remove('is-armed');
                    if (labelEl) labelEl.textContent = restLabel;
                    if (cancelBtn) cancelBtn.hidden = true;
                };

                btn.addEventListener('click', () => {
                    if (btn.disabled) return;

                    if (!armed) {
                        armed = true;
                        btn.classList.add('is-armed');
                        if (labelEl) labelEl.textContent = armedLabel;
                        if (cancelBtn) cancelBtn.hidden = false;
                        return;
                    }

                    const postId = btn.dataset.hbPostId;
                    const template = btn.dataset.hbTrashUrlTemplate;
                    if (!postId || !template) return;

                    btn.disabled = true;
                    document.dispatchEvent(new CustomEvent('hb:save-state', { detail: { state: 'saving' } }));

                    window.fetch(template.replace('__ID__', encodeURIComponent(postId)), {
                        method: 'DELETE',
                        headers: {
                            'Accept': 'application/json',
                            'X-CSRF-TOKEN': csrf(),
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                        credentials: 'same-origin',
                    })
                        .then((r) => r.json().catch(() => ({})).then((data) => ({ ok: r.ok, status: r.status, data: data })))
                        .then((res) => {
                            if (res.ok) {
                                window.location.href = btn.dataset.hbEditorIndexUrl || '/editor';
                                return;
                            }
                            btn.disabled = false;
                            disarm();
                            document.dispatchEvent(new CustomEvent('hb:save-state', {
                                detail: {
                                    state: 'error',
                                    message: 'HTTP ' + res.status + ((res.data && res.data.message) ? ' — ' + res.data.message : ''),
                                },
                            }));
                        })
                        .catch(() => {
                            btn.disabled = false;
                            disarm();
                            document.dispatchEvent(new CustomEvent('hb:save-state', {
                                detail: { state: 'error', message: btn.dataset.hbMsgNetwork || '' },
                            }));
                        });
                });

                if (cancelBtn) cancelBtn.addEventListener('click', disarm);
            });
        };
        if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot, { once: true });
        else boot();
        document.addEventListener('hb:refresh', boot);

        // A new document adopts a real id on its first save (topbar.blade.php's hb:post-id) —
        // same enable-and-learn-the-id posture as revisions-dialog.blade.php's own row.
        if (!document.__hbPostTrashPostId) {
            document.__hbPostTrashPostId = true;
            document.addEventListener('hb:post-id', (event) => {
                const id = event.detail && event.detail.id != null ? String(event.detail.id) : '';
                if (!id) return;
                document.querySelectorAll('[data-hb-post-trash]').forEach((btn) => {
                    btn.dataset.hbPostId = id;
                    btn.disabled = false;
                    btn.removeAttribute('title');
                });
            });
        }
    })();
</script>
@endonce
