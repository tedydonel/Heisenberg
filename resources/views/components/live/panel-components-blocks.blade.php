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
     Blocks tab: search field only. This tab is reserved for the user's SAVED custom UIs (created via
     the toolbar's save-as-block icon) — empty until then, and never a second copy of the component
     palette. --}}
@once
<style>
    .hb-panel-cb { display: flex; flex-direction: column; width: 240px; height: 100%; background: var(--hb-bg, #fff); border-right: 1px solid var(--hb-border, #E4E4E4); flex: none; }
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
        if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot, { once: true });
        else boot();
        document.addEventListener('hb:refresh', boot);
    })();
</script>
@endonce

@props([
    // The full client registry (BlockViewData::clientBlocks(), the same shape block-runtime.blade.php
    // and live/inspector get as `$registry`), keyed by block name. A card below only renders when its
    // key is present here, so the grid can never show a card for a block that isn't actually enabled.
    'registry' => [],
])

@php
    $blockCards = [];
    foreach ($registry as $blockName => $block) {
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
<div data-hb-panel-cb {{ $attributes->merge(['class' => 'hb-panel-cb']) }}>
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
        {{-- Filters the user's saved custom blocks ([data-hb-saved-block] rows) once that
             feature lands — the same live-query contract as the Components filter above. --}}
        <x-ui.search-field :placeholder="__('heisenberg::editor.panel_components_blocks.search_blocks')"
            data-hb-filter="[data-hb-panel-cb-blocks]" data-hb-filter-item="[data-hb-saved-block]" />
    </div>
</div>
