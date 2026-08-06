{{-- ui/sub-tabs — 3 icon-only tabs, 44px tall, underline style.
     Inactive tabs get a bottom border only; the active tab gets top+left+right border (no bottom, so
     it visually merges with the panel below) plus accent-colored, heavier-weight icon. Fixed to 3 items. --}}
@once
<style>
    /* flex: none matches ui/panel-tabs and is what keeps the 44px honest: as a flex item in a
       clamped column (.hb-inspector__populated), the default flex-shrink: 1 lets the strip be
       squeezed whenever the panel below it is tall — so the tab row changed height depending on
       which sub-tab was active. The panel below owns the leftover space; this row never gives any. */
    .hb-subtabs { display: flex; width: 100%; height: 44px; flex: none; }
    .hb-subtabs__tab {
        flex: 1 1 0;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border: 1px solid transparent;
        border-bottom-color: var(--hb-border, #E4E4E4);
        background: transparent;
        color: var(--hb-text-muted, #9A9A9A);
        cursor: pointer;
    }
    .hb-subtabs__tab[aria-selected="true"] {
        border-color: var(--hb-border, #E4E4E4);
        border-bottom-color: transparent;
        color: var(--hb-accent, #000);
    }
    .hb-subtabs__tab[aria-selected="true"]:first-child {
        border-left-color: transparent;
    }
    .hb-subtabs__tab[aria-selected="true"]:last-child {
        border-right-color: transparent;
    }
    .hb-subtabs__icon { display: inline-flex; width: 18px; height: 18px; }
    .hb-subtabs__tab:focus-visible { outline: 2px solid var(--hb-border-focus, #000); outline-offset: -2px; }
</style>
@endonce
@include('heisenberg::components.ui.partials.tablist-script')

@props(['items' => [], 'activeIndex' => 0])
<div role="tablist" data-hb-tablist {{ $attributes->merge(['class' => 'hb-subtabs']) }}>
    @foreach (array_slice($items, 0, 3) as $i => $item)
        <button
            type="button"
            role="tab"
            aria-label="{{ $item['label'] ?? '' }}"
            data-hb-tab="{{ $item['value'] ?? $i }}"
            aria-selected="{{ $i === $activeIndex ? 'true' : 'false' }}"
            class="hb-subtabs__tab"
        >
            <span class="hb-subtabs__icon" aria-hidden="true">
                @include('heisenberg::components.ui.icon', ['name' => $item['icon'] ?? 'square', 'size' => 18])
            </span>
        </button>
    @endforeach
</div>
