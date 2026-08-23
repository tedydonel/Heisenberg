{{-- ui/status-check-row — icon + wrapping label, lineHeight 1.4.
     Source only has the "pass" instance (check-circle/success); "fail" is inferred as the standard
     paired icon (x-circle) in $danger, following the same success/danger pairing used in ui/status-tag.
     "warn" (2026-08-11, the SEO/Social panel's real checklist, docs/seo-system.md §4) is a THIRD
     status the source never showed either — inferred the same way: paired with Phosphor's
     warning-circle (only vendored in the "regular"/hollow weight — EditorIcon falls back to it
     automatically, same as any other icon this package requests in a weight that isn't vendored),
     colored with --hb-warning (2026-08-12, tokens.css §warn) so it carries real status color in
     both themes instead of a fixed hex.
     "na" (2026-08-23, the SEO score tightening — "nothing to score against", e.g. no images on a
     text-only post) is a FOURTH status: Phosphor's minus-circle in the muted text color, so the
     row reads as "not applicable" rather than pass/fail/warn. Rendered as 'na' so the panel's
     row allow-list can hand it through verbatim; the JS allow-list in panel-seo-social mirrors
     this exact set. --}}
@once

@endonce

@props(['status' => 'pass', 'text' => ''])
@php
    $hbStatusCheckIcons = [
        'pass' => 'check-circle-fill',
        'warn' => 'warning-circle',
        'fail' => 'x-circle-fill',
        'na'   => 'minus-circle',
    ];
    $status = array_key_exists($status, $hbStatusCheckIcons) ? $status : 'pass';
@endphp
<div {{ $attributes->merge(['class' => 'hb-statuscheckrow']) }}>
    <span class="hb-statuscheckrow__icon hb-statuscheckrow__icon--{{ $status }}" aria-hidden="true">
        @include('heisenberg::components.ui.icon', ['name' => $hbStatusCheckIcons[$status], 'size' => 14])
    </span>
    <span class="hb-statuscheckrow__text">{{ $text !== '' ? $text : $slot }}</span>
</div>
