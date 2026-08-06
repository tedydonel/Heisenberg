{{-- ui/badge — pill, padding 3/8, bg-inset/text-secondary. Source only shows
     the neutral instance; "accent" variant is inferred (solid accent/accent-fg, matching how the design
     treats accent elsewhere) since no accent-tinted badge token/instance exists to source from. --}}
@once
<style>
    .hb-badge {
        display: inline-flex;
        align-items: center;
        padding: 3px 8px;
        border-radius: 999px;
        font-family: var(--hb-font-sans, Rubik, sans-serif);
        font-size: var(--hb-fs-xs, 11px);
        font-weight: 500;
    }
    .hb-badge--neutral { background: var(--hb-bg-inset, #EEEEEE); color: var(--hb-text-secondary, #5A5A5A); }
    .hb-badge--accent { background: var(--hb-accent, #000); color: var(--hb-accent-fg, #fff); }
</style>
@endonce

@props(['label' => '', 'variant' => 'neutral'])
@php $variant = in_array($variant, ['neutral', 'accent'], true) ? $variant : 'neutral'; @endphp
<span {{ $attributes->merge(['class' => "hb-badge hb-badge--{$variant}"]) }}>{{ $label !== '' ? $label : $slot }}</span>
