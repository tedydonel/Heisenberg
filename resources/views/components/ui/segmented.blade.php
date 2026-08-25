@once

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
