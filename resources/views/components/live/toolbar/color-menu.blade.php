{{-- live/toolbar/color-menu — the Style group's text-colour popover content (Pencil Block
     Toolbar, cPGss). The "A" trigger is specifically Text colour (see groups/style.blade.php's own
     aria-label) — this menu writes supports.color.text only; supports.color.background has no
     toolbar affordance and is left unwired (see block-toolbar.blade.php's report notes). Options
     come from config('heisenberg.tokens.color') — the same design-token registry block-runtime's
     own isSafeColorToken()/cssValueValid('color-value') already allow, and the one the inspector's
     future colour controls are documented to read from (docs/block-schema.md). The Builder's
     ThemeRepository merges a saved theme over this config for its own pages; the Editor has no such
     merge wiring, so this ships the config defaults as-is — a real palette is a follow-up, not a
     toolbar-file concern. Selecting a swatch writes through
     window.hbEditor.setSupport(id, 'color.text', value) — see block-toolbar.blade.php's
     `colorselect` listener. --}}
@php $colorTokens = (array) config('heisenberg.tokens.color', ['' => 'Default']); @endphp
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
        });
        if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot, { once: true });
        else boot();
        document.addEventListener('hb:refresh', boot);
    })();
</script>
@endonce
