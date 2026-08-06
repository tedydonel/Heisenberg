{{-- ui/chip — pill, asymmetric padding 4/6/4/10 (top/right/bottom/left, as
     specified in the source), bg-muted, 16px round close button with an 11px X icon. "selected" isn't
     in the source (only the default instance exists) — inferred as a solid-accent treatment, matching
     the same accent inference used for ui/badge. Real remove behavior: closing dispatches a cancelable
     'remove' event; the chip hides itself unless a listener calls preventDefault(). --}}
@once
<style>
    .hb-chip {
        display: inline-flex;
        align-items: center;
        gap: var(--hb-space-2, 8px);
        padding: 4px 6px 4px 10px;
        border-radius: 999px;
        background: var(--hb-bg-muted, #F4F4F4);
        color: var(--hb-text-secondary, #5A5A5A);
        font-family: var(--hb-font-sans, Rubik, sans-serif);
        font-size: var(--hb-fs-xs, 11px);
        font-weight: 500;
    }
    .hb-chip--selected { background: var(--hb-accent, #000); color: var(--hb-accent-fg, #fff); }
    .hb-chip--disabled { opacity: .5; }
    .hb-chip__close {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 16px;
        height: 16px;
        border: 0;
        border-radius: 999px;
        background: transparent;
        color: inherit;
        cursor: pointer;
    }
    .hb-chip__close:hover { background: rgba(0, 0, 0, .08); }
    .hb-chip__close:focus-visible { outline: 2px solid var(--hb-border-focus, #000); outline-offset: 1px; }
</style>
<script>
    (() => {
        const boot = () => {
            document.querySelectorAll('[data-hb-chip]').forEach((chip) => {
                if (chip.__hbChip) return;
                const close = chip.querySelector('[data-hb-chip-close]');
                close?.addEventListener('click', () => {
                    const event = new CustomEvent('remove', { bubbles: true, cancelable: true });
                    chip.dispatchEvent(event);
                    if (!event.defaultPrevented) chip.hidden = true;
                });
                chip.__hbChip = true;
            });
        };
        if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot, { once: true });
        else boot();
        document.addEventListener('hb:refresh', boot);
    })();
</script>
@endonce

@props(['label' => '', 'selected' => false, 'disabled' => false])
<span
    data-hb-chip
    {{ $attributes->merge(['class' => 'hb-chip' . ($selected ? ' hb-chip--selected' : '') . ($disabled ? ' hb-chip--disabled' : '')]) }}
>
    <span>{{ $label !== '' ? $label : $slot }}</span>
    <button type="button" data-hb-chip-close aria-label="Remove" class="hb-chip__close" @if ($disabled) disabled @endif>
        @include('heisenberg::components.ui.icon', ['name' => 'x', 'size' => 11])
    </button>
</span>
