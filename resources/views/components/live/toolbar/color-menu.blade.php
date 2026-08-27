@props(['tokens' => []])
@php
    $colorTokens = is_array($tokens) && count($tokens) > 1
        ? $tokens
        : (array) config('heisenberg.tokens.color', ['' => 'Default']);
@endphp
<div class="hb-pop hb-colormenu" data-hb-colormenu>
    <div class="hb-varmenu__list">
        @foreach ($colorTokens as $value => $label)
            <button type="button" class="hb-vmi" data-color-value="{{ $value }}">
                <span class="hb-vmi__l">
                    <span class="hb-vmi__name">{{ $label }}</span>
                </span>
                <span class="hb-vmi__sw" style="{{ $value === '' ? 'background: transparent; box-shadow: none;' : 'background: ' . $value . ';' }}"></span>
            </button>
        @endforeach
    </div>
</div>
@once
<script nonce="{{ heisenberg_csp_nonce() }}">
    (() => {
        const boot = () => document.querySelectorAll('[data-hb-colormenu]').forEach((menu) => {
            if (menu.__hbColor) return; menu.__hbColor = true;
            const options = [...menu.querySelectorAll('[data-color-value]')];
            options.forEach((btn) => btn.addEventListener('click', () => {
                options.forEach((b) => b.classList.remove('hb-vmi--on'));
                btn.classList.add('hb-vmi--on');
                menu.dispatchEvent(new CustomEvent('colorselect', { bubbles: true, detail: { value: btn.dataset.colorValue } }));
            }));

            document.addEventListener('hb:block-selected', (e) => {
                const model = e.detail && e.detail.model;
                const current = (model && model.supports && model.supports.color && model.supports.color.text) || '';
                options.forEach((btn) => btn.classList.toggle('hb-vmi--on', btn.dataset.colorValue === current));
            });
        });
        if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot, { once: true });
        else boot();
        document.addEventListener('hb:refresh', boot);
    })();
</script>
@endonce
