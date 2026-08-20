{{-- live/panel-components-blocks — Middle panel, 240px.
     Components tab: search (ui/search-field), "BASE" category head (ui/category-head, now wired to
     collapse the grid below via its [data-hb-category-body] contract) and a grid of ui/tool-card
     cards — genuinely one per block BlockRegistryService discovers, no hardcoded per-name list
     (2026-08-02: replaced the earlier $blockCardDefinitions allow-list, which only ever grew or
     shrank by hand — see TODO.md). Ship a new contract under resources/blocks/ and its card appears
     here automatically; delete one and its card is gone, with zero Blade changes either way.
     Label comes straight from the contract's own (already-localized) `title`. Icon is trickier: a
     contract's `icon` is a Lucide slug, but the editor chrome's resolver (EditorIcon) only speaks
     Phosphor — some names coincide (e.g. "bookmark"), some don't (e.g. "pilcrow" has no Phosphor
     equivalent, per EditorIcon::resolveSlug()), so a contract's icon is used only when it actually
     resolves to a real vendored file; otherwise the card falls back to a generic "cube" glyph rather
     than silently rendering an empty one.
     Each card carries data-hb-insert-block (consumed by live/block-runtime) so a click inserts that
     block, and is pointer-draggable onto the canvas to insert at a specific position
     (block-runtime's wirePaletteDrag) — a plain click still appends, unchanged.
     Blocks tab: search field plus a grid of the user's saved custom UIs (created via the toolbar's
     save-as-block icon, persisted by HeisenbergPatternController). Each card carries
     data-hb-insert-pattern so the runtime can clone + insert the saved block composition; a per-
     card delete button hits the destroy endpoint and refreshes the tab. A save through the toolbar
     dispatches hb:patterns-changed, which the script below listens for to re-fetch + re-render. --}}
@once
<style>
    .hb-panel-cb { display: flex; flex-direction: column; width: 240px; height: 100%; background: var(--hb-bg); border-right: 1px solid var(--hb-border); flex: none; }
    .hb-panel-cb__content { display: flex; flex-direction: column; flex: 1 1 auto; min-height: 0; overflow: hidden; }
    .hb-panel-cb__content[hidden] { display: none; }
    /* Two-layer scroll shell — matches live/inspector's Post tab exactly (the one instance of this
       scrollbar that never had rendering trouble): an outer wrapper that's just a flex slot + the
       position:relative anchor for the scrollbar bar, and an inner body that's the ACTUAL scroll
       region the scrollbar's `container` prop targets. Collapsing every layer into one div (as this
       used to be) left the scrollbar bar and the JS-tracked scroll container as literally the same
       element, and the thumb never tracked real scroll position. */
    .hb-panel-cb__body { flex: 1 1 auto; min-height: 0; overflow: hidden; position: relative; display: flex; flex-direction: column; }
    .hb-panel-cb__scroll { flex: 1 1 auto; min-height: 0; overflow: hidden; padding: var(--hb-space-3, 12px); }
    .hb-panel-cb__grid { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; }
    /* Saved-blocks tab: same card chrome as Components, with a hover-only delete affordance so
       the tab stays visually clean. Empty state centered in the panel body, not the scroll region,
       so the placeholder never drifts as the panel reflows. */
    .hb-panel-cb__card { position: relative; }
    .hb-panel-cb__card-del { position: absolute; top: 4px; right: 4px; width: 22px; height: 22px;
        display: none; align-items: center; justify-content: center;
        background: var(--hb-surface); border: 1px solid var(--hb-border);
        border-radius: 4px; cursor: pointer; color: var(--hb-muted, #6b6b6b); padding: 0; }
    .hb-panel-cb__card:hover .hb-panel-cb__card-del { display: inline-flex; }
    .hb-panel-cb__card-del:hover { color: var(--hb-ink, #111); border-color: var(--hb-ink, #111); }
    .hb-panel-cb__empty { padding: 24px 12px; text-align: center; color: var(--hb-muted, #6b6b6b);
        font-size: var(--hb-fs-sm, 12px); line-height: 1.45; }
</style>
<script>
    (() => {
        const boot = () => {
            document.querySelectorAll('[data-hb-panel-cb]').forEach((root) => {
                if (root.__hbPanelCb) return;
                const tabs = root.querySelector('[data-hb-tablist]');
                const components = root.querySelector('[data-hb-panel-cb-components]');
                const blocks = root.querySelector('[data-hb-panel-cb-blocks]');
                tabs?.addEventListener('change', (event) => {
                    if (components) components.hidden = event.detail.index !== 0;
                    if (blocks) blocks.hidden = event.detail.index !== 1;
                });
                root.__hbPanelCb = true;
            });
        };

        // Re-fetch + re-render the Blocks tab when the toolbar's save-as-block (or a delete from
        // one of these cards) signals hb:patterns-changed. Same URL the editor's first paint
        // seeded from; this avoids threading every pattern's full blocks tree into the initial
        // render payload. On error or empty list the empty-state row reappears. We rebuild only
        // the saved-block grid — Components tab is untouched.
        const refreshBlocksTab = () => {
            const root = document.querySelector('[data-hb-panel-cb]');
            if (!root) return;
            const url = root.getAttribute('data-hb-patterns-index-url');
            const grid = root.querySelector('[data-hb-patterns-grid]');
            const scroll = root.querySelector('[data-hb-panel-cb-blocks-scroll]');
            if (!url || !grid || !scroll) return;
            fetch(url, { headers: { 'Accept': 'application/json' } })
                .then((r) => r.ok ? r.json() : null)
                .then((data) => {
                    if (!data || !Array.isArray(data.patterns)) return;
                    const emptyLabel = grid.getAttribute('data-empty-label') || '';
                    grid.innerHTML = '';
                    if (!data.patterns.length) {
                        const empty = document.createElement('div');
                        empty.className = 'hb-panel-cb__empty';
                        empty.textContent = emptyLabel;
                        grid.appendChild(empty);
                        return;
                    }
                    const frag = document.createDocumentFragment();
                    data.patterns.forEach((p) => {
                        const card = document.createElement('div');
                        card.className = 'hb-panel-cb__card';
                        card.setAttribute('data-hb-saved-block', String(p.id));
                        card.setAttribute('data-hb-pattern-name', p.name || '');
                        card.setAttribute('title', p.name || '');
                        const label = document.createElement('span');
                        label.className = 'hb-tcard__label';
                        label.textContent = p.name || '';
                        card.appendChild(label);
                        const del = document.createElement('button');
                        del.type = 'button';
                        del.className = 'hb-panel-cb__card-del';
                        del.setAttribute('data-hb-pattern-delete', String(p.id));
                        del.setAttribute('aria-label', 'Delete');
                        del.textContent = '×';
                        card.appendChild(del);
                        frag.appendChild(card);
                    });
                    grid.appendChild(frag);
                })
                .catch(() => {});
        };
        document.addEventListener('hb:patterns-changed', refreshBlocksTab);

        if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot, { once: true });
        else boot();
        document.addEventListener('hb:refresh', boot);
    })();
</script>
@endonce

@props([
    'registry' => [],
    'patterns' => [],
    'patternsIndexUrl' => '',
    'patternsStoreUrl' => '',
    'patternsDestroyUrl' => '',
])

@php
    $blockCards = [];
    foreach ($registry as $blockName => $block) {
        // A parent-constrained block (e.g. `column`, which only lives inside `columns`)
        // never appears as a standalone palette card — it arrives via its parent's seed
        // template or in-container insertion.
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
<div data-hb-panel-cb {{ $attributes->merge(['class' => 'hb-panel-cb']) }}
    data-hb-patterns-index-url="{{ $patternsIndexUrl }}"
    data-hb-patterns-store-url="{{ $patternsStoreUrl }}"
    data-hb-patterns-destroy-url="{{ $patternsDestroyUrl }}">
    <x-ui.panel-tabs :items="[['label' => __('heisenberg::editor.panel_components_blocks.tab_components')], ['label' => __('heisenberg::editor.panel_components_blocks.tab_blocks')]]" :active-index="0" />

    <div class="hb-panel-cb__content" data-hb-panel-cb-components>
        <x-ui.search-field :placeholder="__('heisenberg::editor.panel_components_blocks.search_components')"
            data-hb-filter="[data-hb-panel-cb-components]" data-hb-filter-item="[data-hb-insert-block]" />
        <x-ui.category-head :label="__('heisenberg::editor.panel_components_blocks.category_base')" />
        {{-- data-hb-category-body: the sibling ui/category-head above collapses/expands this via its
             own script (see ui/category-head.blade.php) — no wiring needed here. --}}
        <div class="hb-panel-cb__body" data-hb-category-body>
            <div class="hb-panel-cb__scroll" data-hb-panel-cb-scroll>
                <div class="hb-panel-cb__grid">
                    @foreach ($blockCards as $card)
                        <x-ui.tool-card :icon="$card['icon']" :label="$card['label']"
                            :data-hb-insert-block="$card['block']" />
                    @endforeach
                </div>
            </div>
            <x-ui.custom-scrollbar container="[data-hb-panel-cb-scroll]" />
        </div>
    </div>

    <div class="hb-panel-cb__content" data-hb-panel-cb-blocks hidden>
        <x-ui.search-field :placeholder="__('heisenberg::editor.panel_components_blocks.search_blocks')"
            data-hb-filter="[data-hb-panel-cb-blocks]" data-hb-filter-item="[data-hb-saved-block]" />
        <div class="hb-panel-cb__body" data-hb-patterns-body>
            <div class="hb-panel-cb__scroll" data-hb-panel-cb-blocks-scroll>
                <div class="hb-panel-cb__grid" data-hb-patterns-grid data-empty-label="{{ __('heisenberg::editor.panel_components_blocks.empty_blocks') }}">
                    @forelse ($patterns as $pattern)
                        <div class="hb-panel-cb__card" data-hb-saved-block="{{ (int) $pattern['id'] }}"
                            data-hb-pattern-name="{{ $pattern['name'] }}" title="{{ $pattern['name'] }}">
                            <x-ui.tool-card :icon="'stack'" :label="$pattern['name']" />
                            <button type="button" class="hb-panel-cb__card-del"
                                data-hb-pattern-delete="{{ (int) $pattern['id'] }}"
                                aria-label="{{ __('heisenberg::editor.patterns.delete') }}">@include('heisenberg::components.ui.icon', ['name' => 'x', 'size' => 14])</button>
                        </div>
                    @empty
                        <div class="hb-panel-cb__empty" data-hb-patterns-empty>{{ __('heisenberg::editor.panel_components_blocks.empty_blocks') }}</div>
                    @endforelse
                </div>
            </div>
            <x-ui.custom-scrollbar container="[data-hb-panel-cb-blocks-scroll]" />
        </div>
    </div>
</div>