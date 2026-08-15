{{-- ui/tab-item — standalone bordered chip tab (bg, border, radius-sm,
     padding 5/12, fs-xs). Distinct from the pill segments inside ui/tabs — the source defines this as
     its own bordered-chip look, not reused as-is inside the Tabs track, so it's kept as a separate atom
     per the task's rule to not force unrelated-looking things into one component. --}}
@once

@endonce

@props(['label' => 'Tab', 'active' => false])
<button type="button" role="tab" aria-selected="{{ $active ? 'true' : 'false' }}" {{ $attributes->merge(['class' => 'hb-tabitem']) }}>
    {{ $label }}
</button>
