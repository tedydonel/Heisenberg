{{-- ui/toggle — 34x20 track, cornerRadius 999, 16px knob w/ drop shadow.
     Source only has the ON instance: accent track, knob at x=16 (2px inset from the 34-16=18 right
     edge... actually 34-16-16=2px right inset). OFF state is inferred by symmetry (knob at 2px left
     inset) and border-strong track — not shown in source, flagged here rather than presented as sourced.
     Real <input type="checkbox"> for actual on/off + change event + keyboard.
     flex: none is load-bearing next to a long wrapped label in a space-between flex row (see
     live/panel-seo-social's toggle rows) — without it, flex-shrink squeezes this below 34px
     alongside the label instead of just letting the label wrap. --}}
@once

@endonce

@props([
    'on' => false,
    'disabled' => false,
    'name' => null,
])
<label {{ $attributes->merge(['class' => 'hb-toggle' . ($disabled ? ' hb-toggle--disabled' : '')]) }}>
    <input
        type="checkbox"
        role="switch"
        class="hb-toggle__input"
        @if ($name) name="{{ $name }}" @endif
        @if ($on) checked @endif
        @if ($disabled) disabled @endif
    >
    <span class="hb-toggle__track" aria-hidden="true"></span>
    <span class="hb-toggle__knob" aria-hidden="true"></span>
</label>
