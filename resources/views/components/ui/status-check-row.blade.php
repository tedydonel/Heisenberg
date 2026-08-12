{{-- ui/status-check-row — icon + wrapping label, lineHeight 1.4.
     Source only has the "pass" instance (check-circle/success); "fail" is inferred as the standard
     paired icon (x-circle) in $danger, following the same success/danger pairing used in ui/status-tag.
     "warn" (2026-08-11, the SEO/Social panel's real checklist, docs/seo-system.md §4) is a THIRD
     status the source never showed either — inferred the same way: paired with Phosphor's
     warning-circle (only vendored in the "regular"/hollow weight — EditorIcon falls back to it
     automatically, same as any other icon this package requests in a weight that isn't vendored),
     colored with --hb-warning (2026-08-12, tokens.css §warn) so it carries real status color in
     both themes instead of a fixed hex. --}}
@once
<style>
    .hb-statuscheckrow { display: flex; align-items: center; gap: var(--hb-space-2, 8px); width: 100%; }
    .hb-statuscheckrow__icon { display: inline-flex; width: 14px; height: 14px; flex: none; }
    .hb-statuscheckrow__icon--pass { color: var(--hb-success, #3BD186); }
    .hb-statuscheckrow__icon--warn { color: var(--hb-warning, #B45309); }
    .hb-statuscheckrow__icon--fail { color: var(--hb-danger, #D4191A); }
    .hb-statuscheckrow__text {
        flex: 1 1 auto;
        min-width: 0;
        font-family: var(--hb-font-sans, Rubik, sans-serif);
        font-size: var(--hb-fs-sm, 12px);
        line-height: 1.4;
        color: var(--hb-text-secondary, #5A5A5A);
    }
</style>
@endonce

@props(['status' => 'pass', 'text' => ''])
@php
    $hbStatusCheckIcons = [
        'pass' => 'check-circle-fill',
        'warn' => 'warning-circle',
        'fail' => 'x-circle-fill',
    ];
    $status = array_key_exists($status, $hbStatusCheckIcons) ? $status : 'pass';
@endphp
<div {{ $attributes->merge(['class' => 'hb-statuscheckrow']) }}>
    <span class="hb-statuscheckrow__icon hb-statuscheckrow__icon--{{ $status }}" aria-hidden="true">
        @include('heisenberg::components.ui.icon', ['name' => $hbStatusCheckIcons[$status], 'size' => 14])
    </span>
    <span class="hb-statuscheckrow__text">{{ $text !== '' ? $text : $slot }}</span>
</div>
