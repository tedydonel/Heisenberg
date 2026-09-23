@once
<style nonce="{{ heisenberg_csp_nonce() }}">
    .hb-panel-cb { display: flex; flex-direction: column; width: 240px; height: 100%; background: var(--hb-bg); border-right: 1px solid var(--hb-border); flex: none; }
    .hb-panel-cb__content { display: flex; flex-direction: column; flex: 1 1 auto; min-height: 0; overflow: hidden; }
    .hb-panel-cb__content[hidden] { display: none; }
    .hb-panel-cb__body { flex: 1 1 auto; min-height: 0; overflow: hidden; position: relative; display: flex; flex-direction: column; }
    .hb-panel-cb__scroll { flex: 1 1 auto; min-height: 0; overflow: hidden; padding: var(--hb-space-3, 12px); }
    .hb-panel-cb__blocks-scroll { display: flex; flex-direction: column; }
    .hb-panel-cb__grid { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; }
    .hb-panel-cb__blocks-grid { flex: 1 1 auto; min-height: 100%; }
    .hb-panel-cb__blocks-grid .hb-panel-cb__empty { grid-column: 1 / -1; display: flex; align-items: center; justify-content: center; min-height: 100%; padding: 0 12px; }
    /* Hug the card: as a stretched grid item the wrapper ran the full height of the scroll area,
       so its delete button floated away from the card and a click far below one still inserted
       that pattern. */
    .hb-panel-cb__card { position: relative; align-self: start; }
    .hb-panel-cb__card-del { position: absolute; top: 4px; right: 4px; width: 22px; height: 22px;
        display: none; align-items: center; justify-content: center;
        background: var(--hb-surface); border: 1px solid var(--hb-border);
        border-radius: 4px; cursor: pointer; color: var(--hb-muted, #6b6b6b); padding: 0; }
    .hb-panel-cb__card:hover .hb-panel-cb__card-del { display: inline-flex; }
    .hb-panel-cb__card-del:hover { color: var(--hb-ink, #111); border-color: var(--hb-ink, #111); }
    .hb-panel-cb__empty { padding: 24px 12px; text-align: center; color: var(--hb-muted, #6b6b6b);
        font-size: var(--hb-fs-sm, 12px); line-height: 1.45; }
    .hb-panel-cb__email-note { margin: 0; padding: 8px var(--hb-space-3, 12px) 0; color: var(--hb-muted, #6b6b6b);
        font-size: var(--hb-fs-sm, 12px); line-height: 1.4; }
</style>
<script nonce="{{ heisenberg_csp_nonce() }}">
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
                    const template = root.querySelector('[data-hb-pattern-card-template]');
                    if (!template || !template.content) return;
                    const frag = document.createDocumentFragment();
                    data.patterns.forEach((p) => {
                        const card = template.content.firstElementChild.cloneNode(true);
                        const name = p.name || '';
                        card.setAttribute('data-hb-saved-block', String(p.id));
                        card.setAttribute('data-hb-pattern-name', name);
                        card.setAttribute('title', name);
                        const label = card.querySelector('.hb-toolcard__label');
                        if (label) label.textContent = name;
                        card.querySelector('[data-hb-pattern-delete]')?.setAttribute('data-hb-pattern-delete', String(p.id));
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
    'documentType' => 'post',
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

    // docs/email-system.md §4: this panel's own $registry is already filtered SERVER-SIDE to
    // the email-safe subset (EditorController::paletteBlocks()) when $documentType is 'email' —
@endphp
<div data-hb-panel-cb {{ $attributes->merge(['class' => 'hb-panel-cb']) }}
    data-hb-patterns-index-url="{{ $patternsIndexUrl }}"
    data-hb-patterns-store-url="{{ $patternsStoreUrl }}"
    data-hb-patterns-destroy-url="{{ $patternsDestroyUrl }}">
    <x-heisenberg::ui.panel-tabs :items="[['label' => __('heisenberg::editor.panel_components_blocks.tab_components')], ['label' => __('heisenberg::editor.panel_components_blocks.tab_blocks')]]" :active-index="0" />

    <div class="hb-panel-cb__content" data-hb-panel-cb-components>
        <x-heisenberg::ui.search-field :placeholder="__('heisenberg::editor.panel_components_blocks.search_components')"
            data-hb-filter="[data-hb-panel-cb-components]" data-hb-filter-item="[data-hb-insert-block]" />
        <x-heisenberg::ui.category-head :label="__('heisenberg::editor.panel_components_blocks.category_base')" />
        <div class="hb-panel-cb__body" data-hb-category-body>
            <div class="hb-panel-cb__scroll" data-hb-panel-cb-scroll>
                <div class="hb-panel-cb__grid">
                    @foreach ($blockCards as $card)
                        <x-heisenberg::ui.tool-card :icon="$card['icon']" :label="$card['label']"
                            :data-hb-insert-block="$card['block']" />
                    @endforeach
                </div>
            </div>
            <x-heisenberg::ui.custom-scrollbar container="[data-hb-panel-cb-scroll]" />
        </div>
    </div>

    <div class="hb-panel-cb__content" data-hb-panel-cb-blocks hidden>
        <x-heisenberg::ui.search-field :placeholder="__('heisenberg::editor.panel_components_blocks.search_blocks')"
            data-hb-filter="[data-hb-panel-cb-blocks]" data-hb-filter-item="[data-hb-saved-block]" />
        <div class="hb-panel-cb__body" data-hb-patterns-body>
            <div class="hb-panel-cb__scroll hb-panel-cb__blocks-scroll" data-hb-panel-cb-blocks-scroll>
                <div class="hb-panel-cb__grid hb-panel-cb__blocks-grid" data-hb-patterns-grid data-empty-label="{{ __('heisenberg::editor.panel_components_blocks.empty_blocks') }}">
                    @forelse ($patterns as $pattern)
                        <div class="hb-panel-cb__card" data-hb-saved-block="{{ (int) $pattern['id'] }}"
                            data-hb-pattern-name="{{ $pattern['name'] }}" title="{{ $pattern['name'] }}">
                            <x-heisenberg::ui.tool-card :icon="'stack'" :label="$pattern['name']" />
                            <button type="button" class="hb-panel-cb__card-del"
                                data-hb-pattern-delete="{{ (int) $pattern['id'] }}"
                                aria-label="{{ __('heisenberg::editor.patterns.delete') }}">@include('heisenberg::components.ui.icon', ['name' => 'x', 'size' => 14])</button>
                        </div>
                    @empty
                        <div class="hb-panel-cb__empty" data-hb-patterns-empty>{{ __('heisenberg::editor.panel_components_blocks.empty_blocks') }}</div>
                    @endforelse
                </div>
                {{-- The ONE card shape. refreshBlocksTab() clones this instead of hand-building
                     markup: the two had already drifted (its cards carried no tool-card at all,
                     and a `hb-tcard__label` class that exists nowhere), so every card lost its
                     icon and framing the moment a pattern was added or deleted. --}}
                <template data-hb-pattern-card-template>
                    <div class="hb-panel-cb__card" data-hb-saved-block="" data-hb-pattern-name="" title="">
                        <x-heisenberg::ui.tool-card :icon="'stack'" label="" />
                        <button type="button" class="hb-panel-cb__card-del" data-hb-pattern-delete=""
                            aria-label="{{ __('heisenberg::editor.patterns.delete') }}">@include('heisenberg::components.ui.icon', ['name' => 'x', 'size' => 14])</button>
                    </div>
                </template>
            </div>
            <x-heisenberg::ui.custom-scrollbar container="[data-hb-panel-cb-blocks-scroll]" />
        </div>
    </div>
</div>