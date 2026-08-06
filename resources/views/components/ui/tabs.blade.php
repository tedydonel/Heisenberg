{{-- ui/tabs — pill segments on a bg-inset track. The source shows the
     same first segment 4 times labeled Default/Hover/Active/Focus — this is the design documenting one
     tab's look per interaction state (see the task rule on side-by-side state variants), not 4 real
     tabs. Collapsed to props: one active pill (fill bg, text accent) among transparent siblings
     (text-secondary, weight 300). Hover/active/focus renderings were identical to each other in the
     source (only the selected pill differed), so this component adds a standard hover tint + visible
     focus ring using existing tokens rather than reproducing indistinguishable states. --}}
@once
<style>
    .hb-tabs {
        display: flex;
        gap: 2px;
        width: 100%;
        height: 26px;
        padding: 2px;
        background: var(--hb-bg-inset, #EEEEEE);
        border-radius: 5px;
    }
    .hb-tabs__tab {
        flex: 1 1 0;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border: 0;
        border-radius: 4px;
        background: transparent;
        color: var(--hb-text-secondary, #5A5A5A);
        font-family: var(--hb-font-sans, Rubik, sans-serif);
        font-size: var(--hb-fs-xs, 11px);
        font-weight: 300;
        cursor: pointer;
        transition: background-color .12s ease, color .12s ease;
    }
    .hb-tabs__tab:hover { background: var(--hb-surface-hover, #F7F7F7); }
    .hb-tabs__tab[aria-selected="true"] {
        background: var(--hb-bg, #fff);
        color: var(--hb-accent, #000);
        font-weight: 400;
    }
    .hb-tabs__tab:focus-visible { outline: 2px solid var(--hb-border-focus, #000); outline-offset: -2px; }
</style>
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
