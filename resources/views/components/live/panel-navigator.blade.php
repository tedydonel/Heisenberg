{{-- live/panel-navigator — the Navigator. Two flush tabs: List View (a flat list of the canvas
     blocks) and Outline (document stats + a heading tree). It lives in the left child panel and
     is opened by the topbar Layers button (data-hb-layers), swapping whatever panel was shown.

     List View rows come from window.hbEditor's doc model; the Outline reads the canvas DOM for
     the rendered title/stats/heading text. Selection + scroll-to-block and List View
     drag-to-reorder all work.

     Drag-to-reorder: each row's `.grab` grip (Pointer Events + setPointerCapture, no HTML5 DnD) is
     the handle — rows are plain buttons rebuilt wholesale by buildList() on every hb:blocks-changed
     (host.innerHTML replaced), so per-row listeners can't survive and never get attached; the
     pointerdown/keydown listeners below live on the panel root instead (delegation), which IS stable
     across rebuilds. The gesture itself never touches that rebuild machinery mid-drag — pointer
     capture lands on the grip, which stays alive for the whole gesture, and the model write
     (window.hbEditor.moveBlock) only happens on pointerup, after which the resulting
     hb:blocks-changed rebuild is exactly what should happen. The drop indicator (.is-drop-before /
     .is-drop-after) matches the canvas's flat insertion-line language (see block-runtime.blade.php).

     Keyboard reordering: with a row focused, Alt+ArrowUp / Alt+ArrowDown move it one position (same
     moveBlock commit), refocus the row at its new position after the rebuild, and announce the move
     via a visually-hidden aria-live region (.hb-nav__sr) — there is no mouse-only way to reorder. --}}
@props(['registry' => []])
@once
<style>
    .hb-nav { display: flex; flex-direction: column; width: 240px; height: 100%; background: var(--hb-bg); border-right: 1px solid var(--hb-border); flex: none; }
    .hb-nav__content { display: flex; flex-direction: column; flex: 1 1 auto; min-height: 0; overflow: hidden; }
    .hb-nav__content[hidden] { display: none; }
    .hb-nav__body { flex: 1 1 auto; min-height: 0; overflow: hidden; position: relative; }

    /* List View — 28px rows: 10px caret slot, 13px block icon, 12px label, hover grip. */
    .hb-nav-list { display: flex; flex-direction: column; padding: 4px 0 18px; }
    .hb-nav-row { display: flex; align-items: center; gap: 4px; width: 100%; height: 28px; padding: 0 8px; text-align: left; color: var(--hb-text-secondary); background: none; border: 0; position: relative; cursor: pointer; font-family: var(--hb-font-sans, Rubik, sans-serif); }
    .hb-nav-row:hover { background: var(--hb-surface-hover); color: var(--hb-text-primary); }
    .hb-nav-row.is-on { background: var(--hb-bg-muted); color: var(--hb-text-primary); }
    .hb-nav-row .twist { width: 10px; height: 10px; flex: none; }
    /* Container rows get a real caret in the twist slot; clicking it folds the
       subtree (rotated closed like every other disclosure in the editor). */
    .hb-nav-row .twist.has-kids { display: inline-flex; align-items: center; justify-content: center; color: var(--hb-text-muted); cursor: pointer; transition: transform .12s ease; }
    .hb-nav-row .twist.has-kids svg { width: 10px; height: 10px; }
    .hb-nav-row .twist.has-kids.is-closed { transform: rotate(-90deg); }
    .hb-nav-row .ic { width: 13px; height: 13px; flex: none; display: inline-flex; align-items: center; justify-content: center; color: inherit; }
    .hb-nav-row .ic svg { width: 13px; height: 13px; }
    .hb-nav-row .nm { flex: 1; min-width: 0; font-size: 12px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .hb-nav-row .grab { margin-left: auto; width: 16px; flex: none; display: inline-flex; align-items: center; justify-content: center; color: var(--hb-text-disabled); opacity: 0; transition: opacity .12s; cursor: grab; }
    .hb-nav-row .grab svg { width: 12px; height: 12px; }
    .hb-nav-row:hover .grab { opacity: 1; }
    .hb-nav-empty { padding: 40px 20px; text-align: center; color: var(--hb-text-disabled); font-size: 12px; line-height: 1.5; }

    /* Drag-to-reorder — dim the dragged row in place; a flat 2px line (no rounded pill, matching the
       canvas's own drop indicator) shows where it will land. */
    .hb-nav-row.is-dragging { opacity: .4; }
    .hb-nav-row.is-drop-before::before,
    .hb-nav-row.is-drop-after::after {
        content: ""; position: absolute; left: 8px; right: 8px; height: 2px;
        background: var(--hb-editing); pointer-events: none;
    }
    .hb-nav-row.is-drop-before::before { top: 0; }
    .hb-nav-row.is-drop-after::after { bottom: 0; }
    /* Visually-hidden live region — announces keyboard reorders (Alt+ArrowUp/Down) to screen readers. */
    .hb-nav__sr { position: absolute; width: 1px; height: 1px; margin: -1px; overflow: hidden; clip: rect(0, 0, 0, 0); white-space: nowrap; }

    /* Outline — stat rows, hairline divider, then heading rows with mono tags (H1 primary, H2+ dashed). */
    .hb-nav-outline { display: flex; flex-direction: column; }
    .hb-nav-stats { display: flex; flex-direction: column; gap: 4px; padding: 12px; border-bottom: 1px solid var(--hb-border); }
    .hb-nav-stat { display: flex; align-items: center; gap: 12px; height: 20px; font-size: 12px; }
    .hb-nav-stat span { color: var(--hb-text-secondary); }
    .hb-nav-stat b { color: var(--hb-text-primary); font-weight: 400; font-variant-numeric: tabular-nums; }
    .hb-nav-heads { display: flex; flex-direction: column; gap: 8px; padding: 12px; }
    .hb-nav-orow { display: flex; align-items: flex-start; gap: 6px; width: 100%; text-align: left; background: none; border: 0; border-radius: var(--hb-radius-sm, 3px); cursor: pointer; }
    .hb-nav-orow:hover { background: var(--hb-surface-hover); }
    .hb-nav-orow .dash { color: var(--hb-text-muted); font-size: 12px; line-height: 1.4; flex: none; }
    .hb-nav-orow .tag { font-family: var(--hb-font-mono, ui-monospace, SFMono-Regular, Menlo, monospace); font-size: 10px; color: var(--hb-text-muted); background: var(--hb-bg-muted); border-radius: var(--hb-radius-sm, 3px); padding: 2px 5px; flex: none; }
    .hb-nav-orow.is-title { gap: 8px; }
    .hb-nav-orow.is-title .tag { color: var(--hb-text-secondary); }
    .hb-nav-orow .ot { font-size: 11px; line-height: 1.6; color: var(--hb-text-secondary); min-width: 0; }
    .hb-nav-orow.is-title .ot { color: var(--hb-text-primary); }
    .hb-nav-oempty { padding: 28px 16px; text-align: center; color: var(--hb-text-disabled); font-size: 12px; line-height: 1.5; }
</style>
<script>
    (() => {
        const GRIP = '<svg viewBox="0 0 16 16" fill="currentColor" aria-hidden="true"><circle cx="5.5" cy="4" r="1.3"/><circle cx="5.5" cy="8" r="1.3"/><circle cx="5.5" cy="12" r="1.3"/><circle cx="10.5" cy="4" r="1.3"/><circle cx="10.5" cy="8" r="1.3"/><circle cx="10.5" cy="12" r="1.3"/></svg>';
        const BLOCK = '<svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.4" aria-hidden="true"><rect x="3" y="3" width="10" height="10" rx="1.5"/></svg>';
        const esc = (s) => String(s).replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
        const cssId = (id) => (window.CSS && CSS.escape ? CSS.escape(id) : String(id).replace(/"/g, '\\"'));

        // Resolved translations for every JS-built string in this panel. The
        // server pre-renders a <script type="application/json" data-hb-nav-strings>
        // containing the keys below (see the pushed strings block at the end of this file).
        const hbNavStrings = (() => {
            const tag = document.querySelector('[data-hb-nav-strings]');
            if (!tag) return {};
            try { return JSON.parse(tag.textContent || '{}'); } catch (e) { return {}; }
        })();
        const t = (key, fallback) => (hbNavStrings && hbNavStrings[key]) || fallback;

        const titleEl = () => document.querySelector('.hb-page__title[data-hb-title]') || document.querySelector('[data-hb-title]');
        const titleText = () => { const t = titleEl(); if (!t) return ''; return (t.tagName === 'INPUT' ? t.value : t.textContent).trim(); };
        const blocks = () => {
            const wrap = document.querySelector('.hb-page__blocks');
            if (!wrap) return [];
            return Array.prototype.filter.call(wrap.children, (c) => c.nodeType === 1 && c.hasAttribute('data-block'));
        };
        const labelFor = (name) => {
            const slug = name && name.indexOf('/') !== -1 ? name.split('/')[1] : (name || '');
            return slug ? slug.charAt(0).toUpperCase() + slug.slice(1).replace(/-/g, ' ') : t('block_fallback', 'Block');
        };
        const scrollToBlock = (id) => {
            const el = document.querySelector('.hb-page__blocks [data-block="' + cssId(id) + '"]');
            if (el) { try { el.scrollIntoView({ block: 'center' }); } catch (e) { /* older engines */ } }
            return el;
        };

        const TWIST = '<svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 6l4 4 4-4"/></svg>';
        // Folded subtrees per panel root. Rows are rebuilt wholesale on every
        // hb:blocks-changed, so the fold state must live OUTSIDE the DOM.
        const collapsedByRoot = new WeakMap();
        const foldSetFor = (root) => {
            let set = collapsedByRoot.get(root);
            if (!set) { set = new Set(); collapsedByRoot.set(root, set); }
            return set;
        };

        const buildList = (root) => {
            const host = root.querySelector('[data-hb-nav-list-body]');
            if (!host) return;
            const collapsed = foldSetFor(root);
            // Rows read the model (the runtime's source of truth); the Outline below reads the
            // DOM instead because it needs each block's *rendered* text, not raw innerHTML.
            // The walk recurses into innerBlocks — a group's children are part of the document
            // and the list view must show them, indented under their parent, not hide them.
            const docBlocks = window.hbEditor ? window.hbEditor.getDoc().blocks : [];
            const iconFor = (name) => {
                const t = document.querySelector('[data-hb-nav-icon="' + cssId(name) + '"]');
                return t && t.innerHTML.trim() ? t.innerHTML : BLOCK;
            };
            const rows = [];
            const walk = (list, depth) => {
                list.forEach((b) => {
                    const name = b.name || '';
                    const id = b.id == null ? '' : String(b.id);
                    const kids = Array.isArray(b.innerBlocks) ? b.innerBlocks : [];
                    const folded = collapsed.has(id);
                    rows.push('<button type="button" class="hb-nav-row" data-nav-row="' + esc(id) + '" data-nav-depth="' + depth + '"'
                        + (depth ? ' style="padding-left:' + (8 + depth * 14) + 'px"' : '')
                        + ' aria-keyshortcuts="Alt+ArrowUp Alt+ArrowDown">'
                        + (kids.length
                            ? '<span class="twist has-kids' + (folded ? ' is-closed' : '') + '" data-nav-twist="' + esc(id) + '">' + TWIST + '</span>'
                            : '<span class="twist"></span>')
                        + '<span class="ic">' + iconFor(name) + '</span>'
                        + '<span class="nm">' + esc(labelFor(name)) + '</span>'
                        // Drag-to-reorder is top-level only (moveBlock addresses root
                        // indexes) — nested rows get no grip rather than a dead one.
                        + (depth === 0 ? '<span class="grab">' + GRIP + '</span>' : '')
                        + '</button>');
                    if (kids.length && !folded) walk(kids, depth + 1);
                });
            };
            walk(docBlocks, 0);
            host.innerHTML = '<div class="hb-nav-list">' + (rows.length ? rows.join('') : '<div class="hb-nav-empty">' + t('empty_blocks', 'No blocks yet.') + '</div>') + '</div>';
        };

        const buildOutline = (root) => {
            const host = root.querySelector('[data-hb-nav-outline-body]');
            if (!host) return;
            let text = '';
            const heads = [];
            blocks().forEach((blk) => {
                const name = blk.getAttribute('data-block-name') || '';
                text += ' ' + (blk.textContent || '').replace(/\s+/g, ' ');
                if (name.indexOf('heading') !== -1) heads.push(blk);
            });
            const trimmed = text.replace(/\s+/g, ' ').trim();
            const words = trimmed ? trimmed.split(/\s+/).length : 0;
            const chars = trimmed.length;
            const mins = words > 0 ? Math.max(1, Math.round(words / 230)) : 0;
            const minutes = mins === 0
                ? t('minutes_zero', '0 minutes')
                : mins + ' ' + (mins === 1 ? t('minutes_one', 'minute') : t('minutes_other', 'minutes'));
            const stats = [
                [t('stat_characters', 'Characters:'), String(chars)],
                [t('stat_words', 'Words:'), String(words)],
                [t('stat_time', 'Time to read:'), minutes],
            ];
            let html = '<div class="hb-nav-outline">';
            html += '<div class="hb-nav-stats">' + stats.map((s) => '<div class="hb-nav-stat"><span>' + esc(s[0]) + '</span><b>' + esc(s[1]) + '</b></div>').join('') + '</div>';
            html += '<div class="hb-nav-heads">';
            html += '<button type="button" class="hb-nav-orow is-title" data-nav-orow="__title__"><span class="tag">H1</span><span class="ot">' + esc(titleText() || t('untitled', 'Untitled')) + '</span></button>';
            if (!heads.length) {
                html += '<div class="hb-nav-oempty">' + t('empty_headings', 'No headings yet. Add Heading blocks to build an outline.') + '</div>';
            } else {
                html += heads.map((h) => {
                    const lvl = parseInt(h.getAttribute('data-level') || '2', 10) || 2;
                    const t2 = (h.textContent || '').replace(/\s+/g, ' ').trim() || t('empty_heading', 'Empty heading');
                    const id = h.getAttribute('data-block') || '';
                    const indent = lvl > 2 ? ' style="margin-left:' + ((lvl - 2) * 14) + 'px"' : '';
                    return '<button type="button" class="hb-nav-orow lvl-' + lvl + '" data-nav-orow="' + esc(id) + '"' + indent + '>'
                        + '<span class="dash">—</span><span class="tag">H' + lvl + '</span><span class="ot">' + esc(t2) + '</span></button>';
                }).join('');
            }
            html += '</div></div>';
            host.innerHTML = html;
        };

        const rebuildAll = (root) => { buildList(root); buildOutline(root); };

        // ── List View drag-to-reorder + keyboard reordering ────────
        // Rows are recreated wholesale on every rebuildAll (buildList replaces host.innerHTML), so
        // all of this is delegated on `root` — a stable ancestor that survives the rebuild — rather
        // than bound per-row.
        const rowsIn = (root) => {
            const host = root.querySelector('[data-hb-nav-list-body] .hb-nav-list');
            return host ? Array.prototype.filter.call(host.children, (c) => c.classList && c.classList.contains('hb-nav-row')) : [];
        };
        const rowDropTarget = (root, excludeRow, clientY) => {
            const rows = rowsIn(root).filter((r) => r !== excludeRow);
            if (!rows.length) return null;
            for (let i = 0; i < rows.length; i++) {
                const r = rows[i].getBoundingClientRect();
                if (clientY < r.top + r.height / 2) return { el: rows[i], below: false };
            }
            return { el: rows[rows.length - 1], below: true };
        };
        const clearRowMarks = (root) => root.querySelectorAll('.hb-nav-row').forEach((r) => r.classList.remove('is-drop-before', 'is-drop-after'));
        const announce = (root, msg) => { const live = root.querySelector('.hb-nav__sr'); if (live) live.textContent = msg; };

        const wireRowDrag = (root) => {
            root.addEventListener('pointerdown', (e) => {
                if (e.button != null && e.button !== 0) return;
                const grip = e.target.closest('.grab');
                if (!grip) return;
                const row = grip.closest('[data-nav-row]');
                if (!row) return;
                const id = row.getAttribute('data-nav-row');
                e.preventDefault();
                try { grip.setPointerCapture(e.pointerId); } catch (err) { /* older engines */ }
                const startY = e.clientY;
                let active = false;
                let hover = null;

                function onMove(ev) {
                    if (!active) {
                        if (Math.abs(ev.clientY - startY) < 4) return;
                        active = true;
                        row.classList.add('is-dragging');
                    }
                    const found = rowDropTarget(root, row, ev.clientY);
                    if (!found) return;
                    if (hover && hover.el === found.el && hover.below === found.below) return;
                    hover = found;
                    clearRowMarks(root);
                    found.el.classList.add(found.below ? 'is-drop-after' : 'is-drop-before');
                }
                function cleanup() {
                    grip.removeEventListener('pointermove', onMove);
                    grip.removeEventListener('pointerup', onUp);
                    grip.removeEventListener('pointercancel', onCancel);
                    row.classList.remove('is-dragging');
                    clearRowMarks(root);
                }
                function onUp() {
                    if (active && hover && window.hbEditor) {
                        const fromIndex = window.hbEditor.indexOf(id);
                        const hoverIndex = window.hbEditor.indexOf(hover.el.getAttribute('data-nav-row'));
                        if (fromIndex !== -1 && hoverIndex !== -1) {
                            const desired = hover.below ? hoverIndex + 1 : hoverIndex;
                            const toIndex = desired > fromIndex ? desired - 1 : desired;
                            if (toIndex !== fromIndex) window.hbEditor.moveBlock(fromIndex, toIndex);
                        }
                    }
                    cleanup();
                }
                function onCancel() { cleanup(); }
                grip.addEventListener('pointermove', onMove);
                grip.addEventListener('pointerup', onUp);
                grip.addEventListener('pointercancel', onCancel);
            });
        };

        // Alt+ArrowUp / Alt+ArrowDown move the focused row one position — the only non-mouse path
        // to reorder. Refocuses the row (rebuilt under the same data-nav-row id) after the move and
        // announces it via the hidden live region.
        const wireRowKeys = (root) => {
            root.addEventListener('keydown', (e) => {
                if (e.key !== 'ArrowUp' && e.key !== 'ArrowDown') return;
                if (!e.altKey) return;
                const row = e.target.closest && e.target.closest('[data-nav-row]');
                if (!row || !window.hbEditor) return;
                const id = row.getAttribute('data-nav-row');
                const fromIndex = window.hbEditor.indexOf(id);
                if (fromIndex === -1) return;
                const toIndex = e.key === 'ArrowUp' ? fromIndex - 1 : fromIndex + 1;
                const total = window.hbEditor.getDoc().blocks.length;
                if (toIndex < 0 || toIndex >= total) return;
                e.preventDefault();
                const nmEl = row.querySelector('.nm');
                const label = nmEl ? nmEl.textContent : t('block_fallback', 'Block');
                if (window.hbEditor.moveBlock(fromIndex, toIndex)) {
                    const next = root.querySelector('[data-nav-row="' + cssId(id) + '"]');
                    if (next) next.focus();
                    const tmpl = t('moved_announcement', ':label moved to position :pos of :total.');
                    announce(root, tmpl.replace(':label', label).replace(':pos', String(toIndex + 1)).replace(':total', String(total)));
                }
            });
        };

        const wire = (root) => {
            if (root.__hbNav) return;
            root.__hbNav = true;
            const tabs = root.querySelector('[data-hb-tablist]');
            const listBody = root.querySelector('[data-hb-nav-list]');
            const outlineBody = root.querySelector('[data-hb-nav-outline]');
            tabs?.addEventListener('change', (e) => {
                if (listBody) listBody.hidden = e.detail.index !== 0;
                if (outlineBody) outlineBody.hidden = e.detail.index !== 1;
                rebuildAll(root);
            });
            root.addEventListener('click', (e) => {
                // The caret folds the subtree; it must not also select/scroll.
                const twist = e.target.closest('[data-nav-twist]');
                if (twist) {
                    const id = twist.getAttribute('data-nav-twist');
                    const set = foldSetFor(root);
                    set.has(id) ? set.delete(id) : set.add(id);
                    buildList(root);
                    return;
                }
                const row = e.target.closest('[data-nav-row]');
                if (row) {
                    scrollToBlock(row.getAttribute('data-nav-row'));
                    root.querySelectorAll('.hb-nav-row').forEach((r) => r.classList.toggle('is-on', r === row));
                    return;
                }
                const orow = e.target.closest('[data-nav-orow]');
                if (orow) {
                    const k = orow.getAttribute('data-nav-orow');
                    if (k === '__title__') { const t = titleEl(); if (t && t.focus) t.focus(); return; }
                    scrollToBlock(k);
                }
            });
            wireRowDrag(root);
            wireRowKeys(root);
            rebuildAll(root);
        };

        const boot = () => document.querySelectorAll('[data-hb-panel-nav]').forEach(wire);
        if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot, { once: true });
        else boot();
        document.addEventListener('hb:refresh', boot);
        // Title edits and Layers-button opens both refresh the live outline/list.
        document.addEventListener('hb:doc-title', () => document.querySelectorAll('[data-hb-panel-nav]').forEach(buildOutline));
        document.addEventListener('hb:nav-open', () => document.querySelectorAll('[data-hb-panel-nav]').forEach(rebuildAll));
        // The block model mutated (insert / edit) — relist the blocks and rebuild the outline.
        document.addEventListener('hb:blocks-changed', () => document.querySelectorAll('[data-hb-panel-nav]').forEach(rebuildAll));
    })();
</script>
@endonce

{{-- Translator strings consumed by the navigator's vanilla-JS runtime below —
     JS can't call __() / trans(), so we render the resolved strings into a
     single hidden <script type="application/json"> and read them back as JSON.
     Kept tiny on purpose: only the strings the JS templates actually need. --}}
@once
@push('hb-nav-strings')
@php
    // Built here, then emitted as a single variable below. A multi-line json directive taking an
    // inline array literal makes Blade's argument parser mismatch the opening bracket against the
    // array's closing paren, compiling to `json_encode([ ... )` — a hard ParseError that 500s the
    // whole editor. Passing one already-built variable keeps the directive argument on one line.
    // (Directive names are deliberately not written with a leading at-sign anywhere in this file's
    // comments: Blade's compiler matches them inside comment text too, and a stray one reads as an
    // unclosed block.)
    $hbNavStrings = [
        'empty_blocks' => __('heisenberg::editor.panel_navigator.empty_blocks'),
        'empty_headings' => __('heisenberg::editor.panel_navigator.empty_headings'),
        'stat_characters' => __('heisenberg::editor.panel_navigator.stat_characters'),
        'stat_words' => __('heisenberg::editor.panel_navigator.stat_words'),
        'stat_time' => __('heisenberg::editor.panel_navigator.stat_time'),
        'minutes_one' => __('heisenberg::editor.panel_navigator.minutes_one'),
        'minutes_other' => __('heisenberg::editor.panel_navigator.minutes_other'),
        'minutes_zero' => __('heisenberg::editor.panel_navigator.minutes_zero'),
        'empty_heading' => __('heisenberg::editor.panel_navigator.empty_heading'),
        'block_fallback' => __('heisenberg::editor.panel_navigator.block_fallback'),
        'untitled' => __('heisenberg::editor.common.untitled'),
        'moved_announcement' => __('heisenberg::editor.panel_navigator.moved_announcement'),
    ];
@endphp
<script type="application/json" data-hb-nav-strings>@json($hbNavStrings)</script>
@endpush
@endonce
<div data-hb-panel-nav {{ $attributes->merge(['class' => 'hb-nav']) }}>
    <x-ui.panel-tabs :items="[['label' => __('heisenberg::editor.panel_navigator.tab_list')], ['label' => __('heisenberg::editor.panel_navigator.tab_outline')]]" :active-index="0" />

    <div class="hb-nav__content" data-hb-nav-list>
        <div class="hb-nav__body hb-nav__body--list" data-hb-nav-list-body></div>
        {{-- Per-block icons for the List View rows, pre-rendered from each contract's own icon
             (same resolution as the Components palette) and cloned by buildList() per row. --}}
        <div hidden data-hb-nav-icon-templates>
            @foreach (($registry ?? []) as $name => $block)
                @php $navIcon = \Heisenberg\Editor\EditorIcon::resolveSlug((string) ($block['icon'] ?? '')) !== null ? (string) $block['icon'] : 'cube'; @endphp
                <template data-hb-nav-icon="{{ $name }}">@include('heisenberg::components.ui.icon', ['name' => $navIcon, 'size' => 13])</template>
            @endforeach
        </div>
        <x-ui.custom-scrollbar container=".hb-nav__body--list" />
    </div>

    <div class="hb-nav__content" data-hb-nav-outline hidden>
        <div class="hb-nav__body hb-nav__body--outline" data-hb-nav-outline-body></div>
        <x-ui.custom-scrollbar container=".hb-nav__body--outline" />
    </div>

    {{-- Keyboard-reorder announcements (Alt+ArrowUp/Down on a focused row) — see wireRowKeys. --}}
    <div class="hb-nav__sr" role="status" aria-live="polite"></div>
</div>
