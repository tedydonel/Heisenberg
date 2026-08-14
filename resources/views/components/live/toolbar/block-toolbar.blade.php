{{-- live/toolbar/block-toolbar — Composed of gated group
     components (like the inspector's sections): the handle + more groups are always present,
     Format loads for rich-text blocks, and text-colour / align load per supports.color /
     supports.align. So a text block gets the full bar; an image only its handle / save / more.

     Live, against window.hbEditor (see block-runtime.blade.php):
       - Format (bold/italic/underline/strikethrough/code/link) apply to the current selection
         inside the block's .hb-ce via document.execCommand.
       - Align/Color write through hbEditor.setSupport (their popovers are the two containers
         below); the type pill rewrites a heading's `level` via hbEditor.setAttribute.
       - move-up/move-down call hbEditor.moveBlock; drag is already wired natively by
         block-runtime's wireCanvasBlockDrag (pointerdown on .hb-tb__btn--drag). The ⋯ More menu
         (duplicate/delete) is live; select-parent shows once real nesting exists; save-as-block
         still has no backing runtime capability — see the boot() comments below.

     Chrome sits on --hb-editing (square); the white icons/overlays are toolbar mechanics,
     not theme colours. --}}
@props(['supports' => [], 'richText' => true, 'blockType' => 'Text', 'activeFormats' => ['bold'], 'themeTokens' => []])
@php
    $has = fn ($key) => \Illuminate\Support\Arr::get($supports, $key, null) !== null
        && \Illuminate\Support\Arr::get($supports, $key) !== false;
@endphp
<div {{ $attributes->merge(['class' => 'hb-tb']) }} data-hb-toolbar role="toolbar" aria-label="Block toolbar">
    <x-live.toolbar.groups.handle :block-type="$blockType" />

    @if ($richText)
        <span class="hb-tb__sep"></span>
        <x-live.toolbar.groups.format :active="$activeFormats" />
    @endif

    <span class="hb-tb__sep"></span>
    <x-live.toolbar.groups.style :has-color="$has('color')" :has-align="$has('align')" />

    <span class="hb-tb__sep"></span>
    <x-live.toolbar.groups.action :rich-text="$richText" />

    {{-- anchored popovers: the type switcher, align, text colour, and the ⋯ more menu.
         link builds its own popover from script. --}}
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

        // Resolve the block the toolbar currently belongs to via the documented runtime API only —
        // never via DOM ancestry, since the toolbar has none to speak of: it floats in the canvas
        // layer over whichever block is selected (block-runtime's dockToolbar/positionToolbar) and
        // is stowed in a detached holder in between.
        function currentBlock() {
            if (!window.hbEditor) return null;
            const id = window.hbEditor.getSelectedId();
            if (!id) return null;
            const model = window.hbEditor.getModel(id);
            if (!model) return null;
            return { id: id, model: model, contract: window.hbEditor.getContract(model.name) };
        }

        // The selected block's own rich-text editable, resolved fresh every time (never cached) —
        // setAttribute/setSupport rebuild the block's DOM on every write, so a cached reference
        // would go stale the moment any control touches the model.
        function editableInBlock() {
            const ctx = currentBlock();
            if (!ctx) return null;
            const blk = document.querySelector('.hb-blk[data-block="' + ctx.id + '"]');
            return blk ? blk.querySelector('.hb-ce[data-hb-rt]') : null;
        }

        // Whatever already has focus wins if it's the block's own editable (preserves the user's
        // real caret/selection); otherwise there is nothing for execCommand to act on yet, so focus
        // the editable directly (covers selecting a block without ever having clicked its text).
        function ensureEditableFocus() {
            const active = document.activeElement;
            if (active && active.classList && active.classList.contains('hb-ce')) return active;
            const ce = editableInBlock();
            if (ce) ce.focus();
            return ce;
        }

        // Nearest ancestor of the caret matching a tag, bounded by the editable.
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

        // execCommand mutations inside .hb-ce fire `input` natively; only manual DOM surgery
        // (the <code> wrap/unwrap below) needs this synthetic nudge so block-runtime's delegated
        // `input` listener picks the change up and writes it back to the model.
        function fireEditableInput() {
            const ce = editableInBlock();
            if (ce) ce.dispatchEvent(new InputEvent('input', { bubbles: true }));
        }

        // Toggle an inline <code> wrapper around the selection — there is no execCommand for this.
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
            if (!sel || sel.rangeCount === 0 || sel.isCollapsed) return; // nothing to wrap
            const range = sel.getRangeAt(0);
            const code = document.createElement('code');
            try {
                range.surroundContents(code);
            } catch (err) {
                // Selections that cross element boundaries can't be surrounded directly.
                code.appendChild(range.extractContents());
                range.insertNode(code);
            }
            sel.removeAllRanges();
            const after = document.createRange();
            after.selectNodeContents(code);
            sel.addRange(after);
            fireEditableInput();
        }

        // Keeps the format buttons' pressed state tracking the live selection (mirrors the
        // builder's syncToolbarFormatStates) — a button reads "on" the moment the caret lands
        // inside bold/italic/underline/strikethrough/code text, not only right after it's clicked.
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

        const boot = () => document.querySelectorAll('[data-hb-toolbar]').forEach((tb) => {
            if (tb.__hbTb) return; tb.__hbTb = true;

            let closeLink = null; // set while the dynamically-created link popover is open

            const closeAll = () => {
                tb.querySelectorAll('[data-tb-pop]').forEach((p) => { p.hidden = true; });
                tb.querySelectorAll('[data-tb-popover]').forEach((b) => {
                    if (tb.querySelector('[data-tb-pop="' + b.dataset.tbPopover + '"]')) b.setAttribute('aria-expanded', 'false');
                });
                if (closeLink) { closeLink(); closeLink = null; }
            };

            // Blocking mousedown's default keeps the live text selection alive through the
            // click — browsers can collapse it before the click handler ever runs.
            tb.addEventListener('mousedown', (e) => {
                if (e.target.closest('[data-tb-format], [data-tb-popover="link"]')) e.preventDefault();
            });

            tb.querySelectorAll('[data-tb-format]').forEach((btn) => btn.addEventListener('click', () => {
                const key = btn.dataset.tbFormat;
                ensureEditableFocus();
                if (key === 'code') {
                    toggleInlineCode();
                } else if (FORMAT_EXEC[key]) {
                    try { document.execCommand(FORMAT_EXEC[key]); } catch (err) { /* unsupported in this engine */ }
                }
                syncFormatStates(tb);
            }));

            // Link — a tiny popover built on demand, positioned at the selection and cleaned up
            // on Apply/Escape/outside-click. The Range is cloned up front and restored right
            // before createLink, so focus moving into the popover's input never drops the selection.
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
                        try { document.execCommand('createLink', false, url); } catch (err) { /* unsupported */ }
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
                    // moveById is nesting-aware (moves within whichever siblings array the
                    // block lives in); the index pair path remains for older runtimes.
                    if (window.hbEditor.moveById) {
                        window.hbEditor.moveById(ctx.id, action === 'move-up' ? -1 : 1);
                    } else {
                        const i = window.hbEditor.indexOf(ctx.id);
                        const n = window.hbEditor.getDoc().blocks.length;
                        const j = action === 'move-up' ? i - 1 : i + 1;
                        if (i !== -1 && j >= 0 && j < n) window.hbEditor.moveBlock(i, j);
                    }
                }
                // `drag` is a pointer gesture block-runtime already owns end-to-end
                // (wireCanvasBlockDrag's pointerdown on .hb-tb__btn--drag calls preventDefault,
                // which suppresses the compatibility click this handler would otherwise see — there
                // is nothing left for a click handler to do for it).
                //
                // `select-parent` is unreachable today (gateToolbar hides it until blocks nest)
                // but written against the real API so it works the moment containers exist.
                // `save` has no runtime capability yet — a no-op until reusable blocks land.
                if (ctx && window.hbEditor && action === 'select-parent') {
                    const parent = window.hbEditor.parentIdOf?.(ctx.id);
                    if (parent) window.hbEditor.selectById(parent);
                }
            }));

            // ⋯ menu actions — everything through the public runtime API.
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
                    // duplicateBlock is the runtime's own deep clone: nesting-aware (clones
                    // innerBlocks with fresh ids) and lands as the next sibling. The manual
                    // copy path remains for older runtimes.
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
                if (name === 'link') return; // handled by its own listener above
                const pop = tb.querySelector('[data-tb-pop="' + name + '"]');
                const willOpen = pop ? pop.hidden : false;
                closeAll();
                if (pop && willOpen) { pop.style.left = btn.offsetLeft + 'px'; pop.hidden = false; btn.setAttribute('aria-expanded', 'true'); }
            }));

            // The type-menu dispatches this for a heading level pick — see type-menu.blade.php's
            // own header comment for why a genuine cross-contract "turn into" isn't wired here.
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

            // Escape closes whichever popover (static or the dynamic link one) is currently open —
            // matches ui/select.blade.php's Escape handling, the one other place in this codebase
            // that does this correctly.
            tb.addEventListener('keydown', (e) => {
                if (e.key !== 'Escape') return;
                const anyOpen = [...tb.querySelectorAll('[data-tb-pop]')].some((p) => !p.hidden) || !!closeLink;
                if (anyOpen) { e.stopPropagation(); closeAll(); }
            });

            document.addEventListener('click', (e) => { if (!tb.contains(e.target)) closeAll(); });

            // Preview the newly selected block's own text colour on the trigger's underline bar —
            // each block carries its own supports.color.text independently.
            document.addEventListener('hb:block-selected', (e) => {
                const swatchBtn = tb.querySelector('[data-tb-popover="color"]');
                if (!swatchBtn) return;
                const color = e.detail && e.detail.model && e.detail.model.supports ? e.detail.model.supports.color : null;
                swatchBtn.style.setProperty('--hb-tb-color', (color && color.text) || 'var(--ink)');
            });
        });
        if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot, { once: true });
        else boot();
        document.addEventListener('hb:refresh', boot);
    })();
</script>
@endonce
