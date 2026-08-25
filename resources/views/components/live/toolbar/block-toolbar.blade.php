@props(['supports' => [], 'richText' => true, 'blockType' => 'Text', 'activeFormats' => ['bold'], 'themeTokens' => []])
@php
    $has = fn ($key) => \Illuminate\Support\Arr::get($supports, $key, null) !== null
        && \Illuminate\Support\Arr::get($supports, $key) !== false;
@endphp
<div {{ $attributes->merge(['class' => 'hb-tb']) }} data-hb-toolbar role="toolbar" aria-label="Block toolbar"
    data-hb-save-title="{{ __('heisenberg::editor.patterns.save_dialog_title') }}"
    data-hb-save-ph="{{ __('heisenberg::editor.patterns.save_dialog_placeholder') }}"
    data-hb-save-ok="{{ __('heisenberg::editor.patterns.save_dialog_save') }}"
    data-hb-save-cancel="{{ __('heisenberg::editor.patterns.save_dialog_cancel') }}"
    data-hb-save-saving="{{ __('heisenberg::editor.patterns.save_dialog_saving') }}"
    data-hb-save-error-required="{{ __('heisenberg::editor.patterns.name_required') }}"
    data-hb-pattern-delete-confirm="{{ __('heisenberg::editor.patterns.delete_confirm') }}">
    <x-live.toolbar.groups.handle :block-type="$blockType" />

    @if ($richText)
        <span class="hb-tb__sep"></span>
        <x-live.toolbar.groups.format :active="$activeFormats" />
    @endif

    <span class="hb-tb__sep"></span>
    <x-live.toolbar.groups.style :has-color="$has('color')" :has-align="$has('align')" />

    <span class="hb-tb__sep"></span>
    <x-live.toolbar.groups.action :rich-text="$richText" />

    <div class="hb-tb__pop" data-tb-pop="type" hidden>
        <x-live.toolbar.type-menu :selected="$blockType" />
    </div>
    <div class="hb-tb__pop" data-tb-pop="align" hidden>
        <x-live.toolbar.align-menu />
    </div>
    <div class="hb-tb__pop" data-tb-pop="color" hidden>
        <x-live.toolbar.color-menu :tokens="$themeTokens" />
    </div>
    <div class="hb-tb__pop" data-tb-pop="more" hidden>
        <x-live.toolbar.more-menu />
    </div>
</div>
@once
<script>
    (() => {
        const FORMAT_EXEC = { bold: 'bold', italic: 'italic', underline: 'underline', strikethrough: 'strikeThrough' };

        function currentBlock() {
            if (!window.hbEditor) return null;
            const id = window.hbEditor.getSelectedId();
            if (!id) return null;
            const model = window.hbEditor.getModel(id);
            if (!model) return null;
            return { id: id, model: model, contract: window.hbEditor.getContract(model.name) };
        }

        function editableInBlock() {
            const ctx = currentBlock();
            if (!ctx) return null;
            const blk = document.querySelector('.hb-blk[data-block="' + ctx.id + '"]');
            return blk ? blk.querySelector('.hb-ce[data-hb-rt]') : null;
        }

        function ensureEditableFocus() {
            const active = document.activeElement;
            if (active && active.classList && active.classList.contains('hb-ce')) return active;
            const ce = editableInBlock();
            if (ce) ce.focus();
            return ce;
        }

        function inlineAncestor(tag) {
            const sel = window.getSelection();
            if (!sel || !sel.anchorNode) return null;
            let node = sel.anchorNode.nodeType === 3 ? sel.anchorNode.parentNode : sel.anchorNode;
            while (node && node.nodeType === 1) {
                if (node.classList && node.classList.contains('hb-ce')) return null;
                if (node.tagName === tag) return node;
                node = node.parentNode;
            }
            return null;
        }

        function fireEditableInput() {
            const ce = editableInBlock();
            if (ce) ce.dispatchEvent(new InputEvent('input', { bubbles: true }));
        }

        function toggleInlineCode() {
            const existing = inlineAncestor('CODE');
            if (existing) {
                const parent = existing.parentNode;
                while (existing.firstChild) parent.insertBefore(existing.firstChild, existing);
                parent.removeChild(existing);
                fireEditableInput();
                return;
            }
            const sel = window.getSelection();
            if (!sel || sel.rangeCount === 0 || sel.isCollapsed) return;
            const range = sel.getRangeAt(0);
            const code = document.createElement('code');
            try {
                range.surroundContents(code);
            } catch (err) {
                code.appendChild(range.extractContents());
                range.insertNode(code);
            }
            sel.removeAllRanges();
            const after = document.createRange();
            after.selectNodeContents(code);
            sel.addRange(after);
            fireEditableInput();
        }

        function syncFormatStates(tb) {
            tb.querySelectorAll('[data-tb-format]').forEach((btn) => {
                const key = btn.dataset.tbFormat;
                let on = false;
                if (key === 'code') {
                    on = !!inlineAncestor('CODE');
                } else if (FORMAT_EXEC[key]) {
                    try { on = document.queryCommandState(FORMAT_EXEC[key]); } catch (err) { on = false; }
                }
                btn.setAttribute('aria-pressed', on ? 'true' : 'false');
                btn.classList.toggle('hb-tb__btn--on', on);
            });
        }
        let __hbTbSyncTimer = null;
        document.addEventListener('selectionchange', () => {
            if (__hbTbSyncTimer) clearTimeout(__hbTbSyncTimer);
            __hbTbSyncTimer = setTimeout(() => {
                document.querySelectorAll('[data-hb-toolbar]').forEach(syncFormatStates);
            }, 80);
        });

        function openSaveBlockDialog(tb, ctx) {
            if (!ctx) return;
            const root = document.querySelector('[data-hb-panel-cb]');
            const url = root ? root.getAttribute('data-hb-patterns-store-url') || '' : '';
            if (!url) return;
            closeAll();
            const model = ctx.model;
            const rect = tb.getBoundingClientRect();
            const pop = document.createElement('div');
            pop.className = 'hb-pop hb-tb-savepop';
            pop.innerHTML = ''
                + '<div class="hb-tb-savepop__title">' + (tb.dataset.hbSaveTitle || 'Save as block') + '</div>'
                + '<input type="text" maxlength="120" placeholder="' + (tb.dataset.hbSavePh || '') + '">'
                + '<div class="hb-tb-savepop__error" hidden></div>'
                + '<div class="hb-tb-savepop__actions">'
                + '<button type="button" data-save-cancel>' + (tb.dataset.hbSaveCancel || 'Cancel') + '</button>'
                + '<button type="button" data-save-ok>' + (tb.dataset.hbSaveOk || 'Save') + '</button>'
                + '</div>';
            pop.style.left = Math.max(8, Math.min(window.innerWidth - 280, rect.left)) + 'px';
            pop.style.top = (rect.bottom + 6) + 'px';
            document.body.appendChild(pop);
            const input = pop.querySelector('input');
            const okBtn = pop.querySelector('[data-save-ok]');
            const cancelBtn = pop.querySelector('[data-save-cancel]');
            const errEl = pop.querySelector('.hb-tb-savepop__error');
            input.value = '';
            const cleanup = () => {
                pop.remove();
                document.removeEventListener('mousedown', onOutside, true);
                document.removeEventListener('keydown', onKey, true);
            };
            const onOutside = (e) => { if (!pop.contains(e.target) && !tb.contains(e.target)) cleanup(); };
            const onKey = (e) => { if (e.key === 'Escape') { e.preventDefault(); cleanup(); } else if (e.key === 'Enter') { e.preventDefault(); doSave(); } };
            const doSave = () => {
                const name = input.value.trim();
                if (!name) {
                    errEl.hidden = false;
                    errEl.textContent = tb.dataset.hbSaveErrorRequired || 'Give the block a name.';
                    input.focus();
                    return;
                }
                okBtn.disabled = true;
                okBtn.textContent = tb.dataset.hbSaveSaving || 'Saving…';
                errEl.hidden = true;
                const csrf = document.querySelector('meta[name="csrf-token"]');
                const headers = { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' };
                if (csrf && csrf.content) headers['X-CSRF-TOKEN'] = csrf.content;
                fetch(url, { method: 'POST', headers: headers, body: JSON.stringify({ name: name, blocks: [model] }) })
                    .then(async (r) => {
                        const data = await r.json().catch(() => null);
                        if (!r.ok || !data || data.saved !== true) {
                            const msg = (data && data.errors && (data.errors.name || data.errors.blocks)) || 'Could not save.';
                            errEl.hidden = false;
                            errEl.textContent = Array.isArray(msg) ? msg.join(' ') : String(msg);
                            okBtn.disabled = false;
                            okBtn.textContent = tb.dataset.hbSaveOk || 'Save';
                            return;
                        }
                        cleanup();
                        document.dispatchEvent(new CustomEvent('hb:patterns-changed', { detail: { pattern: data.pattern } }));
                    })
                    .catch(() => {
                        errEl.hidden = false;
                        errEl.textContent = 'Network error.';
                        okBtn.disabled = false;
                        okBtn.textContent = tb.dataset.hbSaveOk || 'Save';
                    });
            };
            cancelBtn.addEventListener('click', cleanup);
            okBtn.addEventListener('click', doSave);
            document.addEventListener('mousedown', onOutside, true);
            document.addEventListener('keydown', onKey, true);
            setTimeout(() => input.focus(), 0);
        }

        const boot = () => document.querySelectorAll('[data-hb-toolbar]').forEach((tb) => {
            if (tb.__hbTb) return; tb.__hbTb = true;

            let closeLink = null;

            const closeAll = () => {
                tb.querySelectorAll('[data-tb-pop]').forEach((p) => { p.hidden = true; });
                tb.querySelectorAll('[data-tb-popover]').forEach((b) => {
                    if (tb.querySelector('[data-tb-pop="' + b.dataset.tbPopover + '"]')) b.setAttribute('aria-expanded', 'false');
                });
                if (closeLink) { closeLink(); closeLink = null; }
            };

            tb.addEventListener('mousedown', (e) => {
                if (e.target.closest('[data-tb-format], [data-tb-popover="link"]')) e.preventDefault();
            });

            tb.querySelectorAll('[data-tb-format]').forEach((btn) => btn.addEventListener('click', () => {
                const key = btn.dataset.tbFormat;
                ensureEditableFocus();
                if (key === 'code') {
                    toggleInlineCode();
                } else if (FORMAT_EXEC[key]) {
                    try { document.execCommand(FORMAT_EXEC[key]); } catch (err) { }
                }
                syncFormatStates(tb);
            }));

            tb.querySelectorAll('[data-tb-popover="link"]').forEach((btn) => btn.addEventListener('click', () => {
                const sel = window.getSelection();
                if (!sel || sel.rangeCount === 0 || sel.isCollapsed) return;
                closeAll();
                const liveRange = sel.getRangeAt(0);
                const range = liveRange.cloneRange();
                const rect = liveRange.getBoundingClientRect();
                const pop = document.createElement('div');
                pop.className = 'hb-pop hb-tb-linkpop';
                pop.innerHTML = '<input type="text" placeholder="Paste or type a link…"><button type="button">Apply</button>';
                pop.style.left = rect.left + 'px';
                pop.style.top = (rect.bottom + 6) + 'px';
                document.body.appendChild(pop);
                btn.setAttribute('aria-expanded', 'true');
                const input = pop.querySelector('input');
                const applyBtn = pop.querySelector('button');
                const cleanup = () => {
                    pop.remove();
                    btn.setAttribute('aria-expanded', 'false');
                    document.removeEventListener('mousedown', onOutside, true);
                    document.removeEventListener('keydown', onKey, true);
                    if (closeLink === cleanup) closeLink = null;
                };
                const doApply = () => {
                    const raw = input.value.trim();
                    if (raw) {
                        let url = raw;
                        if (!/^([a-z][a-z0-9+.-]*:|\/\/)/i.test(url)) url = 'https://' + url;
                        const ce = editableInBlock();
                        if (ce) ce.focus();
                        const s = window.getSelection();
                        s.removeAllRanges();
                        s.addRange(range);
                        try { document.execCommand('createLink', false, url); } catch (err) { }
                    }
                    cleanup();
                };
                const onOutside = (e) => { if (!pop.contains(e.target)) cleanup(); };
                const onKey = (e) => { if (e.key === 'Escape') { e.preventDefault(); cleanup(); } };
                document.addEventListener('mousedown', onOutside, true);
                document.addEventListener('keydown', onKey, true);
                applyBtn.addEventListener('click', doApply);
                input.addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); doApply(); } });
                closeLink = cleanup;
                setTimeout(() => input.focus(), 0);
            }));

            tb.querySelectorAll('[data-tb-action]').forEach((btn) => btn.addEventListener('click', () => {
                const action = btn.dataset.tbAction;
                const ctx = currentBlock();
                if (ctx && window.hbEditor && (action === 'move-up' || action === 'move-down')) {
                    if (window.hbEditor.moveById) {
                        window.hbEditor.moveById(ctx.id, action === 'move-up' ? -1 : 1);
                    } else {
                        const i = window.hbEditor.indexOf(ctx.id);
                        const n = window.hbEditor.getDoc().blocks.length;
                        const j = action === 'move-up' ? i - 1 : i + 1;
                        if (i !== -1 && j >= 0 && j < n) window.hbEditor.moveBlock(i, j);
                    }
                }
                if (ctx && window.hbEditor && action === 'select-parent') {
                    const parent = window.hbEditor.parentIdOf?.(ctx.id);
                    if (parent) window.hbEditor.selectById(parent);
                }
                if (action === 'save') {
                    openSaveBlockDialog(tb, ctx);
                }
            }));

            tb.addEventListener('click', (e) => {
                const item = e.target.closest('[data-more-action]');
                if (!item) return;
                closeAll();
                const ctx = currentBlock();
                if (!ctx || !window.hbEditor) return;
                if (item.dataset.moreAction === 'delete') {
                    window.hbEditor.removeBlock(ctx.id);
                    return;
                }
                if (item.dataset.moreAction === 'duplicate') {
                    if (window.hbEditor.duplicateBlock) {
                        window.hbEditor.duplicateBlock(ctx.id);
                        return;
                    }
                    const copy = JSON.parse(JSON.stringify({
                        attributes: ctx.model.attributes || {},
                        supports: ctx.model.supports || {},
                    }));
                    const el = window.hbEditor.insertBlock(ctx.model.name, window.hbEditor.indexOf(ctx.id) + 1);
                    if (!el) return;
                    const nid = el.getAttribute('data-block');
                    Object.keys(copy.attributes).forEach((k) => window.hbEditor.setAttribute(nid, k, copy.attributes[k]));
                    const walk = (node, prefix) => Object.keys(node || {}).forEach((k) => {
                        const path = prefix ? prefix + '.' + k : k;
                        const v = node[k];
                        if (v !== null && typeof v === 'object' && !Array.isArray(v)) walk(v, path);
                        else window.hbEditor.setSupport(nid, path, v);
                    });
                    walk(copy.supports, '');
                    window.hbEditor.selectById(nid);
                }
            });

            tb.querySelectorAll('[data-tb-popover]').forEach((btn) => btn.addEventListener('click', (e) => {
                e.stopPropagation();
                const name = btn.dataset.tbPopover;
                if (name === 'link') return;
                const pop = tb.querySelector('[data-tb-pop="' + name + '"]');
                const willOpen = pop ? pop.hidden : false;
                closeAll();
                if (pop && willOpen) { pop.style.left = btn.offsetLeft + 'px'; pop.hidden = false; btn.setAttribute('aria-expanded', 'true'); }
            }));

            tb.addEventListener('blocktype', (e) => {
                closeAll();
                const ctx = currentBlock();
                if (ctx && window.hbEditor && e.detail && e.detail.level != null) {
                    window.hbEditor.setAttribute(ctx.id, 'level', e.detail.level);
                }
            });

            tb.addEventListener('alignselect', (e) => {
                closeAll();
                const ctx = currentBlock();
                if (ctx && window.hbEditor && e.detail) window.hbEditor.setSupport(ctx.id, 'align', e.detail.value);
            });

            tb.addEventListener('colorselect', (e) => {
                closeAll();
                const ctx = currentBlock();
                if (ctx && window.hbEditor && e.detail) {
                    window.hbEditor.setSupport(ctx.id, 'color.text', e.detail.value);
                    const swatchBtn = tb.querySelector('[data-tb-popover="color"]');
                    if (swatchBtn) swatchBtn.style.setProperty('--hb-tb-color', e.detail.value || 'var(--ink)');
                }
            });

            tb.addEventListener('keydown', (e) => {
                if (e.key !== 'Escape') return;
                const anyOpen = [...tb.querySelectorAll('[data-tb-pop]')].some((p) => !p.hidden) || !!closeLink;
                if (anyOpen) { e.stopPropagation(); closeAll(); }
            });

            document.addEventListener('click', (e) => { if (!tb.contains(e.target)) closeAll(); });

            document.addEventListener('hb:block-selected', (e) => {
                const swatchBtn = tb.querySelector('[data-tb-popover="color"]');
                if (swatchBtn) {
                    const color = e.detail && e.detail.model && e.detail.model.supports ? e.detail.model.supports.color : null;
                    swatchBtn.style.setProperty('--hb-tb-color', (color && color.text) || 'var(--ink)');
                }
                const pill = tb.querySelector('.hb-tb__pill--type');
                if (pill) {
                    const name = (e.detail && e.detail.name) || '';
                    let shown = null;
                    pill.querySelectorAll('[data-tb-type-icon], [data-tb-type-icon-default]').forEach((s) => {
                        const match = s.getAttribute('data-tb-type-icon') === name && name !== '';
                        s.hidden = !match;
                        if (match) shown = s;
                    });
                    if (!shown) {
                        const def = pill.querySelector('[data-tb-type-icon-default]');
                        if (def) { def.hidden = false; shown = def; }
                    }
                    const menuIc = tb.querySelector('[data-type-current] .ic');
                    if (menuIc && shown) menuIc.innerHTML = shown.innerHTML;
                    const blockTitle = e.detail && e.detail.contract && e.detail.contract.title ? e.detail.contract.title
                        : name || '';
                    if (blockTitle) pill.setAttribute('aria-label', 'Block type: ' + blockTitle);
                }
            });
        });
        if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot, { once: true });
        else boot();
        document.addEventListener('hb:refresh', boot);
    })();
</script>
@endonce
