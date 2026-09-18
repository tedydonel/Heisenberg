        @once
        <script nonce="{{ heisenberg_csp_nonce() }}">
            // Generic inspector media picker for block attributes (the block "settings"
            // sub-tab's media controls — e.g. the image block's `url`). Mirrors the
            // Featured Image field (featured-image-behavior.blade.php): a trigger opens
            // the media dialog (upload + library tabs), the picked file paints a small
            // preview and is written through window.hbEditor.setAttribute — the exact
            // write path every other attribute control uses — so canvas, code view and
            // save all see the change.
            (() => {
                const boot = () => {
                    document.querySelectorAll('[data-hb-media-field]').forEach((field) => {
                        if (field.__hbMediaField) return;
                        field.__hbMediaField = true;

                        const attr = field.getAttribute('data-hb-media-attribute') || '';
                        const trigger = field.querySelector('[data-hb-media-trigger]');
                        const removeBtn = field.querySelector('[data-hb-media-remove]');
                        const preview = field.querySelector('[data-hb-media-preview]');
                        const img = field.querySelector('[data-hb-media-img]');
                        const dialog = field.querySelector('[data-hb-media-dialog]');
                        if (!attr || !trigger || !dialog) return;

                        const readAttr = (model, key) => (window.hbEditor && window.hbEditor.readAttr)
                            ? window.hbEditor.readAttr(model, key)
                            : ((model && model.attributes) || {})[key];

                        const paint = (url, alt) => {
                            if (url) {
                                if (img) { img.src = url; img.alt = alt || ''; }
                                if (preview) preview.hidden = false;
                                trigger.hidden = true;
                            } else {
                                if (img) img.removeAttribute('src');
                                if (preview) preview.hidden = true;
                                trigger.hidden = false;
                            }
                        };

                        const currentUrl = (model) => String(readAttr(model, attr) ?? '');

                        const applySelection = (file) => {
                            // Consume the remembered selection — a later pick/remove
                            // must never write into a previously-edited block.
                            const id = field.__hbSelectedId || (window.hbEditor && window.hbEditor.getSelectedId && window.hbEditor.getSelectedId());
                            field.__hbSelectedId = null;
                            if (!id || !window.hbEditor) return;
                            const url = file ? (file.url || file.thumbnail_url || '') : '';
                            window.hbEditor.setAttribute(id, attr, url);
                            paint(url, file ? file.original_name : '');
                            if (removeBtn) removeBtn.hidden = !url;
                        };

                        trigger.addEventListener('click', () => {
                            // Remember which block the dialog is editing — the user's
                            // canvas selection must not drift between open and pick.
                            field.__hbSelectedId = window.hbEditor && window.hbEditor.getSelectedId ? window.hbEditor.getSelectedId() : null;
                            if (typeof dialog.hbOpen === 'function') dialog.hbOpen(trigger);
                        });
                        // Clicking the preview reopens the picker to swap the image
                        // (same affordance as the Featured Image preview's replace).
                        img?.addEventListener('click', () => {
                            if (trigger.hidden) trigger.click();
                        });
                        removeBtn?.addEventListener('click', () => applySelection(null));
                        dialog.addEventListener('hb:media-select', (event) => applySelection(event.detail));

                        // Keep the preview honest when the value changes from anywhere
                        // else (code view edits, AI writes, undo) — same refresh posture
                        // as script-controls-sync.
                        const syncFromModel = (model) => {
                            if (!model) return;
                            const url = currentUrl(model);
                            paint(url, '');
                            if (removeBtn) removeBtn.hidden = !url;
                        };
                        document.addEventListener('hb:block-selected', (event) => {
                            if ((event.detail || {}).name) syncFromModel(event.detail.model);
                        });
                        document.addEventListener('hb:block-updated', (event) => {
                            const detail = event.detail || {};
                            if (!detail.model || !window.hbEditor || window.hbEditor.getSelectedId() !== detail.id) return;
                            syncFromModel(detail.model);
                        });
                    });
                };
                if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot, { once: true });
                else boot();
                document.addEventListener('hb:refresh', boot);
            })();
        </script>
        @endonce
