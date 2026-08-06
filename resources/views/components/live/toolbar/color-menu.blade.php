{{-- live/toolbar/color-menu — the Style group's text-colour popover. Writes supports.color.text
     only (color.background has no toolbar affordance). Options are the live THEME colour tokens
     (ThemeRepository::tokens(), var(--hb-t-*) refs the page's hb-theme-vars style resolves),
     falling back to config('heisenberg.tokens.color') when no theme tokens exist.
     Selecting a swatch writes through window.hbEditor.setSupport(id, 'color.text', value) via
     block-toolbar.blade.php's `colorselect` listener. --}}
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
                    <span class="hb-vmi__check">@include('heisenberg::components.ui.icon', ['name' => 'check', 'size' => 13])</span>
                    <span class="hb-vmi__name">{{ $label }}</span>
                </span>
                <span class="hb-vmi__sw" style="{{ $value === '' ? 'background: transparent; box-shadow: none;' : 'background: ' . $value . ';' }}"></span>
            </button>
        @endforeach
    </div>
</div>
@once
<script>
    (() => {
        const boot = () => document.querySelectorAll('[data-hb-colormenu]').forEach((menu) => {
            if (menu.__hbColor) return; menu.__hbColor = true;
            const options = [...menu.querySelectorAll('[data-color-value]')];
            options.forEach((btn) => btn.addEventListener('click', () => {
                options.forEach((b) => b.classList.remove('hb-vmi--on'));
                btn.classList.add('hb-vmi--on');
                menu.dispatchEvent(new CustomEvent('colorselect', { bubbles: true, detail: { value: btn.dataset.colorValue } }));
            }));

            // Check the swatch matching the newly-selected block's current text colour.
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
