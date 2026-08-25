        @once
        <style>
            .hb-post-dropzone { cursor: pointer; padding: 0; font: inherit; appearance: none; -webkit-appearance: none; }
            .hb-post-dropzone:focus-visible { outline: 2px solid var(--hb-border-focus); outline-offset: 2px; }
            .hb-post-dropzone[hidden] { display: none; }
            .hb-post-dropzone-preview { position: relative; height: 94px; width: 100%; border-radius: var(--hb-radius-md, 5px); overflow: hidden; background: var(--hb-bg-subtle); border: 1px solid var(--hb-border-strong); }
            .hb-post-dropzone-preview[hidden] { display: none; }
            .hb-post-dropzone-preview__img { width: 100%; height: 100%; object-fit: cover; display: block; }
            .hb-post-dropzone-preview__actions { position: absolute; top: 6px; right: 6px; display: flex; gap: 4px; }
            .hb-post-dropzone-preview__btn { display: inline-flex; align-items: center; justify-content: center; width: 24px; height: 24px; border: 0; border-radius: 4px; background: rgba(10, 10, 10, .55); color: #fff; cursor: pointer; }
            .hb-post-dropzone-preview__btn:hover { background: rgba(10, 10, 10, .75); }
            .hb-post-dropzone-preview__btn--danger:hover { background: var(--hb-danger); }
        </style>
        <script>
            (() => {
                const csrf = () => (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
                const boot = () => {
                    document.querySelectorAll('[data-hb-featured-field]').forEach((field) => {
                        if (field.__hbFeatured) return;
                        field.__hbFeatured = true;

                        const trigger = field.querySelector('[data-hb-featured-trigger]');
                        const preview = field.querySelector('[data-hb-featured-preview]');
                        const img = field.querySelector('[data-hb-featured-img]');
                        const replaceBtn = field.querySelector('[data-hb-featured-replace]');
                        const removeBtn = field.querySelector('[data-hb-featured-remove]');
                        const dialog = field.querySelector('[data-hb-featured-dialog]');
                        const idInput = field.querySelector('[data-hb-featured-image-id]');
                        const urlInput = field.querySelector('[data-hb-featured-image-url]');
                        if (!trigger || !preview || !img) return;

                        const updateUrlTemplate = field.dataset.hbFeaturedImageUpdateUrlTemplate || '';
                        let postId = document.querySelector('[data-hb-post-id]')?.dataset?.hbPostId || '';
                        let pending = null;
                        const consume = () => {
                            if (pending && postId) {
                                const file = pending; pending = null;
                                requestSave(file);
                            }
                        };
                        document.addEventListener('hb:post-id', (event) => {
                            if (event && event.detail && event.detail.id != null) {
                                postId = String(event.detail.id);
                                consume();
                            }
                        });

                        const requestSave = (file) => {
                            if (!updateUrlTemplate) return;
                            if (!postId) { pending = file; return; }
                            const url = updateUrlTemplate.replace('__ID__', encodeURIComponent(postId));
                            const payload = JSON.stringify({ featured_image_id: file && file.id != null ? file.id : null });
                            const headers = { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' };
                            const token = csrf();
                            if (token) headers['X-CSRF-TOKEN'] = token;
                            fetch(url, { method: 'PUT', headers, body: payload, credentials: 'same-origin' })
                                .catch(() => { });
                        };

                        const applySelection = (file, opts) => {
                            opts = opts || {};
                            const url = file ? (file.thumbnail_url || file.url || '') : '';
                            if (file && url) {
                                img.src = url;
                                img.alt = file.original_name || 'Featured image';
                                preview.hidden = false;
                                trigger.hidden = true;
                                if (idInput) idInput.value = file.id != null ? String(file.id) : '';
                                if (urlInput) urlInput.value = url;
                            } else {
                                img.removeAttribute('src');
                                preview.hidden = true;
                                trigger.hidden = false;
                                if (idInput) idInput.value = '';
                                if (urlInput) urlInput.value = '';
                                if (opts.focusTrigger) trigger.focus();
                            }
                            requestSave(file && url ? file : null);

                            field.dispatchEvent(new CustomEvent('hb:featured-image-change', {
                                bubbles: true,
                                detail: file && url ? { id: file.id, url } : null,
                            }));
                        };

                        const open = (returnEl) => { if (dialog && typeof dialog.hbOpen === 'function') dialog.hbOpen(returnEl); };

                        trigger.addEventListener('click', () => open(trigger));
                        replaceBtn?.addEventListener('click', () => open(replaceBtn));
                        removeBtn?.addEventListener('click', () => applySelection(null, { focusTrigger: true }));
                        dialog?.addEventListener('hb:media-select', (event) => applySelection(event.detail));

                    });
                };
                if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot, { once: true });
                else boot();
                document.addEventListener('hb:refresh', boot);
            })();
        </script>
        @endonce
