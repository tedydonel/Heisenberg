{{-- ui/segmented — from Pencil Segmented (qE8fW): up to 6 icon segments, same bg-inset track as
     ui/tabs (padding 2, gap 2, radius 5, height 26), active segment gets a bg pill (radius 4). --}}
@once
<style>
    .hb-segmented {
        display: flex;
        gap: 2px;
        width: 100%;
        height: 26px;
        padding: 2px;
        background: var(--hb-bg-inset, #EEEEEE);
        border-radius: 5px;
    }
    .hb-segmented__tab {
        flex: 1 1 0;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border: 0;
        border-radius: 4px;
        background: transparent;
        color: var(--hb-text-secondary, #5A5A5A);
        cursor: pointer;
        transition: background-color .12s ease, color .12s ease;
    }
    .hb-segmented__tab:hover { background: var(--hb-surface-hover, #F7F7F7); }
    .hb-segmented__tab[aria-selected="true"] { background: var(--hb-bg, #fff); color: var(--hb-text-primary, #0A0A0A); }
    .hb-segmented__icon { display: inline-flex; width: 16px; height: 16px; }
    .hb-segmented__tab:focus-visible { outline: 2px solid var(--hb-border-focus, #000); outline-offset: -2px; }
</style>
@endonce
@include('heisenberg::components.ui.partials.tablist-script')

@props(['items' => [], 'activeIndex' => 0])
<div role="tablist" data-hb-tablist {{ $attributes->merge(['class' => 'hb-segmented']) }}>
    @foreach (array_slice($items, 0, 6) as $i => $item)
        <button
            type="button"
            role="tab"
            aria-label="{{ $item['label'] ?? '' }}"
            data-hb-tab="{{ $item['value'] ?? $i }}"
            aria-selected="{{ $i === $activeIndex ? 'true' : 'false' }}"
            class="hb-segmented__tab"
        >
            <span class="hb-segmented__icon" aria-hidden="true">
                @include('heisenberg::components.ui.icon', ['name' => $item['icon'] ?? 'square', 'size' => 16])
            </span>
        </button>
    @endforeach
</div>
