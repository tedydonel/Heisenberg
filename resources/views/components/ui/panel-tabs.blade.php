{{-- ui/panel-tabs — from Pencil Panel Tabs (Mnu2L): 2-up flush header tabs, 32px tall. Active tab is
     transparent (merges with the content panel below) with a right divider against its neighbor;
     inactive tab is bg-muted with a full bottom border, muted text. Fixed to exactly 2 items per source.
     flex: none is load-bearing: this sits above a scrolling sibling inside a flex column (see the
     live/panel-* consumers), and without it an oversized sibling's flex-basis drags this down via
     flex-shrink along with it — measured shrinking to ~21px in the Style panel before this was added. --}}
@once
<style>
    .hb-paneltabs { display: flex; width: 100%; height: 32px; flex: none; }
    .hb-paneltabs__tab {
        flex: 1 1 0;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border: 0;
        border-right: 1px solid var(--hb-border, #E4E4E4);
        border-bottom: 1px solid var(--hb-border, #E4E4E4);
        background: var(--hb-bg-muted, #F4F4F4);
        color: var(--hb-text-muted, #9A9A9A);
        font-family: var(--hb-font-sans, Rubik, sans-serif);
        font-size: var(--hb-fs-sm, 12px);
        font-weight: 300;
        cursor: pointer;
    }
    .hb-paneltabs__tab:last-child { border-right: 0; }
    .hb-paneltabs__tab[aria-selected="true"] {
        background: transparent;
        border-bottom-color: transparent;
        color: var(--hb-text-primary, #0A0A0A);
        font-weight: 500;
    }
    .hb-paneltabs__tab:focus-visible { outline: 2px solid var(--hb-border-focus, #000); outline-offset: -2px; }
</style>
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
