@once

<script nonce="{{ heisenberg_csp_nonce() }}">
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
