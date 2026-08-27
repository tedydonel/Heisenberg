@props(['mode' => 'color', 'selected' => 'border', 'tokens' => null, 'values' => []])
@php
    $default = $mode === 'number'
        ? ['None' => null, 'space-1' => '4', 'space-2' => '8', 'space-3' => '12', 'space-4' => '16', 'radius-sm' => '3', 'radius-md' => '5', 'radius-lg' => '8']
        : ['None' => null, 'accent' => '#000000', 'accent-fg' => '#FFFFFF', 'accent-hover' => '#1A1A1A', 'bg' => '#FFFFFF', 'bg-inset' => '#EEEEEE', 'bg-muted' => '#F4F4F4', 'bg-subtle' => '#FAFAFA', 'border' => '#E4E4E4'];
    $items = $tokens ?? $default;
    $placeholder = $mode === 'number' ? 'Search sizes…' : 'Search colors…';
@endphp
<div class="hb-pop hb-varmenu" data-hb-varmenu>
    <div class="hb-varmenu__search">
        <input type="search" placeholder="{{ $placeholder }}">
    </div>
    <div class="hb-varmenu__list">
        @foreach ($items as $name => $val)
            <button type="button" data-vm-name="{{ $name }}" @if (isset($values[$name])) data-vm-value="{{ $values[$name] }}" @endif class="hb-vmi{{ $name === $selected ? ' hb-vmi--on' : '' }}">
                <span class="hb-vmi__l">
                    <span class="hb-vmi__check">@include('heisenberg::components.ui.icon', ['name' => 'check', 'size' => 13])</span>
                    <span class="hb-vmi__name">{{ $name }}</span>
                </span>
                @if ($mode === 'number')
                    <span class="hb-vmi__val">{{ $val ?? '' }}</span>
                @elseif ($val)
                    <span class="hb-vmi__sw" style="background: {{ $val }};"></span>
                @else
                    <span class="hb-vmi__sw" style="background: transparent; box-shadow: none;"></span>
                @endif
            </button>
        @endforeach
    </div>
</div>
@once
<script nonce="{{ heisenberg_csp_nonce() }}">
    (() => {
        const boot = () => document.querySelectorAll('[data-hb-varmenu]').forEach((menu) => {
            if (menu.__hbVm) return; menu.__hbVm = true;
            const items = [...menu.querySelectorAll('.hb-vmi')];
            const search = menu.querySelector('input[type="search"]');
            items.forEach((it) => it.addEventListener('click', () => {
                items.forEach((i) => i.classList.remove('hb-vmi--on'));
                it.classList.add('hb-vmi--on');
                menu.dispatchEvent(new CustomEvent('varselect', {
                    bubbles: true,
                    detail: { name: it.dataset.vmName, value: it.dataset.vmValue ?? it.dataset.vmName },
                }));
            }));
            if (search) search.addEventListener('input', () => {
                const term = search.value.trim().toLowerCase();
                items.forEach((it) => { it.hidden = !(it.dataset.vmName || '').toLowerCase().includes(term); });
            });
        });
        if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot, { once: true });
        else boot();
        document.addEventListener('hb:refresh', boot);
    })();
</script>
@endonce
