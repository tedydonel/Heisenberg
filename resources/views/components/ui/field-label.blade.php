{{-- ui/field-label — uppercase eyebrow label used above inputs. --}}
@once
<style>
    .hb-field-label {
        display: block;
        font-family: var(--hb-font-sans, Rubik, sans-serif);
        font-size: var(--hb-fs-xs, 11px);
        font-weight: 600;
        letter-spacing: .6px;
        text-transform: uppercase;
        color: var(--hb-text-secondary, #5A5A5A);
    }
</style>
@endonce

@props(['for' => null])
<label @if ($for) for="{{ $for }}" @endif {{ $attributes->merge(['class' => 'hb-field-label']) }}>{{ $slot }}</label>
