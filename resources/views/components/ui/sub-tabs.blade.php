{{-- ui/sub-tabs — 3 icon-only tabs, 44px tall, underline style.
     Inactive tabs get a bottom border only; the active tab gets top+left+right border (no bottom, so
     it visually merges with the panel below) plus accent-colored, heavier-weight icon. Fixed to 3 items. --}}
@once

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
