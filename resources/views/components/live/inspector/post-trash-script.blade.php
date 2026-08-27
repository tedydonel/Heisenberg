@once('hb-post-trash')
<script nonce="{{ heisenberg_csp_nonce() }}">
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
