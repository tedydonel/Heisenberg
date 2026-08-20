{{-- live/quick-inserter — the Gutenberg-style quick inserter: click either appender and pick the
     block you actually want, instead of always getting a paragraph.

     Contract with live/block-runtime (which owns both appenders and is NOT modified by this file):
     the runtime dispatches a CANCELABLE `hb:quick-insert` on `document` with
     `detail: { containerId: string|null, anchor: HTMLElement }` — containerId set for an empty
     container's inner appender, null for the canvas `+`. This component claims that event with
     preventDefault() and drives the insert itself; with this component absent the runtime's own
     fallback (a paragraph) still runs, so the canvas is never left without an insert path.

     Composed from existing primitives, no new UI invented: `ui/search-field` (its built-in
     data-hb-filter/data-hb-filter-item contract filters the grid below with zero extra JS),
     `ui/tool-card` for every card, and the shared `.hb-pop` popover shell (32-pickers.css) for
     bg/border/shadow — the same chrome as the toolbar's ⋯ menu and the style popups.

     Cards are derived from the live registry exactly like live/panel-components-blocks does
     (including skipping parent-constrained blocks such as `column`, which only ever arrive via
     their parent), but carry data-hb-qi-block rather than data-hb-insert-block: the palette's
     attribute is claimed by the runtime's own click handler AND its pointer-drag gesture, and a
     card in a popup should do neither.

     Targeting a container additionally gates the grid: the container contract's
     innerBlocks.allowedBlocks ('*' or a list) decides which cards are live; the rest render dimmed
     (.hb-qi-disabled) and no-op, so the popup always shows the full vocabulary while never
     offering an insert insertInto() would refuse. --}}
@once
<style>
    /* .hb-pop supplies bg/border/shadow/font; everything here is placement + layout. Fixed
       positioning (coordinates computed from the anchor's rect in the script below) so the
       popup escapes .hb-editor__canvas's overflow:hidden. z-index sits above the docked block
       toolbar (30/40/50) and below the inspector's style popups (1000). */
    .hb-qi {
        position: fixed; z-index: 900;
        width: 280px; max-width: calc(100vw - 16px);
        display: flex; flex-direction: column;
        border-radius: var(--hb-radius-md, 5px);
        box-shadow: var(--hb-shadow-lg, 3px 4px 4px rgba(0, 0, 0, .1));
        overflow: hidden;
    }
    .hb-qi[hidden] { display: none; }
    /* .hb-qi__body wraps the scroll container + the custom-scrollbar as siblings (panel-components-blocks
       pattern). `position: relative` + `min-height: 0` let the custom-scrollbar's
       `position:absolute; height:100%` anchor to this element instead of escaping to the
       popup (position:fixed). flex: 1 1 auto so it absorbs the popup's leftover height
       between the header and the footer. */
    .hb-qi__body {
        display: flex;
        flex-direction: column;
        flex: 1 1 auto;
        min-height: 0;
        position: relative;
    }
    /* The .hb-scroll-container class is added at runtime by ui/custom-scrollbar when it
       wires itself to this element — it sets overflow-y:auto and gives the custom bar a
       target. Padding moved onto the inner grid wrapper so the scroll gutter doesn't
       eat into the card layout. 240px keeps the popup compact (≈search 36 + scroll 240 +
       footer 48 + paddings) so it doesn't dominate a viewport. */
    .hb-qi__scroll { flex: 1 1 auto; min-height: 0; max-height: 240px; }
    .hb-qi__scroll-inner { padding: var(--hb-space-3, 12px); }
    /* 2-up, same rhythm as the Components panel's card grid. */
    .hb-qi__grid { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; }
    .hb-qi-disabled { opacity: .4; cursor: not-allowed; }
    .hb-qi-disabled:hover { background: var(--hb-bg); }
    /* Footer row — top hairline separator, full-width secondary button. Uses the same spacing
       tokens as the rest of the popup (--hb-space-3, --hb-border). */
    .hb-qi__footer {
        display: flex;
        padding: var(--hb-space-3, 12px);
        border-top: 1px solid var(--hb-border);
    }
    .hb-qi__footer .hb-btn { width: 100%; }
</style>
<script>
    (() => {
        const POPUP = '[data-hb-qi]';
        // Open state is module-scoped, not per-element: the popup is a singleton and the
        // listeners below are wired once on `document`, so a re-boot after hb:refresh can never
        // leave a stale closure holding a detached popup.
        let containerId = null;
        let anchorEl = null;

        const popup = () => document.querySelector(POPUP);
        const cards = (pop) => pop.querySelectorAll('[data-hb-qi-block]');

        // Every open starts from a clean slate: empty query, every card unhidden.
        const resetSearch = (pop) => {
            const input = pop.querySelector('.hb-searchfield__input');
            if (input) input.value = '';
            cards(pop).forEach((card) => card.classList.remove('hb-filter-hidden'));
        };

        // '*' (or no container) means "anything goes"; otherwise the container contract's
        // allowedBlocks list, normalised to an array.
        const allowedFor = (id) => {
            const api = window.hbEditor;
            if (!id || !api || typeof api.getModel !== 'function') return '*';
            const model = api.getModel(id);
            const contract = model && typeof api.getContract === 'function' ? api.getContract(model.name) : null;
            const inner = contract && contract.innerBlocks ? contract.innerBlocks : null;
            if (!inner || !inner.enabled) return [];
            if (inner.allowedBlocks === '*') return '*';
            return Array.isArray(inner.allowedBlocks) ? inner.allowedBlocks : [];
        };

        const gate = (pop) => {
            const allowed = allowedFor(containerId);
            cards(pop).forEach((card) => {
                const name = card.getAttribute('data-hb-qi-block');
                const ok = allowed === '*' || allowed.indexOf(name) !== -1;
                card.classList.toggle('hb-qi-disabled', !ok);
                if (ok) card.removeAttribute('aria-disabled');
                else card.setAttribute('aria-disabled', 'true');
            });
        };

        // Below the anchor when there's room, above otherwise, always clamped to the viewport.
        const place = (pop) => {
            if (!anchorEl || !anchorEl.isConnected) return;
            const r = anchorEl.getBoundingClientRect();
            const gap = 6, edge = 8;
            const w = pop.offsetWidth, h = pop.offsetHeight;
            let top = r.bottom + gap;
            if (top + h > window.innerHeight - edge) {
                const above = r.top - h - gap;
                top = above >= edge ? above : Math.max(edge, window.innerHeight - h - edge);
            }
            let left = r.left;
            if (left + w > window.innerWidth - edge) left = window.innerWidth - w - edge;
            if (left < edge) left = edge;
            pop.style.top = top + 'px';
            pop.style.left = left + 'px';
        };

        const close = () => {
            const pop = popup();
            containerId = null;
            anchorEl = null;
            if (pop) pop.hidden = true;
        };

        const boot = () => {
            document.querySelectorAll(POPUP).forEach((pop) => {
                if (pop.__hbQi) return;
                pop.__hbQi = true;
                pop.hidden = true;
            });

            if (document.__hbQuickInserter) return;
            document.__hbQuickInserter = true;

            // Claim the runtime's cancelable open event. A second one while open simply
            // re-anchors — nothing stacks, because there is only ever this one popup.
            document.addEventListener('hb:quick-insert', (e) => {
                const pop = popup();
                const detail = e.detail || {};
                if (!pop || !detail.anchor) return;
                e.preventDefault();
                containerId = detail.containerId || null;
                anchorEl = detail.anchor;
                pop.hidden = false;
                resetSearch(pop);
                gate(pop);
                place(pop);
                const input = pop.querySelector('.hb-searchfield__input');
                if (input) { try { input.focus({ preventScroll: true }); } catch (err) { input.focus(); } }
            });

            document.addEventListener('click', (e) => {
                const pop = popup();
                if (!pop || pop.hidden) return;
                const card = e.target.closest ? e.target.closest('[data-hb-qi-block]') : null;
                if (card && pop.contains(card)) {
                    if (card.classList.contains('hb-qi-disabled')) return;
                    const name = card.getAttribute('data-hb-qi-block');
                    const api = window.hbEditor;
                    if (name && api) {
                        if (containerId && typeof api.insertInto === 'function') api.insertInto(containerId, name);
                        else if (typeof api.insertBlock === 'function') api.insertBlock(name);
                    }
                    close();
                    return;
                }
                // "Browse all components" footer button — hand off to the sidebar's own panel
                // switcher so the activation state, persisted nav, and one-open-at-a-time
                // viewport rule are all handled by the same code path as the user clicking
                // the Components nav item directly. Window-level fallback if the sidebar's
                // helper hasn't booted yet (e.g. on first paint).
                const browseAll = e.target.closest && e.target.closest('[data-hb-qi-browse-all]');
                if (browseAll && pop.contains(browseAll)) {
                    e.preventDefault();
                    if (typeof window.hbEditorShowPanel === 'function') {
                        window.hbEditorShowPanel('cb', 0);
                    } else {
                        const navBtn = document.querySelector('[data-hb-nav="cb:0"]');
                        if (navBtn) navBtn.click();
                    }
                    close();
                    return;
                }
                // Click outside — but never the appender that just opened us (both appender
                // handlers stopPropagation, so this is belt-and-braces) nor the popup chrome.
                if (e.target.closest && (e.target.closest(POPUP) || e.target.closest('[data-hb-insert]') || e.target.closest('[data-hb-inner-appender]'))) return;
                close();
            });

            document.addEventListener('keydown', (e) => {
                const pop = popup();
                if (!pop || pop.hidden) return;
                if (e.key === 'Escape') close();
            });

            // The anchor is a canvas element; keep the fixed popup glued to it.
            window.addEventListener('resize', () => {
                const pop = popup();
                if (pop && !pop.hidden) place(pop);
            });
            document.addEventListener('scroll', () => {
                const pop = popup();
                if (!pop || pop.hidden) return;
                if (!anchorEl || !anchorEl.isConnected) { close(); return; }
                place(pop);
            }, true);
        };
        if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot, { once: true });
        else boot();
        document.addEventListener('hb:refresh', boot);
    })();
</script>
@endonce

@props([
    // The full client registry (BlockViewData::clientBlocks()) — the same shape
    // live/panel-components-blocks and live/block-runtime receive, keyed by block name.
    'registry' => [],
])

@php
    $blockCards = [];
    foreach ($registry as $blockName => $block) {
        // Parent-constrained blocks (e.g. `column`) are never offered standalone — same rule
        // as the Components palette.
        if (! empty($block['innerBlocks']['parent'])) {
            continue;
        }
        $lucideIcon = (string) ($block['icon'] ?? '');
        $icon = \Heisenberg\Editor\EditorIcon::resolveSlug($lucideIcon) !== null ? $lucideIcon : 'cube';
        $slug = str_contains($blockName, '/') ? explode('/', $blockName, 2)[1] : $blockName;
        $blockCards[] = [
            'icon' => $icon,
            'label' => (string) ($block['title'] ?? ucfirst($slug)),
            'block' => $blockName,
        ];
    }
@endphp
<div data-hb-qi role="dialog" aria-label="{{ __('heisenberg::editor.quick_inserter.aria_label') }}"
    {{ $attributes->merge(['class' => 'hb-pop hb-qi']) }} hidden>
    {{-- ui/search-field's own filter contract does the work: typing toggles .hb-filter-hidden on
         every [data-hb-qi-block] inside the popup. --}}
    <x-ui.search-field :placeholder="__('heisenberg::editor.quick_inserter.search')"
        data-hb-filter="[data-hb-qi]" data-hb-filter-item="[data-hb-qi-block]" />
    {{-- The body wrapper pairs with the custom-scrollbar as siblings — same shape as
         live/panel-components-blocks (.hb-panel-cb__body → .hb-panel-cb__scroll + bar).
         The wrapper is `position: relative` so the bar's `position: absolute; height: 100%`
         anchors to it, and the scroll container flexes to fill the wrapper so they share
         the same height. Nesting the bar inside the container would have made its
         containing block the container itself, which works visually but couples two
         components that should be peers. --}}
    <div class="hb-qi__body">
        <div class="hb-qi__scroll" data-hb-qi-scroll>
            <div class="hb-qi__scroll-inner">
                <div class="hb-qi__grid">
                    @foreach ($blockCards as $card)
                        <x-ui.tool-card :icon="$card['icon']" :label="$card['label']"
                            :data-hb-qi-block="$card['block']" />
                    @endforeach
                </div>
            </div>
        </div>
        <x-ui.custom-scrollbar container="[data-hb-qi-scroll]" />
    </div>
    {{-- Footer hand-off — opens the Components tab in the left sidebar (handled by the script
         above calling window.hbEditorShowPanel('cb', 0)). ui/button supplies the chrome. --}}
    <div class="hb-qi__footer">
        <x-ui.button variant="secondary" data-hb-qi-browse-all
            leadingIcon="squares-four">{{ __('heisenberg::editor.quick_inserter.browse_all') }}</x-ui.button>
    </div>
</div>
