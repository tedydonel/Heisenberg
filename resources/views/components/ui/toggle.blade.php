{{-- ui/toggle — 34x20 track, cornerRadius 999, 16px knob w/ drop shadow.
     Source only has the ON instance: accent track, knob at x=16 (2px inset from the 34-16=18 right
     edge... actually 34-16-16=2px right inset). OFF state is inferred by symmetry (knob at 2px left
     inset) and border-strong track — not shown in source, flagged here rather than presented as sourced.
     Real <input type="checkbox"> for actual on/off + change event + keyboard.
     flex: none is load-bearing next to a long wrapped label in a space-between flex row (see
     live/panel-seo-social's toggle rows) — without it, flex-shrink squeezes this below 34px
     alongside the label instead of just letting the label wrap. --}}
@once
<style>
    .hb-toggle { position: relative; display: inline-flex; width: 34px; height: 20px; flex: none; cursor: pointer; }
    .hb-toggle--disabled { opacity: .5; cursor: not-allowed; }
    .hb-toggle__input { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); }
    .hb-toggle__track {
        position: absolute;
        inset: 0;
        border-radius: 999px;
        background: var(--hb-border-strong, #C8C8C8);
        transition: background-color .15s ease;
    }
    .hb-toggle__knob {
        position: absolute;
        top: 2px;
        left: 2px;
        width: 16px;
        height: 16px;
        border-radius: 999px;
        background: var(--hb-surface, #fff);
        box-shadow: 0 1px 2px rgba(0, 0, 0, .25);
        transition: transform .15s ease;
    }
    .hb-toggle__input:checked ~ .hb-toggle__track { background: var(--hb-accent, #000); }
    .hb-toggle__input:checked ~ .hb-toggle__knob { transform: translateX(14px); }
    .hb-toggle__input:focus-visible ~ .hb-toggle__track { outline: 2px solid var(--hb-border-focus, #000); outline-offset: 2px; }
</style>
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
