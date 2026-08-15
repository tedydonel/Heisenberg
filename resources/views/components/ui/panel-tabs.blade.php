{{-- ui/panel-tabs — 2-up flush header tabs, 32px tall. Active tab is
     transparent (merges with the content panel below) with a right divider against its neighbor;
     inactive tab is bg-muted with a full bottom border, muted text. Fixed to exactly 2 items per source.
     flex: none is load-bearing: this sits above a scrolling sibling inside a flex column (see the
     live/panel-* consumers), and without it an oversized sibling's flex-basis drags this down via
     flex-shrink along with it — measured shrinking to ~21px in the Style panel before this was added. --}}
@once

@endonce
@include('heisenberg::components.ui.partials.tablist-script')

@props(['items' => [], 'activeIndex' => 0])
<div role="tablist" data-hb-tablist {{ $attributes->merge(['class' => 'hb-paneltabs']) }}>
    @foreach (array_slice($items, 0, 2) as $i => $item)
        <button
            type="button"
            role="tab"
            data-hb-tab="{{ $item['value'] ?? $i }}"
            aria-selected="{{ $i === $activeIndex ? 'true' : 'false' }}"
            class="hb-paneltabs__tab"
        >{{ $item['label'] ?? $item }}</button>
    @endforeach
</div>
