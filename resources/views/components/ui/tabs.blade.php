@once

@endonce
@include('heisenberg::components.ui.partials.tablist-script')

@props(['items' => [], 'activeIndex' => 0])
<div role="tablist" data-hb-tablist {{ $attributes->merge(['class' => 'hb-tabs']) }}>
    @foreach ($items as $i => $item)
        <button
            type="button"
            role="tab"
            data-hb-tab="{{ $item['value'] ?? $i }}"
            aria-selected="{{ $i === $activeIndex ? 'true' : 'false' }}"
            class="hb-tabs__tab"
        >{{ $item['label'] ?? $item }}</button>
    @endforeach
</div>
