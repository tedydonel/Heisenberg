{{-- live/side-panel — generic middle-column panel shell shared by 4 real pairs in the source, all built
     from the identical structure (Panel Tabs header + scrollable body), just with different tab-pair
     labels and content: Components|Blocks (KExNj/GKZ0P), SEO|Social (QLO1w/CpbAX), Style|Themes
     (Q3h5D/Qcba6), Ai|Tools (v23BF/IrAUK). One component with a `tabs` prop instead of 4 near-duplicate
     files, since the source itself doesn't differentiate them structurally — each is `stroke-right: 1,
     width: 260` in isolation.

     Width here is 240, not each base component's own 260 — sourced from the one confirmed real-page
     instance (bTaeD's "Block Library", a ref of KExNj, overrides width to 240). Assumed the same for
     the other 3 pairs since they're structurally identical, not separately confirmed. Empty shell per
     instruction: body is blank, none of the real per-panel content (Colors/Radius sections, SEO fields,
     Ai suggestions, theme grids, etc.) is included — that's content, not shell. --}}
@once
<style>
    .hb-sidepanel {
        display: flex;
        flex-direction: column;
        width: 240px;
        height: 100%;
        background: var(--hb-bg, #fff);
        border-right: 1px solid var(--hb-border, #E4E4E4);
        flex: none;
    }
    .hb-sidepanel__body { flex: 1 1 auto; overflow: hidden; }
</style>
@endonce

@props(['tabs' => [], 'activeIndex' => 0])
<div {{ $attributes->merge(['class' => 'hb-sidepanel']) }}>
    <x-ui.panel-tabs :items="$tabs" :active-index="$activeIndex" />
    <div class="hb-sidepanel__body"></div>
</div>
