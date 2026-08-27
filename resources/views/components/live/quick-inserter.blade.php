@once
<style nonce="{{ heisenberg_csp_nonce() }}">
    .hb-qi {
        position: fixed; z-index: 900;
        width: 280px; max-width: calc(100vw - 16px);
        display: flex; flex-direction: column;
        border-radius: var(--hb-radius-md, 5px);
        box-shadow: var(--hb-shadow-lg, 3px 4px 4px rgba(0, 0, 0, .1));
        overflow: hidden;
    }
    .hb-qi[hidden] { display: none; }
    .hb-qi__body {
        display: flex;
        flex-direction: column;
        flex: 1 1 auto;
        min-height: 0;
        position: relative;
    }
    .hb-qi__scroll { flex: 1 1 auto; min-height: 0; max-height: 240px; }
    .hb-qi__scroll-inner { padding: var(--hb-space-3, 12px); }
    .hb-qi__grid { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; }
    .hb-qi-disabled { opacity: .4; cursor: not-allowed; }
    .hb-qi-disabled:hover { background: var(--hb-bg); }
    .hb-qi__footer {
        display: flex;
        padding: var(--hb-space-3, 12px);
        border-top: 1px solid var(--hb-border);
    }
    .hb-qi__footer .hb-btn { width: 100%; }
</style>
<script nonce="{{ heisenberg_csp_nonce() }}">
    (() => {
        const POPUP = '[data-hb-qi]';
        let containerId = null;
        let anchorEl = null;

        const popup = () => document.querySelector(POPUP);
        const cards = (pop) => pop.querySelectorAll('[data-hb-qi-block]');

        const resetSearch = (pop) => {
            const input = pop.querySelector('.hb-searchfield__input');
            if (input) input.value = '';
            cards(pop).forEach((card) => card.classList.remove('hb-filter-hidden'));
        };

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
                if (e.target.closest && (e.target.closest(POPUP) || e.target.closest('[data-hb-insert]') || e.target.closest('[data-hb-inner-appender]'))) return;
                close();
            });

            document.addEventListener('keydown', (e) => {
                const pop = popup();
                if (!pop || pop.hidden) return;
                if (e.key === 'Escape') close();
            });

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
    'registry' => [],
])

@php
    $blockCards = [];
    foreach ($registry as $blockName => $block) {
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
    <x-heisenberg::ui.search-field :placeholder="__('heisenberg::editor.quick_inserter.search')"
        data-hb-filter="[data-hb-qi]" data-hb-filter-item="[data-hb-qi-block]" />
    <div class="hb-qi__body">
        <div class="hb-qi__scroll" data-hb-qi-scroll>
            <div class="hb-qi__scroll-inner">
                <div class="hb-qi__grid">
                    @foreach ($blockCards as $card)
                        <x-heisenberg::ui.tool-card :icon="$card['icon']" :label="$card['label']"
                            :data-hb-qi-block="$card['block']" />
                    @endforeach
                </div>
            </div>
        </div>
        <x-heisenberg::ui.custom-scrollbar container="[data-hb-qi-scroll]" />
    </div>
    <div class="hb-qi__footer">
        <x-heisenberg::ui.button variant="secondary" data-hb-qi-browse-all
            leadingIcon="squares-four">{{ __('heisenberg::editor.quick_inserter.browse_all') }}</x-heisenberg::ui.button>
    </div>
</div>
