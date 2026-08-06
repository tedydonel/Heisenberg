{{-- ui/status-tag — pill, dot + label, source shows only the success
     instance (success-soft bg, success dot, text-primary label). danger uses the direct token pair
     (danger-subtle/danger); neutral has no soft-bg token of its own, so it's inferred from bg-muted/
     text-muted, following the same soft-bg + dot-color pattern as the sourced success case. --}}
@once
<style>
    .hb-statustag {
        display: inline-flex;
        align-items: center;
        gap: var(--hb-space-2, 8px);
        padding: 3px 8px;
        border-radius: 999px;
        font-family: var(--hb-font-sans, Rubik, sans-serif);
        font-size: var(--hb-fs-xs, 11px);
        font-weight: 500;
        color: var(--hb-text-primary, #0A0A0A);
    }
    .hb-statustag__dot { width: 6px; height: 6px; border-radius: 999px; flex: none; }
    .hb-statustag--success { background: var(--hb-success-soft, #E0FFF0); }
    .hb-statustag--success .hb-statustag__dot { background: var(--hb-success, #3BD186); }
    .hb-statustag--danger { background: var(--hb-danger-subtle, #FEF2F2); }
    .hb-statustag--danger .hb-statustag__dot { background: var(--hb-danger, #D4191A); }
    .hb-statustag--neutral { background: var(--hb-bg-muted, #F4F4F4); }
    .hb-statustag--neutral .hb-statustag__dot { background: var(--hb-text-muted, #9A9A9A); }
</style>
@endonce

@props(['label' => '', 'status' => 'neutral'])
@php $status = in_array($status, ['success', 'danger', 'neutral'], true) ? $status : 'neutral'; @endphp
<span {{ $attributes->merge(['class' => "hb-statustag hb-statustag--{$status}"]) }}>
    <span class="hb-statustag__dot" aria-hidden="true"></span>
    <span>{{ $label !== '' ? $label : $slot }}</span>
</span>
