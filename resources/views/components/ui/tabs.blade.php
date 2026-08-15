{{-- ui/tabs — pill segments on a bg-inset track. The source shows the
     same first segment 4 times labeled Default/Hover/Active/Focus — this is the design documenting one
     tab's look per interaction state (see the task rule on side-by-side state variants), not 4 real
     tabs. Collapsed to props: one active pill (fill bg, text accent) among transparent siblings
     (text-secondary, weight 300). Hover/active/focus renderings were identical to each other in the
     source (only the selected pill differed), so this component adds a standard hover tint + visible
     focus ring using existing tokens rather than reproducing indistinguishable states. --}}
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
